/**
 * @file 28-sprint27-qa-mailpit.spec.ts
 * @description SPRINT 27 (Lot 7) — Tests d'envoi réel d'e-mails via Mailpit (Laragon)
 * SMTP : 127.0.0.1:1025 | API : http://127.0.0.1:8025/api/v1/messages
 * - Phase 1 : Surcharge SMTP (.env -> Mailpit) vérifiée en boîte noire UI + API Mailpit
 * - Phase 2 : Configuration manuelle 100 % UI dans /sys-smtp -> Mailpit (mail de test + flux métier réel)
 * - Isolation stricte : purge DELETE http://127.0.0.1:8025/api/v1/messages avant chaque phase/scénario.
 */

import { test, expect, type APIRequestContext, type Page } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const ENV_PATH = path.join(PROJECT_ROOT, '.env');
const MAILPIT_API = 'http://127.0.0.1:8025/api/v1';

interface MailpitMessageSummary {
    ID: string;
    Subject: string;
    To: Array<{ Address: string; Name?: string }>;
}

/**
 * Purge intégralement la boîte Mailpit avant chaque scénario/phase pour éviter tout faux positif.
 */
async function purgeMailpit(request: APIRequestContext): Promise<void> {
    const delRes = await request.delete(`${MAILPIT_API}/messages`);
    expect(delRes.status(), 'Mailpit doit être démarré sous Laragon (127.0.0.1:8025)').toBe(200);
    const listRes = await request.get(`${MAILPIT_API}/messages`);
    const listJson = await listRes.json();
    expect(Number(listJson.total ?? 0)).toBe(0);
}

/**
 * Attend qu'au moins un e-mail correspondant au destinataire arrive dans Mailpit et renvoie son contenu détaillé.
 */
async function waitForMailpitMessage(
    request: APIRequestContext,
    recipientEmail: string,
    timeoutMs: number = 10_000
): Promise<{ summary: MailpitMessageSummary; html: string; text: string }> {
    const start = Date.now();
    const targetLower = recipientEmail.toLowerCase();

    while (Date.now() - start < timeoutMs) {
        const res = await request.get(`${MAILPIT_API}/messages`);
        if (res.status() === 200) {
            const json = await res.json();
            const messages: MailpitMessageSummary[] = Array.isArray(json.messages) ? json.messages : [];
            const match = messages.find((m) =>
                Array.isArray(m.To) && m.To.some((t) => String(t.Address || '').toLowerCase() === targetLower)
            );
            if (match) {
                const detailRes = await request.get(`${MAILPIT_API}/message/${match.ID}`);
                expect(detailRes.status()).toBe(200);
                const detail = await detailRes.json();
                return {
                    summary: match,
                    html: String(detail.HTML ?? ''),
                    text: String(detail.Text ?? ''),
                };
            }
        }
        await new Promise((r) => setTimeout(r, 300));
    }

    throw new Error(`Aucun e-mail reçu dans Mailpit pour ${recipientEmail} après ${timeoutMs}ms.`);
}

/**
 * Connexion 100 % UI Superadmin via /sys-admin-xyz.
 */
async function uiSuperadminLogin(page: Page): Promise<void> {
    await page.goto('/sys-admin-xyz');
    await expect(page.locator('#sys-login-form')).toBeVisible();
    await page.locator('#sys-login-form input[name="username"]').fill('superadmin');
    await page.locator('#sys-login-form input[name="password"]').fill('password123');
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }),
        page.locator('#btn-sys-submit').click(),
    ]);
    await page.waitForLoadState('networkidle');
}

/**
 * Déconnexion 100 % UI.
 */
async function uiLogout(page: Page): Promise<void> {
    const mobileBtn = page.locator('#mobile-menu-btn');
    if (await mobileBtn.isVisible()) {
        const cls = (await page.locator('#sidebar').getAttribute('class')) ?? '';
        if (cls.includes('-translate-x-full')) {
            await mobileBtn.click();
            await page.waitForTimeout(250);
        }
    }
    const logoutBtn = page.locator('#btn-logout');
    if (await logoutBtn.isVisible()) {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }),
            logoutBtn.click(),
        ]);
        await page.waitForLoadState('networkidle');
    }
}

