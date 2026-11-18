import { test, expect } from '@playwright/test';

test.describe('SPRINT 13 — Rollover Harmonisé, Inscription Intelligente (?discipline=ID), Réinscription Complète & Aperçu Texte Confirmation', () => {

    test('S13-01 : Basculement de Saison (/settings + alias API /api/v1/rollover sans 404)', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });

        // 1. Vérifier que l'alias POST /api/v1/rollover fonctionne sans 404 (JSend HTTP 200)
        const aliasRes = await page.request.post('/api/v1/rollover', {
            data: { target_season: '2026-2027', reference_date: '2026-09-01', archive_previous: false }
        });
        const aliasBody = await aliasRes.json();
        expect(aliasBody.status).toBe('success');
        expect(aliasBody.code).toBe(200);
        expect(aliasBody.data.target_season).toBe('2026-2027');

        // 2. Déclenchement UI depuis /settings onglet Basculement de Saison
        await page.goto('/settings');
        await page.click('#tab-btn-club-rollover');
        await expect(page.locator('#tab-panel-club-rollover')).toBeVisible();

        await page.click('#btn-open-rollover-modal');
        await expect(page.locator('#rollover-confirm-modal')).toBeVisible();

        await page.fill('#rollover-target-season', '2026-2027');
        await page.fill('#rollover-reference-date', '2026-09-01');
        await page.click('#btn-confirm-rollover');

        const toastSuccess = page.locator('[data-testid="toast-success"]').last();
        await expect(toastSuccess).toBeVisible({ timeout: 10000 });
        await expect(toastSuccess).toContainText(/Basculement vers 2026-2027 réussi/i);
        await expect(toastSuccess).not.toContainText('undefined');
    });

    test('S13-02 : /register sans discipline pré-cochée par défaut & pré-sélection via /horaires (?discipline=ID)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });

        // 1. Ouverture directe de /register -> aucune discipline pré-sélectionnée
        await page.goto('/register');
        await page.evaluate(() => localStorage.removeItem('kanso_reg_draft'));
        await page.reload();

        const firstDiscSelect = page.locator('.prac-discipline').first();
        await expect(firstDiscSelect).toBeVisible();
        await expect(firstDiscSelect).toHaveValue('');
        await expect(firstDiscSelect.locator('option:checked')).toContainText(/Sélectionnez une discipline/i);

        // Tentative de passer à l'étape suivante sans discipline choisie -> Toast d'erreur bloquant
        await page.fill('#reg-first-name', 'Nina');
        await page.fill('#reg-last-name', 'Test');
        await page.fill('#reg-birth-date', '1995-03-10');
        await page.dispatchEvent('#reg-birth-date', 'change');
        await page.fill('#reg-email', 'nina.test@mail.com');
        await page.click('#btn-next-from-identity');
        await expect(page.locator('[data-testid="toast-error"]').last()).toContainText(/discipline/i);
        await expect(page.locator('#step-panel-identity')).toBeVisible();

        // 2. Navigation depuis /horaires : clic sur "S'inscrire à cette discipline" (Karaté, id=2)
        await page.evaluate(() => localStorage.removeItem('kanso_reg_draft'));
        await page.goto('/horaires');

        const karateBtn = page.locator('[data-testid="horaires-register-discipline-btn"][data-discipline-id="2"]');
        await expect(karateBtn).toBeVisible();
        await expect(karateBtn).toHaveAttribute('href', '/register?discipline=2');

        await karateBtn.click();
        await page.waitForURL(/\/register\?discipline=2/, { timeout: 10000 });

        // La discipline Karaté (id=2) doit être automatiquement pré-sélectionnée
        const preselectedDisc = page.locator('.prac-discipline').first();
        await expect(preselectedDisc).toHaveValue('2');
        await expect(page.locator('.prac-grade').first().locator('option')).not.toHaveCount(0);
    });

    test('S13-03 : Réinscription complète pré-remplie (identité, discipline, grade, niveau technique, urgence & tuteurs)', async ({ page }) => {
        // Connexion en tant que Thomas Martin (tutorA - parent d'enfants inscrits)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'tutorA' } });
        await page.goto('/register');
        await page.evaluate(() => localStorage.removeItem('kanso_reg_draft'));
        await page.reload();

        const reEnrollSelect = page.locator('#select-existing-family-member');
        await expect(reEnrollSelect).toBeVisible();
        // Attendre que les membres liés soient chargés dans le sélecteur
        await expect(reEnrollSelect.locator('option')).not.toHaveCount(1, { timeout: 10000 });

        // Sélectionner le 1er enfant rattaché (value="0")
        await reEnrollSelect.selectOption('0');

        // Vérifier la bannière de pré-remplissage
        await expect(page.locator('[data-testid="reg-reenroll-prefilled-banner"]')).toBeVisible();

        // 1. Identité pré-remplie
        const firstNameVal = await page.locator('#reg-first-name').inputValue();
        const lastNameVal = await page.locator('#reg-last-name').inputValue();
        const birthVal = await page.locator('#reg-birth-date').inputValue();
        expect(firstNameVal.length).toBeGreaterThan(1);
        expect(lastNameVal.length).toBeGreaterThan(1);
        expect(birthVal).toMatch(/^\d{4}-\d{2}-\d{2}$/);

        // 2. Discipline, grade et niveau technique (ceinture) pré-remplis
        const discVal = await page.locator('.prac-discipline').first().inputValue();
        const gradeVal = await page.locator('.prac-grade').first().inputValue();
        const levelVal = await page.locator('.prac-level').first().inputValue();
        expect(discVal).not.toBe('');
        expect(gradeVal).not.toBe('');
        expect(levelVal).not.toBe('');

        // 3. Passage à l'étape Tuteurs (enfant mineur) -> coordonnées complètes du tuteur légal pré-remplies
        await page.click('#btn-next-from-identity');
        await expect(page.locator('#step-panel-tutors')).toBeVisible();
        await expect(page.locator('#tutor1-first-name')).toHaveValue('Thomas');
        await expect(page.locator('#tutor1-last-name')).toHaveValue('Martin');
        await expect(page.locator('#tutor1-email')).toHaveValue('tutorA@mail.com');
        await expect(page.locator('#tutor1-phone')).not.toHaveValue('');
        await expect(page.locator('#tutor1-address')).not.toHaveValue('');

        // 4. Passage à l'étape Contact d'urgence -> nom et téléphone pré-remplis
        await page.click('#btn-next-from-tutors');
        await expect(page.locator('#step-panel-emergency')).toBeVisible();
        await expect(page.locator('#reg-emerg-name')).not.toHaveValue('');
        await expect(page.locator('#reg-emerg-phone')).not.toHaveValue('');
    });

    test('S13-04 : Aperçu réaliste du texte post-inscription dans /settings avec bascule édition live', async ({ page, request }, testInfo) => {
        const isDesktop = testInfo.project.name === 'chromium';
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/settings');

        // L'encadré d'aperçu réaliste est visible par défaut et l'éditeur masqué
        const previewBox = page.locator('[data-testid="confirm-text-preview-box"]');
        const previewContent = page.locator('[data-testid="confirm-text-preview-content"]');
        const editorWrapper = page.locator('[data-testid="confirm-text-editor-wrapper"]');
        const toggleBtn = page.locator('[data-testid="btn-toggle-confirm-text-edit"]');
        const textarea = page.locator('[data-testid="setting-confirm-text"]');

        await expect(previewBox).toBeVisible();
        await expect(previewBox).toContainText('Votre demande d\'adhésion est bien enregistrée');
        await expect(editorWrapper).toBeHidden();

        const originalText = await textarea.inputValue();

        try {
            // Clic sur "Modifier le texte" -> ouvre la zone d'édition
            await toggleBtn.click();
            await expect(editorWrapper).toBeVisible();
            await expect(toggleBtn).toContainText(/Masquer/i);

            // Mise à jour en direct dans l'aperçu visuel
            const customMsg = `Bienvenue au dojo ! Merci de régler votre cotisation sous 15 jours (${testInfo.project.name}).`;
            await textarea.fill(customMsg);
            await expect(previewContent).toContainText(customMsg);

            if (isDesktop) {
                // Enregistrer et vérifier la persistance après rechargement
                await page.click('#btn-save-settings');
                await expect(page.locator('[data-testid="toast-success"]').last()).toContainText(/enregistrés/i);

                await page.reload();
                await expect(page.locator('[data-testid="confirm-text-preview-content"]')).toContainText(customMsg);
            }
        } finally {
            if (isDesktop && originalText) {
                await request.patch('/api/v1/settings', {
                    data: { registration_confirmation_text: originalText }
                });
            }
        }
    });
});
