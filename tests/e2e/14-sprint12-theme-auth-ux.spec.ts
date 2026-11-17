import { test, expect } from '@playwright/test';
import { execSync } from 'child_process';

function hexToRgbString(hex: string): string {
    const clean = hex.replace('#', '').trim();
    const r = parseInt(clean.substring(0, 2), 16);
    const g = parseInt(clean.substring(2, 4), 16);
    const b = parseInt(clean.substring(4, 6), 16);
    return `rgb(${r}, ${g}, ${b})`;
}

test.describe('SPRINT 12 — Thème CSS Réel, Logo, Mot de Passe Oublié, 2FA Multi-Choix & Langage Humain', () => {

    test('S12-01 : Thème Dynamique CSS Réel (--brand-*) & Encadré Logo adaptatif', async ({ page, request }, testInfo) => {
        const isDesktop = testInfo.project.name === 'chromium';
        let originalPrimary = '#059669';
        let originalAccent = '#10b981';

        if (isDesktop) {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
            const originalRes = await request.get('/api/v1/settings');
            const originalSettings = (await originalRes.json()).data || {};
            originalPrimary = originalSettings.theme_primary_color || '#059669';
            originalAccent = originalSettings.theme_accent_color || '#10b981';
        }

        try {
            if (isDesktop) {
                const patchRes = await request.patch('/api/v1/settings', {
                    data: {
                        theme_primary_color: '#dc2626',
                        theme_accent_color: '#f87171'
                    }
                });
                expect((await patchRes.json()).status).toBe('success');
            }

            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
            await page.goto('/');

            const themeStyle = page.locator('#kanso-dynamic-theme');
            await expect(themeStyle).toHaveCount(1);

            const brandPrimaryVar = await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim()
            );
            expect(brandPrimaryVar).toMatch(/^#[0-9a-fA-F]{6}$/);
            if (isDesktop) {
                expect(brandPrimaryVar.toLowerCase()).toBe('#dc2626');
            }
            const expectedRgb = hexToRgbString(brandPrimaryVar);

            // Vérifier que le bouton de connexion dans la sidebar adopte réellement --brand-primary
            const sidebarLogin = page.locator('#btn-sidebar-login');
            const bgColor = await sidebarLogin.evaluate((el) => getComputedStyle(el).backgroundColor);
            expect(bgColor).toBe(expectedRgb);

            // Vérifier sur /register que le bouton primaire adopte aussi --brand-primary
            await page.goto('/register');
            const nextBtn = page.locator('#btn-next-from-identity');
            const regPrimaryVar = await page.evaluate(() =>
                getComputedStyle(document.documentElement).getPropertyValue('--brand-primary').trim()
            );
            const nextBg = await nextBtn.evaluate((el) => getComputedStyle(el).backgroundColor);
            expect(nextBg).toBe(hexToRgbString(regPrimaryVar));

            // Vérifier les propriétés arrondies/rognage de #overlapping-logo-badge
            const logoBadge = page.locator('#overlapping-logo-badge');
            await expect(logoBadge).toHaveClass(/rounded-2xl/);
            await expect(logoBadge).toHaveClass(/overflow-hidden/);
        } finally {
            if (isDesktop) {
                await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
                await request.patch('/api/v1/settings', {
                    data: {
                        theme_primary_color: originalPrimary,
                        theme_accent_color: originalAccent
                    }
                });
            }
        }
    });

    test('S12-02 : Page Login sans sigle KY, Toast validation & Parcours complet Mot de passe oublié + Double saisie', async ({ page, request }, testInfo) => {
        const targetEmail = testInfo.project.name === 'chromium'
            ? 'pierre.moreau@mail.com'
            : 'celine.moreau@mail.com';

        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
        await page.goto('/login');

        // 1. Aucun sigle "KY" affiché sur la carte de connexion
        const loginCardText = await page.locator('#form-login-card').innerText();
        expect(loginCardText).not.toMatch(/\bKY\b/);

        // 2. Validation formulaire vide -> Toast d'erreur explicite obligatoire
        await page.click('#btn-login-submit');
        await expect(page.locator('[data-testid="toast-error"]')).toBeVisible();

        // 3. Parcours "Mot de passe oublié ?" — Anti-énumération API
        const fakeEmailRes = await request.post('/api/v1/auth/forgot-password', {
            data: { email: 'inconnu.total.99999@kanso-ya.invalid' }
        });
        const fakeBody = await fakeEmailRes.json();
        expect(fakeBody.status).toBe('success');
        expect(fakeBody.code).toBe(200);

        const realEmailRes = await request.post('/api/v1/auth/forgot-password', {
            data: { email: targetEmail }
        });
        const realBody = await realEmailRes.json();
        expect(realBody.status).toBe('success');
        expect(realBody.code).toBe(200);
        // Message strictement identique (anti-énumération)
        expect(realBody.message).toBe(fakeBody.message);

        // Vérifier que l'UI redirige vers /activate-otp?mode=reset
        await page.click('[data-testid="link-forgot-password"]');
        await expect(page.locator('[data-testid="forgot-password-form"]')).toBeVisible();

        // Email invalide -> Toast d'erreur
        await page.fill('[data-testid="forgot-email-input"]', 'pas-un-email');
        await page.click('[data-testid="btn-send-reset-code"]');
        await expect(page.locator('[data-testid="toast-error"]').last()).toBeVisible();

        // Email valide -> redirection vers /activate-otp?mode=reset
        await page.fill('[data-testid="forgot-email-input"]', targetEmail);
        await page.click('[data-testid="btn-send-reset-code"]');
        await page.waitForURL(/\/activate-otp\?mode=reset/, { timeout: 10000 });

        // Récupérer le code généré en BDD (storage/db/kanso-ya.sqlite)
        const otpValue = execSync(
            `php -r "$db = new PDO('sqlite:storage/db/kanso-ya.sqlite'); $s = $db->prepare('SELECT otp_code FROM users WHERE email = :e'); $s->execute(['e' => '${targetEmail}']); echo $s->fetchColumn();"`
        ).toString().trim();
        expect(otpValue.length).toBeGreaterThan(4);

        // Vérifier la trace dans email_logs côté Superadmin
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const logsRes = await request.get('/api/v1/sys/email-logs');
        const logsData = (await logsRes.json()).data || [];
        expect(logsData.some((l: any) => l.recipient_email === targetEmail && l.template_type === 'PASSWORD_RESET')).toBe(true);

        // Saisie divergente sur /activate-otp -> Toast d'erreur bloquant
        await page.fill('[data-testid="reset-otp-input"]', otpValue);
        await page.fill('[data-testid="reset-new-password"]', 'NouveauMdp2026!');
        await page.fill('[data-testid="reset-confirm-password"]', 'AutreMdp2026!');
        await page.click('[data-testid="btn-confirm-reset-password"]');
        await expect(page.locator('[data-testid="toast-error"]').last()).toContainText(/correspondent pas/i);

        // Saisie concordante -> Succès
        await page.fill('[data-testid="reset-confirm-password"]', 'NouveauMdp2026!');
        await page.click('[data-testid="btn-confirm-reset-password"]');
        await expect(page.locator('[data-testid="toast-success"]').last()).toContainText(/succès/i);
    });

    test('S12-03 : Profil — Modale Confirmation Double Saisie Mot de Passe & 2FA 3 Options (QR, Clé, Email)', async ({ page }, testInfo) => {
        const persona = testInfo.project.name === 'chromium' ? 'president' : 'secretaire';
        const userId = testInfo.project.name === 'chromium' ? 1 : 5;

        await page.request.post('/api/v1/auth/switch-persona', { data: { persona } });
        await page.goto('/dashboard');

        // Ouvrir l'onglet Sécurité
        await page.click('#tab-btn-profile-security');
        await expect(page.locator('#tab-panel-profile-security')).toBeVisible();

        // 1. Tester les 3 options de 2FA
        await expect(page.locator('[data-testid="2fa-method-totp"]')).toBeVisible();
        await expect(page.locator('[data-testid="2fa-method-manual"]')).toBeVisible();
        await expect(page.locator('[data-testid="2fa-method-email"]')).toBeVisible();

        // Option Clé secrète manuelle
        await page.click('[data-testid="2fa-method-manual"]');
        await expect(page.locator('[data-testid="btn-copy-totp-secret"]')).toBeVisible();

        // Option Code reçu par e-mail
        await page.click('[data-testid="2fa-method-email"]');
        await expect(page.locator('[data-testid="btn-send-2fa-email-code"]')).toBeVisible();

        // Envoi du code par e-mail via POST /api/v1/users/me/2fa/send-email-code
        await page.click('[data-testid="btn-send-2fa-email-code"]');
        await expect(page.locator('[data-testid="toast-success"]').last()).toContainText(/6 chiffres/i);

        // Récupérer le code à 6 chiffres généré pour ce compte et activer la 2FA Email
        const emailCode = execSync(
            `php -r "$db = new PDO('sqlite:storage/db/kanso-ya.sqlite'); echo $db->query('SELECT otp_code FROM users WHERE id = ${userId}')->fetchColumn();"`
        ).toString().trim();
        expect(emailCode).toMatch(/^\d{6}$/);

        await page.fill('#totp-code-input', emailCode);
        await page.click('#btn-enable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText(/2FA Active/i);

        // Désactiver proprement la 2FA pour ne pas polluer les autres tests
        await page.click('#btn-disable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText('Désactivée');

        // 2. Tester la modale dédiée de confirmation de changement de mot de passe
        await page.fill('#pwd-current', 'password123');
        await page.fill('#pwd-new', 'SuperClub2027!');
        await page.click('#btn-submit-password');

        const pwdModal = page.locator('[data-testid="password-confirm-modal"]');
        await expect(pwdModal).toBeVisible();

        // Vérifier que le bouton dit "Confirmer la modification" et JAMAIS "Confirmer la suppression"
        const confirmBtn = page.locator('[data-testid="btn-confirm-password-change"]');
        await expect(confirmBtn).toHaveText(/Confirmer la modification/i);
        await expect(pwdModal).not.toContainText(/Confirmer la suppression/i);

        // Saisie discordante dans la modale -> Toast d'erreur
        await page.fill('[data-testid="modal-confirm-new-password"]', 'Different2027!');
        await confirmBtn.click();
        await expect(page.locator('[data-testid="toast-error"]').last()).toContainText(/correspondent pas/i);

        // Fermeture modale
        await page.click('#btn-cancel-password-modal');
        await expect(pwdModal).toBeHidden();
    });

    test('S12-04 : Langage Humain Non-Technique & Toasts Systématiques sur Formulaires', async ({ page }) => {
        // 1. Vérifier /roles en Président : aucune clé technique brute (members.view, finances.manage, RBAC)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/roles');
        await expect(page.locator('#rbac-matrix-table')).toBeVisible();
        await expect(page.locator('#rbac-matrix-tbody tr')).toHaveCount(8);

        const rolesText = await page.locator('#main-workspace').innerText();
        expect(rolesText).not.toContain('members.view');
        expect(rolesText).not.toContain('finances.manage');
        expect(rolesText).not.toContain('articles.publish');
        expect(rolesText).not.toMatch(/\bRBAC\b/);

        // Soumission formulaire ajout rôle vide -> Toast erreur
        await page.click('#btn-add-role-col');
        await expect(page.locator('[data-testid="toast-error"]').last()).toBeVisible();

        // 2. Vérifier /settings : aucun app_logs ni is_category_forced visible
        await page.goto('/settings');
        const settingsText = await page.locator('#main-workspace').innerText();
        expect(settingsText).not.toContain('app_logs');
        expect(settingsText).not.toContain('is_category_forced');

        // 3. Vérifier /cms : soumission d'un article sans titre -> Toast d'erreur explicite
        await page.goto('/cms');
        await page.fill('#art-title', '');
        await page.click('#btn-save-article');
        await expect(page.locator('[data-testid="toast-error"]').last()).toContainText(/titre/i);

        // 4. Vérifier /sys-logs en Superadmin : aucun sys_logs / app_logs brut dans les titres
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-logs');
        const sysLogsText = await page.locator('#main-workspace').innerText();
        expect(sysLogsText).not.toContain('sys_logs');
        expect(sysLogsText).not.toContain('app_logs');
    });
});
