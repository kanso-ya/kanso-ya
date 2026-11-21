import { test, expect } from '@playwright/test';

test.describe.serial('Sprint 25 (Lot 6) — Boutique Club : Catalogue Avancé, Variantes, Remises Adhérents, Flocage & Lots', () => {
    const resetAndActivateBoutique = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
        await request.post('/api/v1/sys/modules/scan');
        const toggleRes = await request.patch('/api/v1/sys/modules/BoutiqueApi/toggle', {
            data: { enabled: true },
        });
        expect(toggleRes.status()).toBe(200);
        expect(Boolean((await toggleRes.json()).data.is_active)).toBe(true);
    };

    const uninstallBoutique = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
    };

    test.afterAll(async ({ request }) => {
        await uninstallBoutique(request);
    });

    test('S25-01 : Persona 1 — Création produit (tailles, remise 20%, flocage +8€, members_only) & Lot mixte, puis vue Adhérent', async ({ page, request }) => {
        await resetAndActivateBoutique(request);

        // 1. Président crée le Sweat Adhérent Club via l'UI /boutique
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/boutique');
        await page.getByTestId('btn-toggle-boutique-admin').click();
        await expect(page.getByTestId('boutique-admin-panel')).toBeVisible();

        await page.getByTestId('prod-name-input').fill('Sweat Adherent Club');
        await page.getByTestId('prod-category-input').fill('Tenues');
        await page.getByTestId('prod-price-input').fill('50.00');
        await page.getByTestId('prod-availability-select').selectOption('en_stock');
        await page.getByTestId('prod-discount-type-select').selectOption('percent');
        await page.getByTestId('prod-discount-value-input').fill('20');
        await page.getByTestId('prod-sizes-input').fill('S:3, M:5');
        await page.getByTestId('prod-members-only-check').check();
        await page.getByTestId('prod-flocking-check').check();
        await page.getByTestId('prod-flocking-price-input').fill('8.00');

        const [prodRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/products') && r.request().method() === 'POST'),
            page.getByTestId('btn-create-product').click(),
        ]);
        const prodJson = await prodRes.json();
        expect(prodJson.code).toBe(201);
        const sweatId: number = prodJson.data.id;

        await page.waitForTimeout(450);
        await page.waitForLoadState('networkidle');

        // 2. Président crée un Lot mixte (article existant #1 + article exclusif au lot)
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/products') && r.request().method() === 'GET'),
            page.getByTestId('btn-toggle-boutique-admin').click(),
        ]);
        await page.getByTestId('bundle-name-input').fill('Pack Rentree Dojo');
        await page.getByTestId('bundle-price-input').fill('55.00');
        await page.getByTestId('bundle-existing-product-select').selectOption('1');
        await page.getByTestId('bundle-custom-item-input').fill('Sac Dojo Exclusif');

        const [bundleRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/bundles') && r.request().method() === 'POST'),
            page.getByTestId('btn-create-bundle').click(),
        ]);
        const bundleJson = await bundleRes.json();
        expect(bundleJson.code).toBe(201);
        const bundleId: number = bundleJson.data.id;

        await page.waitForTimeout(450);
        await page.waitForLoadState('networkidle');

        // 3. Adhérent connecté : voit le Sweat à 40,00 € (-20%), tailles S/M, flocage +8€ -> 48,00 € en direct, et le Pack Rentrée
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        await page.goto('/boutique');

        await expect(page.getByTestId('boutique-admin-panel')).toHaveCount(0);
        await expect(page.getByTestId(`product-card-${sweatId}`)).toBeVisible();
        await expect(page.getByTestId(`product-effective-price-${sweatId}`)).toHaveText('40,00 €');
        await expect(page.getByTestId(`product-size-select-${sweatId}`)).toBeVisible();

        // Activation flocage -> prix passe à 48,00 € et champ texte visible
        await page.getByTestId(`product-flocking-toggle-${sweatId}`).check();
        await expect(page.getByTestId(`product-flocking-text-${sweatId}`)).toBeVisible();
        await expect(page.getByTestId(`product-effective-price-${sweatId}`)).toHaveText('48,00 €');

        // Vérification du lot mixte dans boutique-bundles-grid
        const bundleCard = page.getByTestId(`bundle-card-${bundleId}`);
        await expect(bundleCard).toBeVisible();
        await expect(bundleCard).toContainText('Pack Rentree Dojo');
        await expect(bundleCard).toContainText('Kimono Club Kanso-ya');
        await expect(bundleCard).toContainText('Sac Dojo Exclusif');
        await expect(bundleCard).toContainText('Exclusif au lot');
    });

    test('S25-02 : Persona 2 — Visiteur cloisonné sur members_only, prix public affiché, et gardes API 403 / 422', async ({ page, request }) => {
        // 1. Visiteur non connecté ne voit pas le Sweat Adhérent Club (members_only = 1)
        await page.request.post('/api/v1/auth/logout');
        await request.post('/api/v1/auth/logout');

        const pubRes = await (await request.get('/api/v1/modules/boutique/products')).json();
        expect(pubRes.code).toBe(200);
        const pubNames = pubRes.data.map((p: { name: string }) => p.name);
        expect(pubNames).not.toContain('Sweat Adherent Club');
        const kimono = pubRes.data.find((p: { name: string }) => p.name === 'Kimono Club Kanso-ya');
        expect(kimono.effective_price).toBe(45);

        await page.goto('/boutique');
        await expect(page.getByTestId('boutique-products-grid')).not.toContainText('Sweat Adherent Club');

        // 2. Adhérent bloqué en 403 sur création produit / lot
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        expect((await (await request.post('/api/v1/modules/boutique/products', { data: { name: 'X', price: 10 } })).json()).code).toBe(403);
        expect((await (await request.post('/api/v1/modules/boutique/bundles', { data: { name: 'B', price: 10, items: [{ custom_item_name: 'A' }] } })).json()).code).toBe(403);

        // 3. Président reçoit 422 sur payload invalide
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        expect((await (await request.post('/api/v1/modules/boutique/products', { data: { name: 'Bad', price: -5 } })).json()).code).toBe(422);
        expect((await (await request.post('/api/v1/modules/boutique/bundles', { data: { name: 'EmptyBundle', price: 20, items: [] } })).json()).code).toBe(422);
    });
});
