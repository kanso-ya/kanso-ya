import { test, expect } from '@playwright/test';

test.describe('Sprint 9 : Traçabilité Éditoriale CMS, Attestations Trésorerie, Export CSV & Filtres CRM', () => {

    test('US 9.1 - Traçabilité Auteur et Réviseur dans le CMS (Points 18 & 19)', async ({ page }) => {
        // 1. Rédacteur crée un article
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'redacteur' } });
        await page.goto('/cms');

        const title = `Article Traçabilité Test ${Date.now()}`;
        await page.fill('#art-title', title);
        await page.selectOption('#art-category', '1');
        await page.selectOption('#art-visibility', 'public');

        // Rédaction du contenu
        const rawContent = page.locator('#art-content');
        if (await rawContent.isVisible()) {
            await rawContent.fill('<p>Contenu test pour validation de la traçabilité auteur et éditeur.</p>');
        }
        await page.click('#btn-save-article');

        // Vérifier toast de succès
        await expect(page.locator('#toast-container')).toContainText('enregistré');

        // Aller sur l'onglet 2 (Publications)
        await page.click('#cms-tab-btn-publications');
        const articlesList = page.locator('#articles-list');
        await expect(articlesList).toContainText(title);

        // Vérifier la mention de l'auteur
        const authorBadge = articlesList.locator('[data-testid="article-author"]').first();
        await expect(authorBadge).toBeVisible();
        await expect(authorBadge).toContainText('Rédigé par');

        // 2. Président modifie l'article
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/cms');
        await page.click('#cms-tab-btn-publications');

        // Cliquer sur modifier du premier article
        const editBtn = articlesList.locator('button:has-text("✏️ Modifier dans le Builder")').first();
        await editBtn.click();

        // Modifier le titre
        const updatedTitle = `${title} (Révisé)`;
        await page.fill('#art-title', updatedTitle);
        await page.click('#btn-save-article');
        await expect(page.locator('#toast-container')).toContainText('mis à jour');

        // Retourner sur l'onglet Publications et vérifier la traçabilité réviseur
        await page.click('#cms-tab-btn-publications');
        await expect(articlesList).toContainText(updatedTitle);

        const editorBadge = articlesList.locator('[data-testid="article-editor"]').first();
        await expect(editorBadge).toBeVisible();
        await expect(editorBadge).toContainText('Dernière modification par');
    });

    test('US 9.2 - Nettoyage des Médias Orphelins (Point 25)', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/cms');
        await page.click('#cms-tab-btn-publications');

        const cleanupBtn = page.locator('#btn-cleanup-orphans');
        await expect(cleanupBtn).toBeVisible();
        await cleanupBtn.click();

        // Confirmer dans la modale
        const confirmBtn = page.locator('#confirm-modal-accept');
        await expect(confirmBtn).toBeVisible();
        await confirmBtn.click();

        // Vérifier le toast
        await expect(page.locator('#toast-container')).toContainText('Nettoyage terminé');
    });

    test('US 9.3 - Reçu de Cotisation & Attestation CE / CSE (Point 20)', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });
        await page.goto('/registrations');

        await page.waitForSelector('#registrations-tbody tr');

        // S'assurer qu'au moins un dossier est validé pour l'inspection des attestations
        const hasReceiptBtn = await page.locator('.btn-download-receipt').first().count() > 0;
        if (!hasReceiptBtn) {
            const valBtn = page.locator('button:has-text("Valider")').first();
            if (await valBtn.isVisible()) {
                await valBtn.click();
                await page.waitForTimeout(400);
            }
        }

        // Filtrer sur les dossiers validés
        await page.selectOption('#filter-reg-status', 'validated');

        // Vérifier présence du bouton Reçu
        const receiptBtn = page.locator('.btn-download-receipt').first();
        await expect(receiptBtn).toBeVisible();
        await receiptBtn.click();

        // Modale document officiel
        const docModal = page.locator('#document-preview-modal');
        await expect(docModal).toBeVisible();
        const docBody = page.locator('#doc-modal-body');
        await expect(docBody).toContainText('REÇU DE COTISATION');
        await expect(docBody).toContainText('Total Réglé');

        // Fermer la modale
        await page.click('#btn-dismiss-doc-modal');
        await expect(docModal).toBeHidden();

        // Bouton Attestation CSE
        const cseBtn = page.locator('.btn-download-cse').first();
        await expect(cseBtn).toBeVisible();
        await cseBtn.click();

        await expect(docModal).toBeVisible();
        await expect(docBody).toContainText('ATTESTATION DE PRISE EN CHARGE CE / CSE');
        await expect(docBody).toContainText('Comité Social et Économique');

        await page.click('#btn-close-doc-modal');
        await expect(docModal).toBeHidden();
    });

    test('US 9.4 - Export CSV des Effectifs CRM & Cloisonnement RGPD (Point 21)', async ({ page }) => {
        // 1. En tant que Trésorier : export complet avec données de contact et financières
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });
        const resTresorier = await page.request.get('/api/v1/members/export');
        expect(resTresorier.status()).toBe(200);

        const jsonTresorier = await resTresorier.json();
        expect(jsonTresorier.status).toBe('success');
        const csvContentTresorier = jsonTresorier.data.csv as string;
        // Vérifier le BOM UTF-8
        expect(csvContentTresorier.charCodeAt(0)).toBe(0xFEFF);
        // Doit contenir les entêtes complètes
        expect(csvContentTresorier).toContain('Nom');
        expect(csvContentTresorier).toContain('Prénom');
        expect(csvContentTresorier).toContain('Email');
        expect(csvContentTresorier).toContain('Téléphone');
        expect(csvContentTresorier).toContain('Adresse');

        // 2. En tant qu\'Entraîneur : export restreint (cloisonnement RGPD terrain)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });
        const resEntraineur = await page.request.get('/api/v1/members/export');
        expect(resEntraineur.status()).toBe(200);

        const jsonEntraineur = await resEntraineur.json();
        expect(jsonEntraineur.status).toBe('success');
        const csvContentEntraineur = jsonEntraineur.data.csv as string;
        expect(csvContentEntraineur.charCodeAt(0)).toBe(0xFEFF);
        expect(csvContentEntraineur).toContain('Nom');
        expect(csvContentEntraineur).toContain('Prénom');
        expect(csvContentEntraineur).toContain('Contact Urgence');
        // Ne doit PAS contenir d'adresses privées
        expect(csvContentEntraineur).not.toContain('Adresse postale');
    });

    test('US 9.5 - Filtres combinés CRM & Onglets Disciplines sans parenthèses (Points 22 & 23)', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'secretaire' } });
        await page.goto('/members');

        // Attendre le chargement asynchrone des onglets de disciplines
        await page.waitForSelector('#discipline-tabs-container button');

        // Vérifier que la barre d'onglets ne contient pas de parenthèses textuelles brute dans les boutons
        const tabButtons = page.locator('#discipline-tabs-container button');
        const count = await tabButtons.count();
        expect(count).toBeGreaterThan(0);

        for (let i = 0; i < count; i++) {
            const btnText = await tabButtons.nth(i).innerText();
            // Les parenthèses "(X)" sont proscrites au profit d'un badge séparé
            expect(btnText).not.toMatch(/\(\d+\)/);
        }

        // Vérifier les badges d'effectif
        const badges = page.locator('.discipline-count-badge');
        expect(await badges.count()).toBeGreaterThan(0);

        // Tester le filtre d'âge combiné
        await page.selectOption('#filter-member-age', 'minor');
        // Tester le filtre de santé combiné
        await page.selectOption('#filter-member-health', 'signed');

        // Remettre les filtres à "Tous"
        await page.selectOption('#filter-member-age', '');
        await page.selectOption('#filter-member-health', '');
    });

    test('US 9.6 - Relance groupée d\'OTP (Point 24)', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'secretaire' } });
        await page.goto('/members');

        // Attendre le chargement des adhérents
        await page.waitForSelector('.member-row-checkbox');

        // Sélectionner la première case à cocher d'un membre
        const firstCheckbox = page.locator('.member-row-checkbox').first();
        await expect(firstCheckbox).toBeVisible();
        await firstCheckbox.check();

        // Le bouton de relance groupée doit afficher le nombre sélectionné
        const bulkBtn = page.locator('#btn-bulk-resend-otp');
        await expect(bulkBtn).toBeVisible();
        await expect(bulkBtn).toContainText('(1)');

        // Cliquer sur le bouton de relance
        await bulkBtn.click();

        // Vérifier le toast de confirmation
        await expect(page.locator('#toast-container')).toContainText('renvoyé(s) avec succès');
    });

    test('US 9.7 - Tri ascendant / descendant interactif sur colonnes (Point 26)', async ({ page }) => {
        // 1. Tri sur /members (en tant que Président avec tous les accès)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/members');

        await page.waitForSelector('#members-table tbody tr');
        const sortName = page.locator('th[data-sort="name"]');
        await expect(sortName).toBeVisible();
        // Premier clic -> Tri
        await sortName.click();
        const icon1 = sortName.locator('.sort-icon');
        await expect(icon1).toHaveText(/▲|▼/);

        // Deuxième clic -> Inversion
        await sortName.click();
        const icon2 = sortName.locator('.sort-icon');
        await expect(icon2).toHaveText(/▲|▼/);

        // 2. Tri sur /registrations
        await page.goto('/registrations');
        await page.waitForSelector('#registrations-tbody tr');
        const sortAmount = page.locator('th[data-sort="amount"]');
        await expect(sortAmount).toBeVisible();
        await sortAmount.click();
        const iconAmount = sortAmount.locator('.sort-icon');
        await expect(iconAmount).toHaveText(/▲|▼/);

        // 3. Tri sur /cms
        await page.goto('/cms');
        await page.click('#cms-tab-btn-publications');
        await page.waitForSelector('#articles-list');
        const sortArticlesBtn = page.locator('#btn-sort-articles');
        await expect(sortArticlesBtn).toBeVisible();
        await sortArticlesBtn.click();
        await expect(sortArticlesBtn).toContainText('Tri :');
    });

});
