import { test, expect } from '@playwright/test';
import { execSync } from 'node:child_process';
import net from 'node:net';

/**
 * Démarre un mini serveur SMTP TCP local sur 127.0.0.1 (port éphémère)
 * pour valider le protocole SMTP réel (EHLO, AUTH LOGIN, MAIL FROM, RCPT TO, DATA, QUIT).
 */
async function startLocalSmtpServer(): Promise<{
    port: number;
    transcript: string[];
    receivedData: string[];
    close: () => Promise<void>;
}> {
    const transcript: string[] = [];
    const receivedData: string[] = [];

    const server = net.createServer((socket) => {
        let state: 'CMD' | 'AUTH_USER' | 'AUTH_PASS' | 'DATA' = 'CMD';
        let buffer = '';
        let dataAccum = '';

        socket.write('220 localhost Kanso-ya Test SMTP Ready\r\n');

        socket.on('data', (chunk) => {
            buffer += chunk.toString('utf8');

            while (true) {
                if (state === 'DATA') {
                    const endIdx = buffer.indexOf('\r\n.\r\n');
                    if (endIdx === -1) {
                        return;
                    }
                    dataAccum += buffer.slice(0, endIdx);
                    buffer = buffer.slice(endIdx + 5);
                    receivedData.push(dataAccum);
                    dataAccum = '';
                    state = 'CMD';
                    socket.write('250 2.0.0 OK Real-SMTP-Delivered\r\n');
                    continue;
                }

                const lineEnd = buffer.indexOf('\r\n');
                if (lineEnd === -1) {
                    return;
                }
                const line = buffer.slice(0, lineEnd);
                buffer = buffer.slice(lineEnd + 2);
                transcript.push(line);

                if (state === 'AUTH_USER') {
                    state = 'AUTH_PASS';
                    socket.write('334 UGFzc3dvcmQ6\r\n');
                    continue;
                }
                if (state === 'AUTH_PASS') {
                    state = 'CMD';
                    socket.write('235 2.7.0 Authentication successful\r\n');
                    continue;
                }

                const upper = line.toUpperCase();
                if (upper.startsWith('EHLO')) {
                    socket.write('250-localhost\r\n250-AUTH LOGIN PLAIN\r\n250 OK\r\n');
                } else if (upper.startsWith('HELO')) {
                    socket.write('250 localhost\r\n');
                } else if (upper === 'AUTH LOGIN') {
                    state = 'AUTH_USER';
                    socket.write('334 VXNlcm5hbWU6\r\n');
                } else if (upper.startsWith('MAIL FROM:')) {
                    socket.write('250 2.1.0 Originator OK\r\n');
                } else if (upper.startsWith('RCPT TO:')) {
                    socket.write('250 2.1.5 Recipient OK\r\n');
                } else if (upper === 'DATA') {
                    state = 'DATA';
                    socket.write('354 Start mail input\r\n');
                } else if (upper === 'QUIT') {
                    socket.write('221 2.0.0 Bye\r\n');
                    socket.end();
                } else {
                    socket.write('250 OK\r\n');
                }
            }
        });
    });

    await new Promise<void>((resolve) => {
        server.listen(0, '127.0.0.1', () => resolve());
    });

    const address = server.address();
    const port = typeof address === 'object' && address ? address.port : 0;

    return {
        port,
        transcript,
        receivedData,
        close: () => new Promise<void>((resolve) => server.close(() => resolve())),
    };
}

