import { test, expect } from '@playwright/test';

test.describe.serial('Sprint 26 (Lot 6) — Boutique Club : Commandes, Paiement Hors-Ligne, Livraison, Suivi & Facturation PDF', () => {
    const resetAndActivateModules = async (
        request: import('@playwright/test').APIRequestContext,
        withFacturation: boolean = true
    ): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
        await request.post('/api/v1/sys/modules/FacturationApi/uninstall');
        await request.post('/api/v1/sys/modules/scan');

        const toggleBoutique = await request.patch('/api/v1/sys/modules/BoutiqueApi/toggle', {
            data: { enabled: true },
        });
        expect(toggleBoutique.status()).toBe(200);
        expect(Boolean((await toggleBoutique.json()).data.is_active)).toBe(true);

        if (withFacturation) {
            const toggleFact = await request.patch('/api/v1/sys/modules/FacturationApi/toggle', {
                data: { enabled: true },
            });
            expect(toggleFact.status()).toBe(200);
            expect(Boolean((await toggleFact.json()).data.is_active)).toBe(true);
        }
    };

    test.afterAll(async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
        await request.post('/api/v1/sys/modules/FacturationApi/uninstall');
    });

    test('S26-01 : Persona 1 — Paramétrage hors-ligne, commande taille+flocage+livraison, suivi & facture PDF auto', async ({ page, request }) => {
        await resetAndActivateModules(request, true);

        // 1. Président vérifie le verrou "Paiement en ligne indisponible" et règle les frais postaux à 7,00 €
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/boutique');
        await page.getByTestId('boutique-tab-admin').click();
        await expect(page.getByTestId('boutique-settings-form')).toBeVisible();
        await expect(page.getByTestId('setting-pay-online-disabled')).toBeDisabled();
        await expect(page.getByTestId('boutique-settings-form')).toContainText('Indisponible pour le moment');

        await page.getByTestId('setting-postal-fee').fill('7.00');
        const [saveSetRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/settings') && r.request().method() === 'PUT'),
            page.getByTestId('btn-save-boutique-settings').click(),
        ]);
        expect((await saveSetRes.json()).code).toBe(200);
        await page.waitForTimeout(450);
        await page.waitForLoadState('networkidle');

        // 2. Adhérent commande le Kimono (#1 : 45€ -10% adhérent = 40,50€) + taille 160cm + flocage "K. Tanaka" (+6€) + livraison postale (+7€) = 53,50€
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        const beforeProd = (await (await request.get('/api/v1/modules/boutique/products')).json()).data.find(
            (p: { id: number }) => p.id === 1
        );
        const stockBefore: number = Number(beforeProd.stock);

        await page.goto('/boutique');
        await expect(page.getByTestId('boutique-tab-admin')).toHaveCount(0);

        await page.getByTestId('product-size-select-1').selectOption('160cm');
        await page.getByTestId('product-flocking-toggle-1').check();
        await page.getByTestId('product-flocking-text-1').fill('K. Tanaka');
        await page.getByTestId('btn-add-to-cart-product-1').click();

        await page.getByTestId('cart-payment-select').selectOption('cheque');
        await page.getByTestId('cart-delivery-select').selectOption('livraison_postale');
        await page.getByTestId('cart-buyer-address').fill('12 rue du Dojo, 75011 Paris');
        await expect(page.getByTestId('cart-total-display')).toHaveText('53,50 €');

        const [orderRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/orders') && r.request().method() === 'POST'),
            page.getByTestId('btn-submit-order').click(),
        ]);
        const orderJson = await orderRes.json();
        expect(orderJson.code).toBe(201);
        const orderId: number = orderJson.data.order.id;
        const orderNumber: string = orderJson.data.order.order_number;
        expect(orderNumber).toMatch(/^CMD-\d{4}-0001$/);
        expect(Number(orderJson.data.order.total_amount)).toBe(53.5);
        await expect(page.getByTestId('cart-order-confirmation')).toContainText(orderNumber);

        // Vérification décrémentation stock
        const afterProd = (await (await request.get('/api/v1/modules/boutique/products')).json()).data.find(
            (p: { id: number }) => p.id === 1
        );
        expect(Number(afterProd.stock)).toBe(stockBefore - 1);

        // 3. Adhérent consulte son suivi commandes (pas de sélecteur statut Bureau)
        await page.getByTestId('boutique-tab-orders').click();
        await expect(page.getByTestId('boutique-orders-table')).toContainText(orderNumber);
        await expect(page.getByTestId(`order-status-badge-${orderId}`)).toContainText('En attente de paiement');
        await expect(page.getByTestId(`order-status-select-${orderId}`)).toHaveCount(0);

        // 4. Président passe la commande à "payee" -> déclenche la facture PDF officielle & l'e-mail
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/boutique');
        await page.getByTestId('boutique-tab-orders').click();
        await expect(page.getByTestId(`order-row-${orderId}`)).toBeVisible();

        await page.getByTestId(`order-status-select-${orderId}`).selectOption('payee');
        const [payRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/api/v1/modules/boutique/orders/${orderId}/status`) && r.request().method() === 'PATCH'),
            page.getByTestId(`btn-update-order-status-${orderId}`).click(),
        ]);
        expect((await payRes.json()).code).toBe(200);
        await expect(page.getByTestId(`order-status-badge-${orderId}`)).toContainText('Payée');

        // Passage à "prete_ou_expediee"
        await page.getByTestId(`order-status-select-${orderId}`).selectOption('prete_ou_expediee');
        const [shipRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/api/v1/modules/boutique/orders/${orderId}/status`) && r.request().method() === 'PATCH'),
            page.getByTestId(`btn-update-order-status-${orderId}`).click(),
        ]);
        expect((await shipRes.json()).code).toBe(200);
        await expect(page.getByTestId(`order-status-badge-${orderId}`)).toContainText('Prête / Expédiée');

        // 5. Vérification Facture PDF officielle générée dans FacturationApi (source_type='boutique')
        const invListJson = await (await request.get('/api/v1/modules/facturation/invoices')).json();
        expect(invListJson.code).toBe(200);
        const invoices = Array.isArray(invListJson.data?.invoices) ? invListJson.data.invoices : [];
        const boutiqueInv = invoices.find(
            (inv: { source_type: string; source_id: number }) => inv.source_type === 'boutique' && Number(inv.source_id) === orderId
        );
        expect(boutiqueInv).toBeTruthy();
        expect(Number(boutiqueInv.total_amount)).toBe(53.5);

        const pdfJson = await (await request.get(`/api/v1/modules/facturation/invoices/${boutiqueInv.id}/pdf`)).json();
        expect(pdfJson.code).toBe(200);
        expect(pdfJson.data.mime_type).toBe('application/pdf');
        const pdfBytes = Buffer.from(String(pdfJson.data.pdf_base64), 'base64');
        expect(pdfBytes.subarray(0, 5).toString('ascii')).toBe('%PDF-');
    });

    test('S26-02 : Persona 2 — Garde 422 paiement en ligne, 403 RBAC, restauration stock sur annulation & dégradation gracieuse', async ({ request }) => {
        // 1. Rejet 422 si tentative d'activer le paiement en ligne dans les paramètres ou dans une commande
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const badSet = await (await request.put('/api/v1/modules/boutique/settings', {
            data: { online_payment_enabled: true, payment_methods: ['cheque'] },
        })).json();
        expect(badSet.code).toBe(422);

        const badOrd = await (await request.post('/api/v1/modules/boutique/orders', {
            data: {
                buyer_name: 'Test Online',
                buyer_email: 'test@kanso-ya.fr',
                payment_method: 'online',
                delivery_method: 'retrait_dojo',
                items: [{ item_type: 'product', item_id: 2, quantity: 1 }],
            },
        })).json();
        expect(badOrd.code).toBe(422);

        // 2. Adhérent bloqué en 403 sur modification paramètres et transition de statut
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        expect((await (await request.put('/api/v1/modules/boutique/settings', {
            data: { payment_methods: ['cheque'], delivery_methods: ['retrait_dojo'], shipping_postal_fee: 5 },
        })).json()).code).toBe(403);
        expect((await (await request.patch('/api/v1/modules/boutique/orders/1/status', {
            data: { status: 'annulee' },
        })).json()).code).toBe(403);

        // 3. Restauration stock sur annulation d'une commande (produit #2)
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const prod2Before = (await (await request.get('/api/v1/modules/boutique/products')).json()).data.find(
            (p: { id: number }) => p.id === 2
        );
        const stock2Before: number = Number(prod2Before.stock);

        const ord2Json = await (await request.post('/api/v1/modules/boutique/orders', {
            data: {
                buyer_name: 'Annulation Test',
                buyer_email: 'cancel@kanso-ya.fr',
                payment_method: 'especes',
                delivery_method: 'retrait_dojo',
                items: [{ item_type: 'product', item_id: 2, quantity: 2 }],
            },
        })).json();
        expect(ord2Json.code).toBe(201);
        const ord2Id: number = ord2Json.data.order.id;

        const cancelJson = await (await request.patch(`/api/v1/modules/boutique/orders/${ord2Id}/status`, {
            data: { status: 'annulee' },
        })).json();
        expect(cancelJson.code).toBe(200);

        const prod2After = (await (await request.get('/api/v1/modules/boutique/products')).json()).data.find(
            (p: { id: number }) => p.id === 2
        );
        expect(Number(prod2After.stock)).toBe(stock2Before);

        // 4. Dégradation gracieuse : si FacturationApi est désinstallé, le passage à 'payee' réussit sans erreur
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/FacturationApi/uninstall');

        const ord3Json = await (await request.post('/api/v1/modules/boutique/orders', {
            data: {
                buyer_name: 'Graceful Test',
                buyer_email: 'graceful@kanso-ya.fr',
                payment_method: 'virement',
                delivery_method: 'remise_main_propre',
                items: [{ item_type: 'product', item_id: 3, quantity: 1 }],
            },
        })).json();
        expect(ord3Json.code).toBe(201);

        const payWithoutFact = await (await request.patch(`/api/v1/modules/boutique/orders/${ord3Json.data.order.id}/status`, {
            data: { status: 'payee' },
        })).json();
        expect(payWithoutFact.code).toBe(200);
        expect(payWithoutFact.data.status).toBe('payee');
    });
});
