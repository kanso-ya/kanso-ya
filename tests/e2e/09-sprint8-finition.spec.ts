import { test, expect } from '@playwright/test';

test.describe('Sprint 8 : Inscriptions intelligentes, Remises paramétrables, Santé Cerfa & Finition', () => {

    test.beforeEach(async ({ page }) => {
        await page.goto('/');
    });

    test('US 8.1 - Questionnaire de santé Cerfa QS-Sport (/questionnaire-sante)', async ({ page }) => {
        await page.goto('/questionnaire-sante');
        await expect(page).toHaveTitle(/Questionnaire de Santé/);

        // 1. Bascule vers le questionnaire Majeur
        await page.click('#btn-tab-adult');
        const adultForm = page.locator('#cerfa-adult-form');
        await expect(adultForm).toBeVisible();

        // 2. Cocher une question médicale -> déclenche l'alerte médicale immédiate
        const firstCheckbox = adultForm.locator('.qs-adult-checkbox').first();
        await firstCheckbox.check();

        const warningBanner = page.locator('#health-warning-banner');
        await expect(warningBanner).toBeVisible();
        await expect(warningBanner).toContainText('Certificat médical de non-contre-indication obligatoire');

        // 3. Décocher la case -> l'auto-évaluation redevient favorable
        await firstCheckbox.uncheck();
        await expect(warningBanner).toBeHidden();
        const resultBanner = page.locator('#health-result-banner');
        await expect(resultBanner).toBeVisible();

        // 4. Cocher l'attestation sur l'honneur
        const attestationCb = page.locator('#health-attestation-checkbox');
        await attestationCb.check();

        // 5. Valider l'attestation et vérifier la redirection vers /register
        await page.click('#btn-confirm-health-attestation');
        await page.waitForURL('**/register');
    });

    test('US 8.2 - Brouillon local LocalStorage (Point 16)', async ({ page }) => {
        await page.goto('/register');

        // Remplir un nom et prénom
        await page.fill('#reg-first-name', 'Camille');
        await page.fill('#reg-last-name', 'Brouillon');
        await page.fill('#reg-birth-date', '1995-04-12');
        await page.dispatchEvent('#reg-birth-date', 'change');

        // Vérifier l'écriture dans LocalStorage
        const draftValue = await page.evaluate(() => localStorage.getItem('kanso_reg_draft'));
        expect(draftValue).not.toBeNull();
        expect(draftValue).toContain('Camille');

        // Recharger la page -> le bandeau de restauration doit apparaître
        await page.reload();
        const restoreBanner = page.locator('#draft-restore-banner');
        await expect(restoreBanner).toBeVisible();

        // Restaurer le brouillon
        await page.click('#btn-restore-draft');
        await expect(restoreBanner).toBeHidden();
        await expect(page.locator('#reg-first-name')).toHaveValue('Camille');
        await expect(page.locator('#reg-last-name')).toHaveValue('Brouillon');

        // Effacer le brouillon
        await page.evaluate(() => localStorage.setItem('kanso_reg_draft', JSON.stringify({ form: { first_name: 'Test' } })));
        await page.reload();
        await expect(page.locator('#draft-restore-banner')).toBeVisible();
        await page.click('#btn-clear-draft');
        await expect(page.locator('#draft-restore-banner')).toBeHidden();
        const clearedDraft = await page.evaluate(() => localStorage.getItem('kanso_reg_draft'));
        expect(clearedDraft).toBeNull();
    });

    test('US 8.3 - Remises dynamiques paramétrables et calcul panier familial', async ({ page }) => {
        await page.goto('/register');

        // Adhérent 1 (Adulte)
        await page.fill('#reg-first-name', 'Alexandre');
        await page.fill('#reg-last-name', 'Famille');
        await page.fill('#reg-birth-date', '1990-01-01');
        await page.dispatchEvent('#reg-birth-date', 'change');
        await page.fill('#reg-email', 'alexandre.famille@test.local');
        await page.click('#btn-next-from-identity');

        // Contact urgence avec champs enrichis (Point 14)
        await page.fill('#reg-emerg-name', 'Marie Famille');
        await page.fill('#reg-emerg-phone', '0601020304');
        await page.fill('#reg-emerg-relation', 'Conjoint');
        await page.fill('#reg-emerg-phone-secondary', '0699887766');
        await page.click('#btn-next-from-emergency');

        // Étape paiement : 1 adhérent -> pas de remise
        await expect(page.locator('#family-cart-count')).toContainText('1 adhérent');
        await expect(page.locator('#step-panel-payment #reg-total-price')).toHaveText('150.00');
        await expect(page.locator('#reg-discount-badge')).toBeHidden();

        // Ajouter un deuxième membre au panier
        await page.click('#btn-add-family-member');

        // Adhérent 2 (Adulte)
        await page.fill('#reg-first-name', 'Sophie');
        await page.fill('#reg-last-name', 'Famille');
        await page.fill('#reg-birth-date', '1992-05-10');
        await page.dispatchEvent('#reg-birth-date', 'change');
        await page.fill('#reg-email', 'sophie.famille@test.local');
        await page.click('#btn-next-from-identity');

        await page.fill('#reg-emerg-name', 'Alexandre Famille');
        await page.fill('#reg-emerg-phone', '0601020304');
        await page.click('#btn-next-from-emergency');

        // Seuil atteint (2 inscrits) -> remise 10% appliquée (300 - 30 = 270 €)
        await expect(page.locator('#family-cart-count')).toContainText('2 adhérent');
        await expect(page.locator('#reg-discount-badge')).toBeVisible();
        await expect(page.locator('#reg-discount-badge')).toContainText('Remise Famille');
        await expect(page.locator('#reg-discount-amount')).toBeVisible();
        await expect(page.locator('#reg-discount-amount')).toContainText('-30.00 €');
        await expect(page.locator('#step-panel-payment #reg-total-price')).toHaveText('270.00');
    });

    test('US 8.4 - Modale récapitulative de pré-soumission et validation complète (Point 17)', async ({ page }) => {
        await page.goto('/register');

        // Membre adulte complet
        await page.fill('#reg-first-name', 'Julien');
        await page.fill('#reg-last-name', 'Confirme');
        await page.fill('#reg-birth-date', '1985-07-20');
        await page.dispatchEvent('#reg-birth-date', 'change');
        await page.fill('#reg-email', 'julien.confirme@test.local');
        await page.click('#btn-next-from-identity');

        await page.fill('#reg-emerg-name', 'Claire Confirme');
        await page.fill('#reg-emerg-phone', '0612345678');
        await page.click('#btn-next-from-emergency');

        // Clic pour ouvrir la modale récapitulative
        await page.click('#btn-open-confirm-modal');
        const modal = page.locator('#pre-submit-modal');
        await expect(modal).toBeVisible();
        await expect(page.locator('#modal-summary-content')).toContainText('Julien Confirme');
        await expect(page.locator('#modal-summary-content')).toContainText('150.00 €');

        // Tentative de soumission sans cocher le règlement intérieur
        await page.click('#btn-confirm-registration');
        // La modale reste ouverte
        await expect(modal).toBeVisible();

        // Accepter le règlement intérieur et confirmer
        await page.check('#modal-accept-rules');
        await page.click('#btn-confirm-registration');

        // Vérifier l'écran de succès
        await expect(page.locator('#registration-success-screen')).toBeVisible();
        await expect(page.locator('#confirm-screen-amount')).toHaveText('150.00');
    });

    test('US 8.5 - Page Paramètres du Club (/settings) pour le Bureau & Modules (Point 19 & 20)', async ({ page }) => {
        // Connexion en tant que Président via switch-persona POST
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/settings');

        await expect(page).toHaveTitle(/Paramètres du Club/);

        // 1. Vérifier la présence des onglets
        await expect(page.locator('#tab-btn-club-general')).toBeVisible();
        await expect(page.locator('#tab-btn-club-modules')).toBeVisible();
        await expect(page.locator('#tab-btn-club-rollover')).toBeVisible();
        await expect(page.locator('#tab-btn-club-logs')).toBeVisible();

        // 2. Modifier la remise en montant fixe (ex: 20 €)
        const discTypeSelect = page.locator('select[name="discount_type"]');
        await discTypeSelect.selectOption('fixed');
        const discValInput = page.locator('input[name="discount_value"]');
        await discValInput.fill('20');

        await page.click('#btn-save-settings');
        // Vérifier le toast de confirmation
        await expect(page.locator('#toast-container')).toContainText('enregistrés avec succès');

        // 3. Consulter l'onglet Modules
        await page.click('#tab-btn-club-modules');
        await expect(page.locator('#tab-panel-club-modules')).toBeVisible();
        await expect(page.locator('#btn-scan-modules')).toBeVisible();

        // Restaurer la remise en % à 10 pour les tests suivants
        await page.click('#tab-btn-club-general');
        await discTypeSelect.selectOption('percent');
        await discValInput.fill('10');
        await page.click('#btn-save-settings');
    });

    test('US 8.6 - Onglets modulaires statistiques avec hook stats_tabs (Point 28)', async ({ page }) => {
        // Connexion en tant que Président via switch-persona POST
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/stats');

        const tabsBar = page.locator('#stats-tabs-bar');
        await expect(tabsBar).toBeVisible();

        // Vérifier les 3 onglets standards
        await expect(page.locator('[data-testid="stats-tab-kpis"]')).toBeVisible();
        await expect(page.locator('[data-testid="stats-tab-occupancy"]')).toBeVisible();
        await expect(page.locator('[data-testid="stats-tab-attendance"]')).toBeVisible();
    });

});