test.describe.serial('Sprint 20 (Lot 4) — Transport SMTP Réel, Chiffrement Secret & Relance Fiable', () => {
    const restoreDefaultSmtp = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.patch('/api/v1/settings', {
            data: {
                smtp_transport: 'log',
                smtp_host: 'smtp.kanso-ya.fr',
                smtp_port: '587',
                smtp_encryption: 'tls',
                smtp_timeout: '10',
                smtp_username: 'notifications@kanso-ya.fr',
                smtp_password_clear: '1',
                smtp_from_email: 'ne-pas-repondre@kanso-ya.fr',
                smtp_from_name: 'Secrétariat Kanso-ya',
            },
        });
    };

    test.beforeEach(async ({ request }) => {
        await restoreDefaultSmtp(request);
    });

    test.afterAll(async ({ request }) => {
        await restoreDefaultSmtp(request);
    });

    test('S20-01 : Persona 1 — Configuration sécurisée /sys-smtp, chiffrement AES-256-GCM, masquage API & validations 422', async ({ page, request }) => {
        // Connexion Superadmin
        const authRes = await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        expect(authRes.status()).toBe(200);

        await page.goto('/sys-smtp');
        await expect(page.getByTestId('smtp-transport-select')).toBeVisible();
        await expect(page.getByTestId('smtp-password-status')).toContainText('Non configuré');

        // Saisie d'un mot de passe SMTP et sauvegarde
        await page.getByTestId('smtp-transport-select').selectOption('log');
        await page.getByTestId('smtp-timeout-input').fill('12');
        await page.getByTestId('smtp-password-input').fill('MonSecretSmtp#2026');
        await page.getByTestId('btn-save-smtp').click();

        await expect(page.getByTestId('smtp-password-status')).toContainText('Configuré (Base chiffrée)');
        await expect(page.getByTestId('smtp-password-input')).toHaveValue('');

        // Rechargement de page : le secret reste configuré mais jamais exposé dans le DOM ni l'API
        await page.reload();
        await expect(page.getByTestId('smtp-password-status')).toContainText('Configuré (Base chiffrée)');
        await expect(page.getByTestId('smtp-password-input')).toHaveValue('');
        const html = await page.content();
        expect(html).not.toContain('MonSecretSmtp#2026');

        const settingsRes = await page.request.get('/api/v1/settings');
        const settingsJson = await settingsRes.json();
        expect(settingsJson.code).toBe(200);
        expect(settingsJson.data.smtp_password).toBeUndefined();
        expect(settingsJson.data.smtp_password_configured).toBe('1');
        expect(settingsJson.data.smtp_password_source).toBe('db');
        expect(settingsJson.data.smtp_timeout).toBe('12');

        // Vérification directe en SQLite : la valeur est chiffrée (enc:v1:) et jamais en clair
        const rawDbValue = execSync(
            `php -r "require 'vendor/autoload.php'; echo \\Core\\Api\\Database\\Database::getInstance()->query(\\\"SELECT setting_value FROM sys_settings WHERE setting_key='smtp_password'\\\")->fetchColumn();"`,
            { encoding: 'utf8' }
        ).trim();
        expect(rawDbValue.startsWith('enc:v1:')).toBe(true);
        expect(rawDbValue).not.toContain('MonSecretSmtp#2026');

        // Sauvegarde sans ressaisir le mot de passe : le secret est préservé
        await page.getByTestId('smtp-timeout-input').fill('15');
        await page.getByTestId('btn-save-smtp').click();
        await expect(page.getByTestId('smtp-password-status')).toContainText('Configuré (Base chiffrée)');

        // Effacement explicite via smtp-password-clear
        await page.getByTestId('smtp-password-clear').check();
        await page.getByTestId('btn-save-smtp').click();
        await expect(page.getByTestId('smtp-password-status')).toContainText('Non configuré');

        // Validations API 422 sur paramètres invalides
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const badPort = await (await request.patch('/api/v1/settings', { data: { smtp_port: '99999' } })).json();
        expect(badPort.code).toBe(422);
        const badTimeout = await (await request.patch('/api/v1/settings', { data: { smtp_timeout: '99' } })).json();
        expect(badTimeout.code).toBe(422);
        const badTransport = await (await request.patch('/api/v1/settings', { data: { smtp_transport: 'carrier_pigeon' } })).json();
        expect(badTransport.code).toBe(422);
    });

    test('S20-02 : Transport SMTP réel TCP sur serveur local (EHLO, AUTH LOGIN déchiffré, DATA MIME UTF-8)', async ({ request }) => {
        const smtpServer = await startLocalSmtpServer();
        try {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

            const saveRes = await request.patch('/api/v1/settings', {
                data: {
                    smtp_transport: 'smtp',
                    smtp_host: '127.0.0.1',
                    smtp_port: String(smtpServer.port),
                    smtp_encryption: 'none',
                    smtp_timeout: '5',
                    smtp_username: 'dojo-mailer',
                    smtp_password: 'RealPassword#42',
                    smtp_from_email: 'ne-pas-repondre@kanso-ya.fr',
                    smtp_from_name: 'Secrétariat Kanso-ya',
                },
            });
            expect((await saveRes.json()).code).toBe(200);

            const sendRes = await request.post('/api/v1/sys/test-email', {
                data: {
                    recipient_email: 'adherent.test@kanso-ya.fr',
                    subject: 'Test SMTP Réel Éàç',
                    template_type: 'SMTP_DIAGNOSTIC',
                    body_html: '<p>Bonjour depuis le client SMTP natif PHP !</p>',
                },
            });
            expect(sendRes.status()).toBe(200);
            const sendJson = await sendRes.json();
            expect(sendJson.status).toBe('success');
            expect(sendJson.code).toBe(200);
            expect(sendJson.data.status).toBe('sent');
            expect(sendJson.data.smtp_response).toContain('Real-SMTP-Delivered');

            // Vérifie que le dialogue SMTP réel a eu lieu avec les identifiants déchiffrés
            expect(smtpServer.transcript.some((l) => l.toUpperCase().startsWith('EHLO'))).toBe(true);
            expect(smtpServer.transcript).toContain('AUTH LOGIN');
            expect(smtpServer.transcript).toContain(Buffer.from('dojo-mailer').toString('base64'));
            expect(smtpServer.transcript).toContain(Buffer.from('RealPassword#42').toString('base64'));
            expect(smtpServer.transcript).toContain('RCPT TO:<adherent.test@kanso-ya.fr>');
            expect(smtpServer.receivedData.length).toBe(1);
            expect(smtpServer.receivedData[0]).toContain('Content-Type: text/html; charset=UTF-8');
        } finally {
            await smtpServer.close();
        }
    });

    test('S20-03 : Garde sécurité APP_ENV=testing bloquant tout hôte SMTP externe en mode smtp', async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

        await request.patch('/api/v1/settings', {
            data: {
                smtp_transport: 'smtp',
                smtp_host: 'smtp.external-domain.invalid',
                smtp_port: '587',
                smtp_encryption: 'tls',
            },
        });

        const sendRes = await request.post('/api/v1/sys/test-email', {
            data: {
                recipient_email: 'blocked@kanso-ya.fr',
                subject: 'Ne doit jamais sortir sur Internet',
            },
        });
        expect(sendRes.status()).toBe(200);
        const sendJson = await sendRes.json();
        expect(sendJson.status).toBe('error');
        expect(sendJson.code).toBe(502);
        expect(sendJson.data.status).toBe('failed');
        expect(sendJson.data.smtp_response).toContain('550 5.7.1 Blocked external SMTP host in testing environment');
    });

    test('S20-04 : Persona 2 — Échec SMTP tracé en rouge, relance UI réussie & contrôles 403/404/409', async ({ page, request }, testInfo) => {
        const uniqueRecipient = `retry.${testInfo.project.name.replace(/\s+/g, '').toLowerCase()}.${Date.now()}@kanso-ya.fr`;
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

        // 1. Configure un port local fermé pour provoquer un échec réseau réel maîtrisé
        await page.goto('/sys-smtp');
        await page.getByTestId('smtp-transport-select').selectOption('smtp');
        await page.locator('#smtp-host').fill('127.0.0.1');
        await page.locator('#smtp-port').fill('1');
        await page.locator('#smtp-encryption').selectOption('none');
        await page.getByTestId('smtp-timeout-input').fill('2');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/settings') && r.request().method() === 'PATCH'),
            page.getByTestId('btn-save-smtp').click(),
        ]);

        // 2. Déclenche un e-mail de test qui échoue et attend la fin de l'appel SMTP + rechargement logs
        await page.getByTestId('test-smtp-recipient').fill(uniqueRecipient);
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/sys/test-email') && r.request().method() === 'POST'),
            page.getByTestId('btn-send-test-email').click(),
        ]);

        // 3. Ouvre l'onglet Historique et vérifie le badge Échec + bouton Relancer
        await page.getByTestId('smtp-tab-history').click();
        await expect(page.getByTestId('smtp-panel-history')).toBeVisible();

        const logsRes = await page.request.get('/api/v1/sys/email-logs');
        const logsJson = await logsRes.json();
        const failedLog = logsJson.data.find((l: { recipient_email: string; status: string }) =>
            l.recipient_email === uniqueRecipient && l.status === 'failed'
        );
        expect(failedLog).toBeTruthy();

        const logId: number = failedLog.id;
        await expect(page.getByTestId(`email-log-status-${logId}`)).toContainText('Échec');
        await expect(page.getByTestId(`btn-retry-email-${logId}`)).toBeVisible();

        // 4. Corrige la configuration (bascule en mode log) puis relance depuis l'UI
        await page.getByTestId('smtp-tab-config').click();
        await page.getByTestId('smtp-transport-select').selectOption('log');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/settings') && r.request().method() === 'PATCH'),
            page.getByTestId('btn-save-smtp').click(),
        ]);

        await page.getByTestId('smtp-tab-history').click();
        await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/api/v1/sys/email-logs/${logId}/retry`) && r.request().method() === 'POST'),
            page.getByTestId(`btn-retry-email-${logId}`).click(),
        ]);

        await expect(page.getByTestId(`email-log-status-${logId}`)).toContainText('Envoyé');
        await expect(page.getByTestId(`email-log-retries-${logId}`)).toContainText('Relances : 1');
        await expect(page.getByTestId(`btn-retry-email-${logId}`)).toHaveCount(0);

        // 5. Contrôles API 409 (déjà envoyé), 404 (introuvable) et 403 (non Superadmin)
        const retryAgain = await (await page.request.post(`/api/v1/sys/email-logs/${logId}/retry`)).json();
        expect(retryAgain.code).toBe(409);

        const retryNotFound = await (await page.request.post('/api/v1/sys/email-logs/999999/retry')).json();
        expect(retryNotFound.code).toBe(404);

        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const retryForbidden = await (await request.post(`/api/v1/sys/email-logs/${logId}/retry`)).json();
        expect(retryForbidden.code).toBe(403);
    });
});