test.describe.serial('Sprint 27 (Lot 7) — Tests E-mails Réels via Mailpit (Surcharge .env + Config 100 % UI)', () => {
    test('S27-04 : Mailpit Phase 1 — Surcharge SMTP (.env -> 127.0.0.1:1025) vérifiée via UI & API Mailpit', async ({ page, request }) => {
        await purgeMailpit(request);

        const hadEnv = fs.existsSync(ENV_PATH);
        const originalEnv = hadEnv ? fs.readFileSync(ENV_PATH, 'utf-8') : '';

        try {
            // 1. Surcharge SMTP niveau serveur (.env) pointant vers Mailpit 127.0.0.1:1025
            const cleanedLines = originalEnv
                .split(/\r?\n/)
                .filter((line) => !/^\s*SMTP_(TRANSPORT|HOST|PORT|ENCRYPTION|USERNAME|PASSWORD)\s*=/.test(line));
            cleanedLines.push(
                'SMTP_TRANSPORT=smtp',
                'SMTP_HOST=127.0.0.1',
                'SMTP_PORT=1025',
                'SMTP_ENCRYPTION=none'
            );
            fs.writeFileSync(ENV_PATH, cleanedLines.join('\n') + '\n', 'utf-8');

            // 2. Déclenchement 100 % UI d'un e-mail réel (Mot de passe oublié sur /login)
            await page.goto('/login');
            await page.getByTestId('link-forgot-password').click();
            await expect(page.getByTestId('forgot-password-form')).toBeVisible();
            await page.getByTestId('forgot-email-input').fill('tutorA@mail.com');

            const [resetRes] = await Promise.all([
                page.waitForResponse((r) => r.url().includes('/api/v1/auth/forgot-password') && r.request().method() === 'POST'),
                page.getByTestId('btn-send-reset-code').click(),
            ]);
            expect((await resetRes.json()).code).toBe(200);

            // 3. Vérification effective dans Mailpit API
            const msg = await waitForMailpitMessage(request, 'tutorA@mail.com');
            expect(msg.summary.Subject.length).toBeGreaterThan(3);
            expect(msg.html.length + msg.text.length).toBeGreaterThan(20);
        } finally {
            // Restauration intégrale du fichier .env d'origine
            if (hadEnv) {
                fs.writeFileSync(ENV_PATH, originalEnv, 'utf-8');
            } else if (fs.existsSync(ENV_PATH)) {
                fs.unlinkSync(ENV_PATH);
            }
        }
    });

    test('S27-05 : Mailpit Phase 2 — Configuration SMTP manuelle 100 % UI (/sys-smtp) + mail de test & flux métier', async ({ page, request }) => {
        await purgeMailpit(request);

        // 1. Connexion Superadmin 100 % UI via /sys-admin-xyz et paramétrage manuel Mailpit dans /sys-smtp
        await uiSuperadminLogin(page);
        await page.goto('/sys-smtp');
        await page.waitForLoadState('networkidle');

        await page.getByTestId('smtp-transport-select').selectOption('smtp');
        await page.locator('#smtp-host').fill('127.0.0.1');
        await page.locator('#smtp-port').fill('1025');
        await page.locator('#smtp-encryption').selectOption('none');
        await page.locator('#smtp-username').fill('');

        const [saveRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/settings') && ['PATCH', 'PUT', 'POST'].includes(r.request().method())),
            page.getByTestId('btn-save-smtp').click(),
        ]);
        expect((await saveRes.json()).code).toBe(200);

        // 2. Envoi du mail de test depuis l'UI /sys-smtp et vérification dans Mailpit
        await page.getByTestId('test-smtp-recipient').fill('qa-mailpit-ui@kanso-ya.fr');
        const [testMailRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/sys/test-email') && r.request().method() === 'POST'),
            page.getByTestId('btn-send-test-email').click(),
        ]);
        expect((await testMailRes.json()).code).toBe(200);

        const diagMsg = await waitForMailpitMessage(request, 'qa-mailpit-ui@kanso-ya.fr');
        expect(diagMsg.summary.Subject.toLowerCase()).toContain('smtp');
        expect(diagMsg.html).toContain('Kanso-ya');

        // 3. Purge Mailpit + Flux métier réel 100 % UI (Mot de passe oublié)
        await purgeMailpit(request);
        await uiLogout(page);

        await page.goto('/login');
        await page.getByTestId('link-forgot-password').click();
        await page.getByTestId('forgot-email-input').fill('president@kanso-ya.local');
        const [bizRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/auth/forgot-password') && r.request().method() === 'POST'),
            page.getByTestId('btn-send-reset-code').click(),
        ]);
        expect((await bizRes.json()).code).toBe(200);

        const bizMsg = await waitForMailpitMessage(request, 'president@kanso-ya.local');
        expect(bizMsg.summary.Subject.length).toBeGreaterThan(3);
        expect(bizMsg.html).toContain('Kanso-ya');
        expect(bizMsg.html).toMatch(/<strong>[A-F0-9]{6,}<\/strong>|href="https?:\/\//i);

        // 4. Remise propre du transport en mode 'log' via /sys-smtp UI
        await uiSuperadminLogin(page);
        await page.goto('/sys-smtp');
        await page.waitForLoadState('networkidle');
        await page.getByTestId('smtp-transport-select').selectOption('log');
        const [restoreRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/settings') && ['PATCH', 'PUT', 'POST'].includes(r.request().method())),
            page.getByTestId('btn-save-smtp').click(),
        ]);
        expect((await restoreRes.json()).code).toBe(200);
        await uiLogout(page);
    });
});
