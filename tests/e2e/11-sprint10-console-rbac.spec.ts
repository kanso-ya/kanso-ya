import { test, expect } from '@playwright/test';

test.describe('Sprint 10 - Console Superadmin Multi-pages, RBAC Matrix Sticky & Horaires Dynamiques', () => {

    test('US 10.1 : Navigation multi-pages Superadmin et métriques système', async ({ page }) => {
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

        // Navigation vers Branding
        await page.click('a[href="/sys-branding"]');
        await page.waitForURL('**/sys-branding');
        await expect(page.locator('#branding-form')).toBeVisible();

        // Navigation vers SMTP & Templates
        await page.click('a[href="/sys-smtp"]');
        await page.waitForURL('**/sys-smtp');
        await expect(page.locator('#smtp-settings-form')).toBeVisible();
        await expect(page.locator('#select-email-template')).toBeVisible();

        // Navigation vers Modules
        await page.click('a[href="/sys-modules"]');
        await page.waitForURL('**/sys-modules');
        await expect(page.locator('#btn-scan-modules')).toBeVisible();

        // Navigation vers Logs
        await page.click('a[href="/sys-logs"]');
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

});
