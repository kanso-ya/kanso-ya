/**
 * @file 01-auth-rbac.spec.ts
 * @description Suite E2E & API : Domaine 1 - Authentification, Shadow Login, Sécurité, Profil 2FA & Matrice RBAC.
 */

import { test, expect } from '@playwright/test';

test.describe('DOMAINE 1 — Authentification, Shadow Login, Sécurité, Profil 2FA & Matrice RBAC', () => {

    test.beforeEach(async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('UI-01 : Respect du Layout 2026 (Logo à cheval Header/Sidebar, Nom du site centré, Accueil public isolé)', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/');

        const header = page.locator('#app-header');
        const sidebar = page.locator('#sidebar');
        const main = page.locator('#main-workspace');
        const overlappingLogo = page.locator('#overlapping-logo-badge');
        const centeredHeading = page.locator('#club-name-heading');

        await expect(header).toBeVisible();
        await expect(sidebar).toBeAttached();
        await expect(main).toBeVisible();
        await expect(overlappingLogo).toBeVisible();
        await expect(centeredHeading).toBeVisible();

        // L'accueil public ne doit jamais exposer le tableau back-office des membres ni de lien direct Superadmin
        await expect(page.locator('#members-table')).toHaveCount(0);
        await expect(page.locator('#sidebar a[href*="sys-admin"]')).toHaveCount(0);
    });

    test('UI-02 : Le bouton hamburger mobile ouvre et ferme le tiroir latéral', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');

        const sidebar = page.locator('#sidebar');
        const btn = page.locator('#mobile-menu-btn');

        await expect(sidebar).toHaveClass(/.*-translate-x-full.*/);
        await btn.click();
        await expect(sidebar).not.toHaveClass(/.*-translate-x-full.*/);
    });

    test('TC-SYS-01 : Le Superadmin configure le Branding/Couleurs et teste l\'envoi SMTP avec traçabilité dans email_logs et sys_logs', async ({ request }) => {
        const settingsRes = await request.patch('/api/v1/settings', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                site_name: 'Kanso-ya - Clubs et Associations',
                theme_primary_color: '#059669',
                theme_sidebar_color: '#0f172a',
                theme_header_color: '#ffffff',
                smtp_host: 'smtp.kanso-ya.fr',
                smtp_port: '587'
            }
        });
        const settingsBody = await settingsRes.json();
        expect(settingsBody.status).toBe('success');
        expect(settingsBody.code).toBe(200);

        const testEmail = `audit.smtp.${Date.now()}@kanso-ya.fr`;
        const sendRes = await request.post('/api/v1/sys/test-email', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                recipient_email: testEmail,
                subject: 'Vérification Automate QA SMTP',
                template_type: 'SMTP_DIAGNOSTIC'
            }
        });
        expect((await sendRes.json()).status).toBe('success');

        const emailLogsRes = await request.get('/api/v1/sys/email-logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const emailLogsBody = await emailLogsRes.json();
        expect(emailLogsBody.status).toBe('success');
        expect(emailLogsBody.data.some((m: { recipient_email: string }) => m.recipient_email === testEmail)).toBe(true);

        const sysLogsRes = await request.get('/api/v1/sys/logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const sysLogsBody = await sysLogsRes.json();
        expect(sysLogsBody.data.some((l: { action: string }) => l.action === 'SYS_SETTINGS_UPDATED')).toBe(true);
    });

    test('TC-SEC-01 : Étanchéité du Shadow Login (Superadmin rejeté sur la route standard /login)', async ({ request }) => {
        const response = await request.post('/api/v1/auth/login', {
            data: { email: 'superadmin@kanso-ya.local', password: 'password123' }
        });
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(401);
        expect(body.message.toLowerCase()).toContain('identifiants');
    });

    test('TC-SEC-02-REV : Étanchéité inverse (Utilisateur classique rejeté sur l\'API de Shadow Login)', async ({ request }) => {
        const response = await request.post('/api/v1/sys/login', {
            data: { username: 'president@kanso-ya.local', password: 'password123' }
        });
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(401);
    });

    test('TC-AUTH-P02 : Connexion Superadmin réussie via URL furtive (/sys-admin-xyz)', async ({ page, request }) => {
        try {
            await page.goto('/sys-admin-xyz');
            await expect(page.locator('h1')).toContainText('Authentification Système');

            await page.fill('input[name="username"]', 'superadmin');
            await page.fill('input[name="password"]', 'password123');
            await page.click('#btn-sys-submit');

            const toast = page.locator('#toast-container');
            await expect(toast).toContainText('Accès technique autorisé');
        } finally {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        }
    });

    test('TC-AUTH-N02 : Protection contre l\'injection SQL dans le formulaire de connexion UI', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', "' OR '1'='1");
        await page.fill('input[name="password"]', 'password123');
        await page.click('button[type="submit"]');

        const toast = page.locator('#toast-container');
        await expect(toast).toContainText('invalide');
    });

    test('TC-AUTH-N03 : Un utilisateur radié (is_active = 0) est bloqué à la connexion (403)', async ({ request }) => {
        const response = await request.post('/api/v1/auth/login', {
            data: { email: 'radie_user@mail.com', password: 'password123' }
        });
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(403);
        expect(body.message).toContain('désactivé');
    });

    test('TC-PROF-01 : Page "Mon profil" à 2 onglets, champs dynamiques et 2FA TOTP', async ({ page, request }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/dashboard.php');
        await expect(page.locator('h1')).toContainText('Mon profil');

        // Onglet 1 : Ajout d'un champ personnalisé dynamique et sauvegarde
        await page.click('#btn-add-custom-field');
        const lastCfLabel = page.locator('.cf-label').last();
        const lastCfValue = page.locator('.cf-value').last();
        await lastCfLabel.fill('Grade Fédéral');
        await lastCfValue.fill('Ceinture Noire 2e Dan');
        await page.click('#btn-save-profile');
        await expect(page.locator('#toast-container')).toContainText('Profil mis à jour');

        // Bascule sur l'Onglet 2 : Sécurité & 2FA
        await page.click('#tab-btn-profile-security');
        await expect(page.locator('#tab-panel-profile-security')).toBeVisible();

        // Tentative d'activation 2FA avec un code invalide -> rejet 422
        const bad2fa = await request.post('/api/v1/users/me/2fa', {
            data: { enabled: true, totp_code: 'abc' }
        });
        expect((await bad2fa.json()).code).toBe(422);

        // Activation 2FA avec un code TOTP à 6 chiffres valide -> 200 OK
        await page.fill('#totp-code-input', '123456');
        await page.click('#btn-enable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText('2FA Active');

        // Désactivation 2FA pour remise à l'état initial
        await page.click('#btn-disable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText('Désactivée');
    });

    test('TC-AUTH-P03 & P04 : Vérification de l\'ancien mot de passe et Déconnexion propre', async ({ request }) => {
        // Mauvais mot de passe actuel -> Rejet 422
        const badPwdRes = await request.patch('/api/v1/users/me/password', {
            data: {
                current_password: 'WrongPassword2026!',
                new_password: 'NewStrongPassword2026!'
            }
        });
        const badBody = await badPwdRes.json();
        expect(badBody.status).toBe('error');
        expect(badBody.code).toBe(422);

        // Déconnexion propre
        const logoutRes = await request.post('/api/v1/auth/logout');
        const logoutBody = await logoutRes.json();
        expect(logoutBody.status).toBe('success');
        expect(logoutBody.code).toBe(200);
    });

    test('REQ-SEC-RBAC-03 : Un Rédacteur sans droit members.view est bloqué (403) sur GET /api/v1/members', async ({ request }) => {
        const response = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-redacteur-token' }
        });
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(403);
    });

    test('US 2.1 (CA3) : La Sidebar masque dynamiquement les liens non autorisés selon le rôle', async ({ page }) => {
        try {
            await page.goto('/');
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'redacteur' })
                });
            });

            await page.goto('/');
            await expect(page.locator('#sidebar a[href*="members"]')).toHaveCount(0);
            await expect(page.locator('#sidebar a[href*="roles"]')).toHaveCount(0);
            await expect(page.locator('#sidebar a[href*="registrations"]')).toHaveCount(0);
            await expect(page.locator('#sidebar a[href*="cms"]')).toBeVisible();
        } finally {
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'president' })
                });
            });
        }
    });

    test('TC-RBAC-04 : Tableau Unique Multi-Colonnes — Ajout d\'un rôle, renommage, mise à jour des droits et suppression', async ({ page, request }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/roles.php');
        await expect(page.locator('#rbac-matrix-table')).toBeVisible();

        const customRoleName = `Bénévole-${Date.now()}`;
        await page.fill('#new-role-name', customRoleName);
        await page.click('#btn-add-role-col');
        await expect(page.locator('#toast-container')).toContainText('ajoutée à la matrice');

        const rolesRes = await request.get('/api/v1/roles');
        const rolesData = (await rolesRes.json()).data;
        const createdRole = rolesData.find((r: { name: string }) => r.name === customRoleName);
        expect(createdRole).toBeDefined();

        const renamedRole = `${customRoleName}-Modifié`;
        const patchRes = await request.patch(`/api/v1/roles/${createdRole.id}`, {
            data: {
                name: renamedRole,
                permissions: ['members.view', 'articles.write']
            }
        });
        const patchBody = await patchRes.json();
        expect(patchBody.status).toBe('success');
        expect(patchBody.data.name).toBe(renamedRole);

        // Suppression refusée sur rôle système (id 1 = Président)
        const delSysRes = await request.delete('/api/v1/roles/1');
        expect((await delSysRes.json()).code).toBe(403);

        // Suppression réussie du rôle créé
        let delCustomRes = await request.delete(`/api/v1/roles/${createdRole.id}`);
        let delCustomBody = await delCustomRes.json();
        if (delCustomBody.code === 500) {
            await new Promise((r) => setTimeout(r, 600));
            delCustomRes = await request.delete(`/api/v1/roles/${createdRole.id}`);
            delCustomBody = await delCustomRes.json();
        }
        expect(delCustomBody.code).toBe(200);
    });

    test('TC-RBAC-REJECT-403 : Tentatives d\'actions non autorisées par différents rôles', async ({ request }) => {
        // 1. Un Entraîneur tentant de créer un rôle
        const roleCreateRes = await request.post('/api/v1/roles', {
            headers: { 'Authorization': 'Bearer stub-entraineur-token' },
            data: { name: 'HackerRole', permissions: ['system.admin'] }
        });
        expect((await roleCreateRes.json()).code).toBe(403);

        // 2. Un Adhérent tentant de consulter les logs système
        const sysLogsRes = await request.get('/api/v1/sys/logs', {
            headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
        });
        expect((await sysLogsRes.json()).code).toBe(403);

        // 3. Un Visiteur anonyme tentant de modifier la configuration
        const settingsRes = await request.patch('/api/v1/settings', {
            headers: { 'Authorization': 'Bearer stub-visitor-token' },
            data: { settings: { site_name: 'Pwned' } }
        });
        expect((await settingsRes.json()).code).toBe(403);
    });
});
