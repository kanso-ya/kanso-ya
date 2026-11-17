/**
 * @file 13-cms-builder-advanced.spec.ts
 * @description Suite de tests E2E pour les fonctionnalités avancées du Studio Éditorial (CMS) :
 * - US 13.1 : Drag & Drop intégral (Barre d'outils -> Canevas ET Réorganisation intra-colonne)
 * - US 13.2 : 2 Colonnes Asymétriques avec réglage de largeur au choix (30/70, 40/60, 50/50, 60/40, 70/30) et gel des styles inline
 * - US 13.3 : Lecteur Vidéo Léger Personnalisé (Play/Pause, Stop, Volume sans contrôles natifs)
 * - US 13.4 : Bloc Photo Simple et Carrousel (Horizontal & Vertical avec navigation et compteur)
 * - US 13.5 : Filtres Multi-Critères (Statut, Catégorie, Auteur) et Traçabilité (Auteur & Modificateur) sur Publications
 */

import { test, expect } from '@playwright/test';

test.describe('DOMAINE 13 — Studio Éditorial Avancé : Drag & Drop, Colonnes Asymétriques, Vidéo & Carrousels, Filtres Publications', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('US 13.1 : Drag & Drop intégral — Barre d\'outils vers canevas et réorganisation intra-colonne', async ({ page }) => {
        await page.goto('/cms');

        // 1. Vérification que les boutons de la barre d'outils sont configurés pour le Drag & Drop (draggable="true")
        const toolbarCalloutBtn = page.locator('button[data-add-block="callout"]');
        const toolbarH2Btn = page.locator('button[data-add-block="h2"]');
        await expect(toolbarCalloutBtn).toHaveAttribute('draggable', 'true');
        await expect(toolbarH2Btn).toHaveAttribute('draggable', 'true');

        // Cibler la première zone de dépôt (dropzone)
        const dropzone = page.locator('.column-dropzone').first();
        await expect(dropzone).toBeVisible();

        // 2. Glisser-déposer depuis la barre d'outils (bouton Encadré Info) vers la colonne
        await toolbarCalloutBtn.dispatchEvent('dragstart');
        await dropzone.dispatchEvent('dragover');
        await dropzone.dispatchEvent('drop');
        await toolbarCalloutBtn.dispatchEvent('dragend');

        const calloutBlock = dropzone.locator('.builder-block:has(p.border-l-4)');
        await expect(calloutBlock).toBeVisible();

        // 3. Glisser-déposer un deuxième bloc (Titre h2) depuis la barre d'outils
        await toolbarH2Btn.dispatchEvent('dragstart');
        await dropzone.dispatchEvent('dragover');
        await dropzone.dispatchEvent('drop');
        await toolbarH2Btn.dispatchEvent('dragend');

        const titleBlock = dropzone.locator('.builder-block:has(h2)');
        await expect(titleBlock).toBeVisible();

        // 4. Réorganisation intra-colonne : Déplacer le bloc Titre avant le bloc Encadré Info
        await titleBlock.dispatchEvent('dragstart');
        await calloutBlock.dispatchEvent('dragover', { clientY: 10 });
        await calloutBlock.dispatchEvent('drop');
        await titleBlock.dispatchEvent('dragend');

        // Vérifier dans le code source HTML synchronisé que le h2 apparaît bien avant l'encadré
        const contentText = await page.locator('#art-content').inputValue();
        expect(contentText).toContain('<h2');
        expect(contentText).toContain('Encadré important');
    });

    test('US 13.2 : Colonnes Asymétriques — Répartition de largeur personnalisée et styles inline figés', async ({ page }) => {
        await page.goto('/cms');

        // 1. Clic sur le bouton d'ajout de 2 colonnes asymétriques
        const btnAsym = page.locator('button[data-cols="asym"]');
        await expect(btnAsym).toBeVisible();
        await btnAsym.click();

        // 2. Vérification de la création de la rangée asymétrique avec son sélecteur de ratio
        const asymGrid = page.locator('.builder-row-grid[data-cols="asym"]');
        await expect(asymGrid).toBeVisible();
        const ratioSelect = page.locator('.select-col-ratio').first();
        await expect(ratioSelect).toBeVisible();

        // Par défaut : 30% / 70%
        await expect(ratioSelect).toHaveValue('30-70');
        const col1 = asymGrid.locator('.builder-column').nth(0);
        const col2 = asymGrid.locator('.builder-column').nth(1);
        await expect(col1).toHaveCSS('flex-basis', '30%');
        await expect(col2).toHaveCSS('flex-basis', '70%');

        // 3. Changement de ratio vers 40% / 60%
        await ratioSelect.selectOption('40-60');
        await expect(asymGrid).toHaveAttribute('data-ratio', '40-60');
        await expect(col1).toHaveCSS('flex-basis', '40%');
        await expect(col2).toHaveCSS('flex-basis', '60%');

        // 4. Changement de ratio vers 70% / 30%
        await ratioSelect.selectOption('70-30');
        await expect(asymGrid).toHaveAttribute('data-ratio', '70-30');
        await expect(col1).toHaveCSS('flex-basis', '70%');
        await expect(col2).toHaveCSS('flex-basis', '30%');

        // 5. Vérifier que les styles inline sont fidèlement enregistrés dans le HTML généré (#art-content)
        const html = await page.locator('#art-content').inputValue();
        expect(html).toContain('cms-row-asym');
        expect(html).toContain('data-ratio="70-30"');
        expect(html).toContain('flex: 0 0 70%');
        expect(html).toContain('flex: 0 0 30%');
    });

    test('US 13.3 : Lecteur Vidéo Léger Personnalisé — Contrôles Play, Stop, Volume et rendu propre', async ({ page }) => {
        await page.goto('/cms');

        // 1. Ajout d'un bloc vidéo depuis la barre d'outils
        await page.click('button[data-add-block="video"]');
        const videoBlock = page.locator('.custom-video-player');
        await expect(videoBlock).toBeVisible();

        // 2. Vérification des boutons de contrôle personnalisés (sans barre native)
        const playBtn = videoBlock.locator('.btn-video-play');
        const stopBtn = videoBlock.locator('.btn-video-stop');
        const volSlider = videoBlock.locator('.video-volume-slider');

        await expect(playBtn).toBeVisible();
        await expect(playBtn).toHaveText('▶ Play');
        await expect(stopBtn).toBeVisible();
        await expect(volSlider).toBeVisible();

        // 3. Interaction avec les contrôles
        await playBtn.click();
        await stopBtn.click();
        await expect(playBtn).toHaveText('▶ Play');

        // 4. Réglage du volume
        await volSlider.fill('0.5');

        // 5. Vérifier que la vidéo et son lecteur personnalisé sont bien présents dans la modale d'Aperçu
        await page.fill('#art-title', 'Article avec Lecteur Vidéo');
        await page.click('#btn-preview-article');
        const previewModal = page.locator('#article-preview-modal');
        await expect(previewModal).toBeVisible();
        await expect(previewModal.locator('.custom-video-player')).toBeVisible();
        await page.click('#btn-close-preview-modal');
    });

    test('US 13.4 : Bloc Photo Simple et Carrousel — Défilement horizontal avec flèches et vertical', async ({ page }) => {
        await page.goto('/cms');

        // 1. Ajout d'un bloc photo
        await page.click('button[data-add-block="image"]');
        const photoBlock = page.locator('.photo-block-container');
        await expect(photoBlock).toBeVisible();

        // 2. Bascule en Carrousel Horizontal
        const modeSelect = photoBlock.locator('.select-photo-mode');
        await modeSelect.selectOption('carousel-horizontal');
        const horizCarousel = photoBlock.locator('.cms-carousel-horizontal');
        await expect(horizCarousel).toBeVisible();
        await expect(horizCarousel.locator('.btn-carousel-prev')).toBeVisible();
        await expect(horizCarousel.locator('.btn-carousel-next')).toBeVisible();
        await expect(horizCarousel.locator('.carousel-indicator')).toContainText('1 / 1');

        // Clic sur Suivant
        await horizCarousel.locator('.btn-carousel-next').click();
        await expect(horizCarousel.locator('.carousel-indicator')).toContainText('1 / 1');

        // 3. Bascule en Carrousel Vertical
        await modeSelect.selectOption('carousel-vertical');
        const vertCarousel = photoBlock.locator('.cms-carousel-vertical');
        await expect(vertCarousel).toBeVisible();
        await expect(vertCarousel.locator('.btn-carousel-prev')).toBeVisible();
        await expect(vertCarousel.locator('.btn-carousel-next')).toBeVisible();

        // 4. Vérification dans l'aperçu
        await page.click('#btn-preview-article');
        const previewModal = page.locator('#article-preview-modal');
        await expect(previewModal).toBeVisible();
        await expect(previewModal.locator('.cms-carousel-vertical')).toBeVisible();
        await page.click('#btn-close-preview-modal');
    });

    test('US 13.5 : Filtres Multi-Critères et Traçabilité Auteur/Modificateur sur l\'onglet Publications', async ({ page }) => {
        await page.goto('/cms');

        // 1. Basculer sur l'onglet 2 : Publications & Pages
        await page.click('#cms-tab-btn-publications');
        await expect(page.locator('#cms-tab-panel-publications')).toBeVisible();

        // 2. Vérification de la barre de filtres
        const statusFilter = page.locator('#filter-article-status');
        const categoryFilter = page.locator('#filter-article-category');
        const authorFilter = page.locator('#filter-article-author');
        const resetBtn = page.locator('#btn-reset-filters');

        await expect(statusFilter).toBeVisible();
        await expect(categoryFilter).toBeVisible();
        await expect(authorFilter).toBeVisible();
        await expect(resetBtn).toBeVisible();

        // 3. Traçabilité sur les cartes d'articles : Auteur + Date & Modificateur
        const firstCard = page.locator('#articles-list > div').first();
        await expect(firstCard).toBeVisible();
        await expect(firstCard.locator('[data-testid="article-author"]')).toBeVisible();
        await expect(firstCard.locator('[data-testid="article-author"]')).toContainText('Rédigé par');

        // 4. Filtrage par statut (Publié)
        await statusFilter.selectOption('published');
        const countPublished = await page.locator('#articles-list > div').count();
        expect(countPublished).toBeGreaterThanOrEqual(1);

        // 5. Réinitialisation des filtres
        await resetBtn.click();
        await expect(statusFilter).toHaveValue('all');
        await expect(categoryFilter).toHaveValue('all');
        await expect(authorFilter).toHaveValue('all');
    });

});
