import { test, expect } from '@playwright/test';

test.describe('Sprint 10 - Console Superadmin Multi-pages, RBAC Matrix Sticky & Horaires Dynamiques', () => {

    test('US 10.1 : Navigation multi-pages Superadmin et métriques système', async ({ page, isMobile }) => {
        const navigateSidebar = async (href: string): Promise<void> => {
            if (isMobile) {
                const mobileBtn = page.locator('#mobile-menu-btn');
                if (await mobileBtn.isVisible()) {
                    await mobileBtn.click();
                }
            }
            await page.locator(`#sidebar-nav a[href="${href}"]`).click();
        };

        // Authentification Superadmin via hook de test
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-console');

        // Vérification des métriques système
        const metricUsers = page.locator('#metric-users-count');
        await expect(metricUsers).toBeAttached();

        const metricMembers = page.locator('#metric-members-count');
        await expect(metricMembers).toBeAttached();

        const metricDbSize = page.locator('#metric-db-size');
        await expect(metricDbSize).toBeAttached();

        // Navigation vers Branding via la Sidebar gauche unique (Sprint 14 - Point 18)
        await navigateSidebar('/sys-branding');
        await page.waitForURL('**/sys-branding');
        await expect(page.locator('#branding-form')).toBeVisible();

        // Navigation vers SMTP & Templates via la Sidebar
        await navigateSidebar('/sys-smtp');
        await page.waitForURL('**/sys-smtp');
        await expect(page.locator('#smtp-settings-form')).toBeVisible();
        await page.locator('[data-testid="smtp-tab-templates"]').click();
        await expect(page.locator('#select-email-template')).toBeVisible();

        // Navigation vers Modules via la Sidebar
        await navigateSidebar('/sys-modules');
        await page.waitForURL('**/sys-modules');
        await expect(page.locator('#btn-scan-modules')).toBeVisible();

        // Navigation vers Logs via la Sidebar
        await navigateSidebar('/sys-logs');
        await page.waitForURL('**/sys-logs');
        await expect(page.locator('#sys-logs-tbody')).toBeVisible();
    });

    test('US 10.2 : Sauvegarde BDD SQLite en 1 clic', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-console');

        const btnBackup = page.locator('#btn-backup-db');
        await expect(btnBackup).toBeVisible();
        await btnBackup.click();

        // Vérification du toast de confirmation
        const toast = page.locator('#toast-container');
        await expect(toast).toContainText('Sauvegarde SQLite réussie');
    });

    test('US 10.3 : Éditeur de modèles d\'emails transactionnels', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-smtp');

        // Ouverture de l'onglet Modèles d'Emails (Sprint 14 - Point 20)
        await page.locator('[data-testid="smtp-tab-templates"]').click();

        const select = page.locator('#select-email-template');
        await expect(select).toBeVisible();

        // Sélection d'un modèle (ex: otp_invitation)
        await select.selectOption('otp_invitation');

        const inputSubject = page.locator('#tpl-subject');
        await expect(inputSubject).toHaveValue(/Activez|Activation|espace personnel/i);

        // Modification du sujet
        const updatedSubject = `Activation de votre compte adhérent - ${Date.now()}`;
        await inputSubject.fill(updatedSubject);

        // Soumission
        await page.click('#btn-save-email-template');

        // Vérification du toast
        const toast = page.locator('#toast-container');
        await expect(toast).toContainText('mis à jour avec succès');

        // Rechargement pour vérifier la persistance
        await page.reload();
        await page.locator('[data-testid="smtp-tab-templates"]').click();
        await select.selectOption('otp_invitation');
        await expect(inputSubject).toHaveValue(updatedSubject);
    });

    test('US 10.4 : Matrice RBAC - Première colonne sticky et toggles', async ({ page }) => {
        // Connexion en tant que Président
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/roles');

        // Vérification du sticky sur le header et tbody
        const stickyHeader = page.locator('th.sticky');
        await expect(stickyHeader).toBeVisible();
        await expect(stickyHeader).toContainText('Domaine & Permission');

        // Vérification des toggles
        const toggleInput = page.locator('input[data-matrix-role]').first();
        await expect(toggleInput).toBeAttached();

        // Rôle système désactivé
        const systemInput = page.locator('input[data-matrix-role="1"]').first();
        await expect(systemInput).toBeDisabled();
    });

    test('US 10.5 : Page publique dynamique /horaires avec tarifs et créneaux', async ({ page }) => {
        // En visiteur non connecté
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
        await page.goto('/horaires');

        // Vérification de la présence des disciplines et tarifs
        await expect(page.locator('h1')).toContainText('Horaires des Cours');
        
        // Au moins une discipline affichée
        const disciplineCards = page.locator('#schedules-grid > div');
        await expect(disciplineCards.first()).toBeVisible();
        await expect(page.locator('body')).toContainText('places disponibles', { ignoreCase: true });
    });

    test('US 10.6 : SEO, OpenGraph et accessibilité modale Escape', async ({ page }) => {
        await page.goto('/');

        // Meta tags SEO & OpenGraph
        const metaDesc = page.locator('meta[name="description"]');
        await expect(metaDesc).toHaveAttribute('content', /Kanso-ya/);

        const ogTitle = page.locator('meta[property="og:title"]');
        await expect(ogTitle).toHaveAttribute('content', /Kanso-ya/);

        // Test fermeture Escape sur modale de radiation adhérents
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/members');

        const deleteBtn = page.locator('.btn-delete-member').first();
        await expect(deleteBtn).toBeVisible();
        await deleteBtn.click();

        const modal = page.locator('#confirm-modal');
        await expect(modal).toBeVisible();
        await expect(modal).toHaveAttribute('role', 'dialog');
        await expect(modal).toHaveAttribute('aria-modal', 'true');

        // Appui sur Escape
        await page.keyboard.press('Escape');
        await expect(modal).toBeHidden();
    });

    test('US 10.7 : Isolation Superadmin (interdiction /cms) et gestion des identifiants (POST superadmins & PATCH password)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

        // 1. Sidebar Superadmin ne contient pas de lien vers /cms
        await page.goto('/sys-console');
        const cmsLink = page.locator('#sidebar-nav a[href="/cms"]');
        await expect(cmsLink).toHaveCount(0);

        // 2. Accès direct à /cms doit renvoyer 403 Forbidden
        const cmsRes = await page.goto('/cms');
        expect(cmsRes?.status()).toBe(403);
        await expect(page.locator('body')).toContainText('Accès refusé');

        // 3. Création d'un Superadmin secondaire via API & UI
        const ts = Date.now();
        const createRes = await request.post('/api/v1/sys/superadmins', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                username: `sysadmin_${ts}`,
                email: `admin_${ts}@kanso-ya.local`,
                password: 'Password123!'
            }
        });
        expect(createRes.status()).toBe(200);
        const createJson = await createRes.json();
        expect(createJson.status).toBe('success');

        // 4. Mise à jour du mot de passe Superadmin (sur le compte créé pour isolation parallèle)
        const patchPassRes = await request.patch('/api/v1/sys/password', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                superadmin_id: createJson.data.id,
                current_password: 'Password123!',
                new_password: 'NewSuperPassword123!'
            }
        });
        expect(patchPassRes.status()).toBe(200);
        const patchPassJson = await patchPassRes.json();
        expect(patchPassJson.status).toBe('success');

        // 5. Rejet si mauvais mot de passe actuel ou complexité insuffisante
        const invalidPassRes = await request.patch('/api/v1/sys/password', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                superadmin_id: createJson.data.id,
                current_password: 'wrongpassword',
                new_password: 'Simple'
            }
        });
        expect(invalidPassRes.status()).toBe(200);
        const invalidPassJson = await invalidPassRes.json();
        expect(invalidPassJson.status).toBe('error');
    });

    test('US 10.8 : Module Scan Toast et Éditeur Pages Institutionnelles sur /settings', async ({ page, request }) => {
        // Authentification Superadmin
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-modules');

        // Clic sur Scanner les modules
        await page.click('#btn-scan-modules');
        const toast = page.locator('#toast-container');
        await expect(toast).toContainText(/Scan terminé : \d+ module\(s\) détecté\(s\)/i);
        await expect(page.locator('#modules-tbody')).toBeVisible();

        // Bascule sur Président pour tester /settings
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/settings');

        // Vérification de la capacité théorique
        const capInput = page.locator('#setting-default-capacity');
        await expect(capInput).toBeVisible();

        // Onglet 5 : Pages Pied de Page
        const tabPagesBtn = page.locator('#tab-btn-club-pages');
        await expect(tabPagesBtn).toBeVisible();
        await tabPagesBtn.click();

        await expect(page.locator('#tab-panel-club-pages')).toBeVisible();
        const selectPage = page.locator('#select-institutional-page');
        await expect(selectPage).toBeVisible();

        // Sélection de 'horaires'
        await selectPage.selectOption('horaires');
        const pageTitleInput = page.locator('#page-title-input');
        await expect(pageTitleInput).not.toHaveValue('');

        // Sauvegarde de la page
        await page.click('#btn-save-institutional-page');
        await expect(toast).toContainText('enregistrée avec succès');
    });

});
