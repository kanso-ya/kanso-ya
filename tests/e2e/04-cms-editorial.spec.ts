/**
 * @file 04-cms-editorial.spec.ts
 * @description Suite E2E & API : Domaine 4 - Studio CMS, Builder Multi-Colonnes, Workflow Éditorial & Sécurité XSS.
 */

import { test, expect } from '@playwright/test';

test.describe('DOMAINE 4 — Studio CMS, Builder Multi-Colonnes, Workflow Éditorial & Sécurité XSS', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('TC-CMS-BUILDER : Navigation entre les 2 onglets du Studio CMS, ajout d\'une rangée 2 colonnes et modal Aperçu', async ({ page }) => {
        await page.goto('/cms');

        // 1. Onglet 1 par défaut : Studio Builder
        await expect(page.locator('#cms-tab-panel-editor')).toBeVisible();
        await expect(page.locator('#cms-tab-panel-publications')).toHaveClass(/.*hidden.*/);

        // 2. Ajout d'une rangée 2 colonnes dans le Builder
        await page.click('button[data-cols="2"]');
        await expect(page.locator('.builder-row-grid[data-cols="2"]')).toBeVisible();

        // 3. Saisie du titre et ouverture de la modale d'Aperçu
        await page.fill('#art-title', 'Titre Aperçu Test');
        await page.click('#btn-preview-article');
        const previewModal = page.locator('#article-preview-modal');
        await expect(previewModal).toBeVisible();
        await expect(page.locator('#preview-modal-title')).toContainText('Titre Aperçu Test');
        await page.click('#btn-close-preview-modal');

        // 4. Bascule sur l'Onglet 2 : Publications & Pages Légales
        await page.click('#cms-tab-btn-publications');
        await expect(page.locator('#cms-tab-panel-publications')).toBeVisible();
        await expect(page.locator('#cms-tab-panel-editor')).toHaveClass(/.*hidden.*/);
    });

    test('TC-CMS-P01 & TC-CMS-N01 : Un Rédacteur soumettant status="published" est automatiquement forcé en "draft"', async ({ request }) => {
        const response = await request.post('/api/v1/articles', {
            headers: { 'Authorization': 'Bearer stub-redacteur-token' },
            data: {
                title: 'Compte-rendu du stage régional',
                content: '<p>Superbe participation de nos judokas.</p>',
                category_id: 2,
                visibility: 'public',
                status: 'published'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.code).toBe(201);
        expect(body.data.status).toBe('draft'); // Forcé en brouillon

        await request.delete(`/api/v1/articles/${body.data.id}`);
    });

    test('TC-CMS-PUBLISH-WF : Un utilisateur avec droit articles.publish peut modifier et publier un article', async ({ request }) => {
        // 1. Création d'un brouillon
        const createRes = await request.post('/api/v1/articles', {
            data: {
                title: 'Article en préparation',
                content: '<p>Contenu en cours de finalisation.</p>',
                category_id: 1,
                visibility: 'public',
                status: 'draft'
            }
        });
        const articleId = (await createRes.json()).data.id;

        try {
            // 2. Publication par le Président / Secrétaire
            const pubRes = await request.patch(`/api/v1/articles/${articleId}/status`, {
                data: { status: 'published' }
            });
            const pubBody = await pubRes.json();
            expect(pubBody.status).toBe('success');
            expect(pubBody.data.status).toBe('published');

            // 3. Vérification que l'article est désormais visible
            const getRes = await request.get(`/api/v1/articles/${articleId}`);
            expect((await getRes.json()).data.status).toBe('published');
        } finally {
            await request.delete(`/api/v1/articles/${articleId}`);
        }
    });

    test('TC-CMS-N02 : Neutralisation absolue des injections XSS (<script>, onclick, onerror) dans les articles', async ({ request }) => {
        const response = await request.post('/api/v1/articles', {
            data: {
                title: 'Article Test XSS',
                content: '<p>Texte sain</p><script>alert("hack")</script><img src=x onerror="alert(1)"><p onclick="evil()">Suite</p>',
                category_id: 1,
                visibility: 'public',
                status: 'published'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.data.content).not.toContain('<script>');
        expect(body.data.content).not.toContain('onclick');
        expect(body.data.content).not.toContain('onerror');
        expect(body.data.content).toContain('<p>Texte sain</p>');

        await request.delete(`/api/v1/articles/${body.data.id}`);
    });

    test('REQ-CMS-WF-03 & TC-CMS-N03 : Étanchéité de visibilité (404 pour Visiteur anonyme, 403 pour Adhérent sur article Bureau)', async ({ request }) => {
        const createRes = await request.post('/api/v1/articles', {
            data: {
                title: 'PV Réunion Bureau Confidentiel',
                content: '<p>Budget prévisionnel et orientations financières.</p>',
                category_id: 1,
                visibility: 'board',
                status: 'published'
            }
        });
        const articleId = (await createRes.json()).data.id;

        try {
            // Visiteur anonyme -> 404 Not Found (Masquage de la ressource)
            const visitorRes = await request.get(`/api/v1/articles/${articleId}`, {
                headers: { 'Authorization': 'Bearer stub-visitor-token' }
            });
            expect((await visitorRes.json()).code).toBe(404);

            // Adhérent connecté -> 403 Forbidden (Non membre du bureau)
            const memberRes = await request.get(`/api/v1/articles/${articleId}`, {
                headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
            });
            expect((await memberRes.json()).code).toBe(403);
        } finally {
            await request.delete(`/api/v1/articles/${articleId}`);
        }
    });

    test('US 4.1 : Pages légales fixes (/rgpd, /cgv) accessibles au public et statut brouillon renvoyant 404', async ({ page, request }) => {
        await page.goto('/rgpd');
        await expect(page.locator('#legal-page-title')).toContainText('RGPD');

        // Modification en statut draft d'une page
        await request.put('/api/v1/pages/reglement-interieur', {
            data: {
                title: 'Règlement Intérieur 2027',
                content: '<p>Document en cours de validation par le comité.</p>',
                status: 'draft'
            }
        });

        // Visiteur anonyme tente de consulter la page draft -> 404
        const draftRes = await request.get('/api/v1/pages/reglement-interieur', {
            headers: { 'Authorization': 'Bearer stub-visitor-token' }
        });
        expect((await draftRes.json()).code).toBe(404);
    });

    test('US 4.2 : Affichage et rendu des badges colorés Tailwind pour les catégories', async ({ page }) => {
        await page.goto('/');
        await page.waitForSelector('#home-articles-grid article');
        const articleCard = page.locator('#home-articles-grid article').first();
        await expect(articleCard).toBeVisible();

        const categoryBadge = articleCard.locator('.article-badge');
        await expect(categoryBadge).toBeVisible();
    });

    test('TC-CMS-MEDIA : Upload de média pour article et rejet des fichiers non-images', async ({ request }) => {
        const badMediaRes = await request.post('/api/v1/articles/media', {
            multipart: {
                file: {
                    name: 'script.js',
                    mimeType: 'text/javascript',
                    buffer: Buffer.from('alert(1);')
                }
            }
        });
        expect((await badMediaRes.json()).code).toBe(422);
    });
});
