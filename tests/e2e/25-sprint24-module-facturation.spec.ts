import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';

const PROJECT_ROOT = process.cwd();
const PUBLIC_INVOICE_ASSETS = path.join(PROJECT_ROOT, 'public', 'assets', 'modules', 'facturation');
const PRIVATE_INVOICES_DIR = path.join(PROJECT_ROOT, 'storage', 'private_uploads', 'invoices');

async function startLocalSmtpCaptureServer(): Promise<{
    port: number;
    receivedData: string[];
    close: () => Promise<void>;
}> {
    const receivedData: string[] = [];
    const server = net.createServer((socket) => {
        let inData = false;
        let buffer = '';
        let dataAccum = '';
        socket.write('220 localhost Facturation SMTP Ready\r\n');

        socket.on('data', (chunk) => {
            buffer += chunk.toString('utf8');
            while (true) {
                if (inData) {
                    const endIdx = buffer.indexOf('\r\n.\r\n');
                    if (endIdx === -1) return;
                    dataAccum += buffer.slice(0, endIdx);
                    buffer = buffer.slice(endIdx + 5);
                    receivedData.push(dataAccum);
                    dataAccum = '';
                    inData = false;
                    socket.write('250 2.0.0 OK Invoice-PDF-Delivered\r\n');
                    continue;
                }
                const lineEnd = buffer.indexOf('\r\n');
                if (lineEnd === -1) return;
                const line = buffer.slice(0, lineEnd);
                buffer = buffer.slice(lineEnd + 2);
                const upper = line.toUpperCase();
                if (upper.startsWith('EHLO')) {
                    socket.write('250-localhost\r\n250 OK\r\n');
                } else if (upper === 'DATA') {
                    inData = true;
                    socket.write('354 Start mail input\r\n');
                } else if (upper === 'QUIT') {
                    socket.write('221 Bye\r\n');
                    socket.end();
                } else {
                    socket.write('250 OK\r\n');
                }
            }
        });
    });

    await new Promise<void>((resolve) => server.listen(0, '127.0.0.1', () => resolve()));
    const addr = server.address();
    return {
        port: typeof addr === 'object' && addr ? addr.port : 0,
        receivedData,
        close: () => new Promise<void>((resolve) => server.close(() => resolve())),
    };
}

test.describe.serial('Sprint 24 (Lot 6) — Module Facturation PDF Officielle & Envoi Automatique', () => {
    const activateFacturation = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/scan');
        const toggleRes = await request.patch('/api/v1/sys/modules/FacturationApi/toggle', {
            data: { enabled: true },
        });
        expect(toggleRes.status()).toBe(200);
        expect(Boolean((await toggleRes.json()).data.is_active)).toBe(true);
    };

    const restoreLogSmtp = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.patch('/api/v1/settings', {
            data: {
                smtp_transport: 'log',
                smtp_host: 'smtp.kanso-ya.fr',
                smtp_port: '587',
                smtp_encryption: 'tls',
                smtp_username: 'notifications@kanso-ya.fr',
            },
        });
    };

    const uninstallFacturation = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await restoreLogSmtp(request);
        await request.post('/api/v1/sys/modules/FacturationApi/uninstall');
    };

    test.afterAll(async ({ request }) => {
        await uninstallFacturation(request);
    });

    test('S24-01 : Persona 1 — Templates PDF & E-mail, émission UI /factures, PDF binaire %PDF-1.4 hors webroot & trace email_logs', async ({ page, request }) => {
        await activateFacturation(request);

        expect(fs.existsSync(path.join(PUBLIC_INVOICE_ASSETS, 'facturation.css'))).toBe(true);
        expect(fs.existsSync(path.join(PUBLIC_INVOICE_ASSETS, 'facturation.js'))).toBe(true);

        // 1. Président personnalise le template PDF et le template e-mail dédié
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/factures');
        await expect(page.getByTestId('facturation-tab-templates')).toBeVisible();
        await page.getByTestId('facturation-tab-templates').click();

        await page.getByTestId('pdf-tpl-legal-id').fill('RNA W790098765');
        await page.getByTestId('pdf-tpl-footer').fill('Mention officielle Kanso-ya 2026');
        await page.getByTestId('email-tpl-subject').fill('[OFFICIEL] Facture {{invoice_number}} - {{club_name}}');

        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/facturation/templates') && r.request().method() === 'PUT'),
            page.getByTestId('btn-save-invoice-templates').click(),
        ]);

        // 2. Émission d'une facture manuelle (source boutique) depuis l'UI
        await page.getByTestId('facturation-tab-list').click();
        await page.getByTestId('invoice-recipient-name').fill('Marie Facturation');
        await page.getByTestId('invoice-recipient-email').fill('marie.facture@kanso-ya.fr');
        await page.getByTestId('invoice-source-type').selectOption('boutique');
        await page.getByTestId('invoice-line-label').fill('Kimono Club Brode');
        await page.getByTestId('invoice-line-amount').fill('85.00');

        const [issueRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/facturation/invoices') && r.request().method() === 'POST'),
            page.getByTestId('btn-issue-invoice').click(),
        ]);
        const issueJson = await issueRes.json();
        expect(issueJson.code).toBe(201);
        const invoiceId: number = issueJson.data.invoice.id;
        const invoiceNumber: string = issueJson.data.invoice.invoice_number;
        const pdfFilename: string = issueJson.data.invoice.pdf_filename;

        await expect(page.getByTestId(`btn-download-invoice-${invoiceId}`)).toBeVisible();
        expect(fs.existsSync(path.join(PRIVATE_INVOICES_DIR, pdfFilename))).toBe(true);

        // 3. Vérification du PDF binaire retourné par GET .../pdf
        const pdfRes = await page.request.get(`/api/v1/modules/facturation/invoices/${invoiceId}/pdf`);
        const pdfJson = await pdfRes.json();
        expect(pdfJson.code).toBe(200);
        expect(pdfJson.data.mime_type).toBe('application/pdf');

        const pdfContent = Buffer.from(pdfJson.data.pdf_base64, 'base64').toString('latin1');
        expect(pdfContent.startsWith('%PDF-1.4')).toBe(true);
        expect(pdfContent).toContain('%%EOF');
        expect(pdfContent).toContain(invoiceNumber);
        expect(pdfContent).toContain('RNA W790098765');
        expect(pdfContent).toContain('Mention officielle Kanso-ya 2026');
        expect(pdfContent).toContain('Marie Facturation');
        expect(pdfContent).toContain('85,00 EUR');

        // 4. Vérification de la trace d'envoi automatique avec pièce jointe dans email_logs
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const logsJson = await (await request.get('/api/v1/sys/email-logs')).json();
        const mailLog = logsJson.data.find((l: { recipient_email: string; template_type: string }) =>
            l.recipient_email === 'marie.facture@kanso-ya.fr' && l.template_type === 'INVOICE_ISSUED'
        );
        expect(mailLog).toBeTruthy();
        expect(mailLog.subject).toContain(`[OFFICIEL] Facture ${invoiceNumber}`);
        expect(mailLog.smtp_response).toContain(`[PJ: ${pdfFilename}]`);
    });

    test('S24-02 : Envoi réel SMTP TCP avec pièce jointe MIME multipart/mixed + application/pdf', async ({ request }) => {
        await activateFacturation(request);
        const smtp = await startLocalSmtpCaptureServer();
        try {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
            await request.patch('/api/v1/settings', {
                data: {
                    smtp_transport: 'smtp',
                    smtp_host: '127.0.0.1',
                    smtp_port: String(smtp.port),
                    smtp_encryption: 'none',
                    smtp_username: '',
                    smtp_timeout: '5',
                },
            });

            const issueRes = await request.post('/api/v1/modules/facturation/invoices', {
                data: {
                    source_type: 'cotisation',
                    recipient_name: 'Jean Cotisation',
                    recipient_email: 'jean.cotisation@kanso-ya.fr',
                    items: [{ label: 'Cotisation Annuelle Judo', qty: 1, unit_price: 150 }],
                    send_email: true,
                },
            });
            const issueJson = await issueRes.json();
            expect(issueJson.code).toBe(201);
            expect(issueJson.data.email_status).toBe('sent');
            expect(smtp.receivedData.length).toBe(1);
            expect(smtp.receivedData[0]).toContain('Content-Type: multipart/mixed;');
            expect(smtp.receivedData[0]).toContain('Content-Type: application/pdf;');
            expect(smtp.receivedData[0]).toContain('Content-Disposition: attachment; filename="FAC-');
        } finally {
            await smtp.close();
            await restoreLogSmtp(request);
        }
    });

    test('S24-03 : Persona 2 — Cloisonnement RBAC/RGPD (403 Adhérent sur facture tierce & templates) + Désinstallation propre (404)', async ({ page, request }) => {
        await activateFacturation(request);

        // Crée une facture appartenant à un tiers
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const otherInv = await (await request.post('/api/v1/modules/facturation/invoices', {
            data: {
                source_type: 'autre',
                recipient_name: 'Tiers Prive',
                recipient_email: 'tiers.prive@kanso-ya.fr',
                items: [{ label: 'Prestation privee', qty: 1, unit_price: 40 }],
                send_email: false,
            },
        })).json();
        const otherId: number = otherInv.data.invoice.id;

        // Adhérent : pas d'accès admin ni aux factures tierces
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        await page.goto('/factures');
        await expect(page.getByTestId('facturation-create-form')).toBeHidden();
        await expect(page.getByTestId('facturation-tab-templates')).toBeHidden();

        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        expect((await (await request.get(`/api/v1/modules/facturation/invoices/${otherId}/pdf`)).json()).code).toBe(403);
        expect((await (await request.put('/api/v1/modules/facturation/templates', { data: {} })).json()).code).toBe(403);
        expect((await (await request.post('/api/v1/modules/facturation/invoices', { data: {} })).json()).code).toBe(403);

        // Désinstallation propre
        await uninstallFacturation(request);
        expect(fs.existsSync(PUBLIC_INVOICE_ASSETS)).toBe(false);
        expect((await request.get('/factures')).status()).toBe(404);
        expect((await (await request.get('/api/v1/modules/facturation/invoices')).json()).code).toBe(404);
    });
});
