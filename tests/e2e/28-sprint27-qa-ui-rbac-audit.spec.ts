/**
 * @file 28-sprint27-qa-ui-rbac-audit.spec.ts
 * @description SPRINT 27 (Lot 7) — QA Intégrale 100 % UI (Boîte Noire), Matrice RBAC (8 rôles),
 * Audit de Propreté des Vues à Onglets & Parcours Bout-en-Bout (Desktop & Mobile).
 * Règle absolue : ZERO switch-persona, ZERO raccourci API d'authentification — 100 % formulaires & clics UI.
 */

import { test, expect, type Page } from '@playwright/test';

/**
 * Ouvre le tiroir latéral si l'on est sur un viewport mobile (< 1024px) où la sidebar est cachée.
 */
async function ensureSidebarVisible(page: Page): Promise<void> {
    const mobileBtn = page.locator('#mobile-menu-btn');
    if (await mobileBtn.isVisible()) {
        const sidebar = page.locator('#sidebar');
        const cls = (await sidebar.getAttribute('class')) ?? '';
        if (cls.includes('-translate-x-full')) {
            await mobileBtn.click();
            await page.waitForTimeout(250);
        }
    }
}

/**
 * Ferme le tiroir latéral mobile s'il est ouvert.
 */
async function closeMobileSidebarIfOpen(page: Page): Promise<void> {
    const mobileBtn = page.locator('#mobile-menu-btn');
    if (await mobileBtn.isVisible()) {
        const cls = (await page.locator('#sidebar').getAttribute('class')) ?? '';
        if (!cls.includes('-translate-x-full')) {
            await mobileBtn.click();
            await page.waitForTimeout(250);
        }
    }
}

/**
 * Connexion 100 % UI via /login (formulaire standard).
 */
async function uiLogin(page: Page, email: string, password: string = 'password123'): Promise<void> {
    await page.goto('/login');
    await expect(page.locator('#login-form')).toBeVisible();
    await page.locator('#login-identifier').fill(email);
    await page.locator('#login-password').fill(password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }),
        page.locator('#btn-login-submit').click(),
    ]);
    await page.waitForLoadState('networkidle');
}

/**
 * Connexion 100 % UI Superadmin via /sys-admin-xyz.
 */
async function uiSuperadminLogin(page: Page, username: string = 'superadmin', password: string = 'password123'): Promise<void> {
    await page.goto('/sys-admin-xyz');
    await expect(page.locator('#sys-login-form')).toBeVisible();
    await page.locator('#sys-login-form input[name="username"]').fill(username);
    await page.locator('#sys-login-form input[name="password"]').fill(password);
    await Promise.all([
        page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }),
        page.locator('#btn-sys-submit').click(),
    ]);
    await page.waitForLoadState('networkidle');
}

/**
 * Déconnexion 100 % UI via le bouton #btn-logout dans la sidebar.
 */
async function uiLogout(page: Page): Promise<void> {
    await ensureSidebarVisible(page);
    const logoutBtn = page.locator('#btn-logout');
    if (await logoutBtn.isVisible()) {
        await Promise.all([
            page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 15_000 }),
            logoutBtn.click(),
        ]);
        await page.waitForLoadState('networkidle');
    }
}

/**
 * Retourne tous les hrefs présents dans #sidebar-nav.
 */
async function getSidebarHrefs(page: Page): Promise<string[]> {
    await ensureSidebarVisible(page);
    const hrefs = await page.locator('#sidebar-nav a[href]').evaluateAll((els) =>
        els.map((el) => el.getAttribute('href') || '').filter(Boolean)
    );
    await closeMobileSidebarIfOpen(page);
    return hrefs;
}

/**
 * Vérifie l'absence de débordement horizontal sur la page courante (tolérance 2px sub-pixel).
 */
async function assertNoHorizontalOverflow(page: Page, contextLabel: string): Promise<void> {
    const metrics = await page.evaluate(() => ({
        scrollWidth: document.documentElement.scrollWidth,
        clientWidth: document.documentElement.clientWidth,
    }));
    expect(
        metrics.scrollWidth,
        `Débordement horizontal détecté sur ${contextLabel} (scrollWidth=${metrics.scrollWidth} > clientWidth=${metrics.clientWidth})`
    ).toBeLessThanOrEqual(metrics.clientWidth + 2);
}

test.describe.serial('Sprint 27 (Lot 7) — QA Intégrale 100 % UI, Matrice RBAC & Audit Vues', () => {
    test('S27-01 : Matrice RBAC complète (8 rôles) en boîte noire 100 % UI', async ({ page }) => {
        // 1. VISITEUR ANONYME
        await page.goto('/');
        await expect(page.locator('#app-header')).toBeVisible();
        await ensureSidebarVisible(page);
        await expect(page.locator('#btn-sidebar-login')).toBeVisible();
        await closeMobileSidebarIfOpen(page);

        // Accès direct à une page protégée -> bloqué (401/403) ou redirigé vers /login
        const resVisitorMembers = await page.goto('/members');
        const stVisitor = resVisitorMembers?.status() ?? 200;
        const visitorBlocked = page.url().includes('/login') || stVisitor === 401 || stVisitor === 403 || (await page.locator('body').textContent())?.includes('connecter');
        expect(visitorBlocked, `Un visiteur ne doit pas accéder à /members (status=${stVisitor})`).toBe(true);

        // 2. ADHÉRENT / TUTEUR (tutorA@mail.com — accès Mon profil + Mes fiches /members uniquement)
        await uiLogin(page, 'tutorA@mail.com');
        const adhLinks = await getSidebarHrefs(page);
        expect(adhLinks).toContain('/dashboard');
        expect(adhLinks).not.toContain('/stats');
        expect(adhLinks).not.toContain('/cms');
        expect(adhLinks).not.toContain('/registrations');
        expect(adhLinks).not.toContain('/roles');
        expect(adhLinks).not.toContain('/sys-console');
        await uiLogout(page);

        // 3. ENTRAÎNEUR (tutorB@mail.com)
        await uiLogin(page, 'tutorB@mail.com');
        const coachLinks = await getSidebarHrefs(page);
        expect(coachLinks).toContain('/members');
        expect(coachLinks).toContain('/stats');
        expect(coachLinks).toContain('/cms');
        expect(coachLinks).not.toContain('/registrations');
        expect(coachLinks).not.toContain('/roles');
        expect(coachLinks).not.toContain('/sys-console');
        await uiLogout(page);

        // 4. RÉDACTEUR (redacteur@kanso-ya.local)
        await uiLogin(page, 'redacteur@kanso-ya.local');
        const redLinks = await getSidebarHrefs(page);
        expect(redLinks).toContain('/cms');
        expect(redLinks).not.toContain('/members');
        expect(redLinks).not.toContain('/stats');
        expect(redLinks).not.toContain('/registrations');
        expect(redLinks).not.toContain('/roles');
        await uiLogout(page);

        // 5. SECRÉTAIRE (secretaire@kanso-ya.local)
        await uiLogin(page, 'secretaire@kanso-ya.local');
        const secLinks = await getSidebarHrefs(page);
        expect(secLinks).toContain('/members');
        expect(secLinks).toContain('/cms');
        expect(secLinks).toContain('/stats');
        expect(secLinks).not.toContain('/registrations');
        expect(secLinks).not.toContain('/roles');
        await uiLogout(page);

        // 6. TRÉSORIER (tresorier@kanso-ya.local)
        await uiLogin(page, 'tresorier@kanso-ya.local');
        const tresLinks = await getSidebarHrefs(page);
        expect(tresLinks).toContain('/members');
        expect(tresLinks).toContain('/registrations');
        expect(tresLinks).not.toContain('/cms');
        expect(tresLinks).not.toContain('/roles');
        expect(tresLinks).not.toContain('/sys-console');
        await uiLogout(page);

        // 7. PRÉSIDENT (president@kanso-ya.local)
        await uiLogin(page, 'president@kanso-ya.local');
        const presLinks = await getSidebarHrefs(page);
        expect(presLinks).toContain('/members');
        expect(presLinks).toContain('/registrations');
        expect(presLinks).toContain('/cms');
        expect(presLinks).toContain('/stats');
        expect(presLinks).toContain('/disciplines');
        expect(presLinks).toContain('/roles');
        expect(presLinks).toContain('/settings');
        expect(presLinks).not.toContain('/sys-console');
        await uiLogout(page);

        // 8. SUPERADMIN (via /sys-admin-xyz)
        await uiSuperadminLogin(page);
        const sysLinks = await getSidebarHrefs(page);
        expect(sysLinks).toContain('/sys-console');
        expect(sysLinks).toContain('/sys-branding');
        expect(sysLinks).toContain('/sys-smtp');
        expect(sysLinks).toContain('/sys-modules');
        expect(sysLinks).toContain('/sys-logs');
        expect(sysLinks).not.toContain('/cms');
        await uiLogout(page);
    });

    test('S27-02 : Audit propreté des vues à onglets, zéro erreur JS/réseau, zéro lien mort & zéro débordement mobile', async ({ page }) => {
        const consoleErrors: string[] = [];
        const pageErrors: string[] = [];
        const failedRequests: string[] = [];

        page.on('console', (msg) => {
            if (msg.type() === 'error') {
                consoleErrors.push(msg.text());
            }
        });
        page.on('pageerror', (err) => {
            pageErrors.push(err.message);
        });
        page.on('response', (res) => {
            if (res.status() >= 400 && !res.url().includes('favicon')) {
                failedRequests.push(`${res.status()} ${res.url()}`);
            }
        });

        // 1. Audit Accueil public & liens internes
        await page.goto('/');
        await page.waitForLoadState('networkidle');
        await assertNoHorizontalOverflow(page, '/');

        const publicLinks = await page.locator('a[href^="/"]').evaluateAll((els) =>
            Array.from(new Set(els.map((e) => e.getAttribute('href') || '').filter((h) => h.startsWith('/') && !h.startsWith('//'))))
        );
        for (const href of publicLinks.slice(0, 10)) {
            const resp = await page.request.get(href);
            expect(resp.status(), `Lien interne cassé sur / : ${href}`).toBeLessThan(400);
        }

        // 2. Superadmin : audit /sys-smtp (3 onglets exclusifs) & activation BoutiqueApi + FacturationApi via UI /sys-modules
        await uiSuperadminLogin(page);
        await page.goto('/sys-smtp');
        await page.waitForLoadState('networkidle');
        await assertNoHorizontalOverflow(page, '/sys-smtp');

        for (const tabId of ['config', 'templates', 'history']) {
            await page.getByTestId(`smtp-tab-${tabId}`).click();
            const visiblePanels = await page.locator('.smtp-tab-panel:not(.hidden)').count();
            expect(visiblePanels, `/sys-smtp onglet ${tabId} doit afficher exactement 1 panneau`).toBe(1);
            await expect(page.getByTestId(`smtp-panel-${tabId}`)).toBeVisible();
            await assertNoHorizontalOverflow(page, `/sys-smtp#${tabId}`);
        }

        // Activer BoutiqueApi et FacturationApi via l'UI /sys-modules
        await page.goto('/sys-modules');
        await page.waitForLoadState('networkidle');
        const scanBtn = page.locator('#btn-scan-modules');
        if (await scanBtn.isVisible()) {
            await Promise.all([
                page.waitForResponse((r) => r.url().includes('/api/v1/sys/modules/scan')),
                scanBtn.click(),
            ]);
            await page.waitForTimeout(300);
        }
        for (const modFolder of ['BoutiqueApi', 'FacturationApi']) {
            const toggleBtn = page.locator(`button[data-action="toggle-module"][data-folder="${modFolder}"]`);
            await expect(toggleBtn).toBeVisible();
            const isActive = (await toggleBtn.getAttribute('data-active')) === '1';
            if (!isActive) {
                await Promise.all([
                    page.waitForResponse((r) => r.url().includes(`/api/v1/sys/modules/${modFolder}/toggle`)),
                    toggleBtn.click(),
                ]);
                await page.waitForTimeout(350);
            }
        }
        await uiLogout(page);

        // 3. Président : audit /dashboard, /stats, /disciplines, /boutique, /factures
        await uiLogin(page, 'president@kanso-ya.local');

        // /dashboard (2 onglets)
        await page.goto('/dashboard');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#tab-panel-profile-info')).toBeVisible();
        await expect(page.locator('#tab-panel-profile-security')).toBeHidden();
        await assertNoHorizontalOverflow(page, '/dashboard#info');
        await page.locator('#tab-btn-profile-security').click();
        await expect(page.locator('#tab-panel-profile-security')).toBeVisible();
        await expect(page.locator('#tab-panel-profile-info')).toBeHidden();
        await assertNoHorizontalOverflow(page, '/dashboard#security');

        // /stats (tous les onglets)
        await page.goto('/stats');
        await page.waitForLoadState('networkidle');
        const statsTabs = page.locator('#stats-tabs-bar [role="tab"]');
        const statsTabCount = await statsTabs.count();
        expect(statsTabCount).toBeGreaterThanOrEqual(4);
        for (let i = 0; i < statsTabCount; i++) {
            await statsTabs.nth(i).click();
            const visibleCount = await page.locator('.stats-tab-panel:not(.hidden)').count();
            expect(visibleCount, `/stats onglet #${i} doit afficher exactement 1 panneau`).toBe(1);
            await assertNoHorizontalOverflow(page, `/stats#tab-${i}`);
        }

        // /disciplines (onglets par discipline)
        await page.goto('/disciplines');
        await page.waitForLoadState('networkidle');
        const discTabs = page.locator('#disciplines-tabs-bar [role="tab"]');
        const discCount = await discTabs.count();
        expect(discCount).toBeGreaterThanOrEqual(1);
        for (let i = 0; i < discCount; i++) {
            await discTabs.nth(i).click();
            await assertNoHorizontalOverflow(page, `/disciplines#tab-${i}`);
        }

        // /boutique (3 onglets : catalog, orders, admin)
        await page.goto('/boutique');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('#boutique-section-catalog')).toBeVisible();
        await expect(page.locator('#boutique-section-orders')).toBeHidden();
        await expect(page.locator('#boutique-admin-panel')).toBeHidden();
        await assertNoHorizontalOverflow(page, '/boutique#catalog');

        await page.getByTestId('boutique-tab-orders').click();
        await expect(page.locator('#boutique-section-orders')).toBeVisible();
        await expect(page.locator('#boutique-section-catalog')).toBeHidden();
        await expect(page.locator('#boutique-admin-panel')).toBeHidden();
        await assertNoHorizontalOverflow(page, '/boutique#orders');

        await page.getByTestId('boutique-tab-admin').click();
        await expect(page.locator('#boutique-admin-panel')).toBeVisible();
        await expect(page.locator('#boutique-section-catalog')).toBeHidden();
        await expect(page.locator('#boutique-section-orders')).toBeHidden();
        await expect(page.getByTestId('setting-pay-online-disabled')).toBeDisabled();
        await expect(page.getByTestId('boutique-settings-form')).toContainText('Indisponible pour le moment');
        await assertNoHorizontalOverflow(page, '/boutique#admin');

        // /factures
        await page.goto('/factures');
        await page.waitForLoadState('networkidle');
        await assertNoHorizontalOverflow(page, '/factures');

        await uiLogout(page);

        expect(consoleErrors, `Erreurs console JS détectées : ${consoleErrors.join(' | ')}`).toEqual([]);
        expect(pageErrors, `Exceptions JS non gérées : ${pageErrors.join(' | ')}`).toEqual([]);
        expect(failedRequests, `Requêtes HTTP >= 400 inattendues : ${failedRequests.join(' | ')}`).toEqual([]);
    });

    test('S27-03 : Parcours métier bout-en-bout 100 % UI (Public -> Adhérent Boutique -> Bureau CRM/Suivi)', async ({ page }) => {
        // 1. Navigation publique
        await page.goto('/horaires');
        await page.waitForLoadState('networkidle');
        await page.goto('/register');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('form').first()).toBeVisible();

        // 2. Adhérent passe une commande Boutique 100 % UI
        await uiLogin(page, 'tutorA@mail.com');
        await page.goto('/boutique');
        await page.waitForLoadState('networkidle');
        await expect(page.getByTestId('boutique-tab-admin')).toHaveCount(0);

        await page.getByTestId('product-size-select-1').selectOption('160cm');
        await page.getByTestId('btn-add-to-cart-product-1').click();
        await page.getByTestId('cart-payment-select').selectOption('cheque');
        await page.getByTestId('cart-delivery-select').selectOption('retrait_dojo');

        const [orderRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/boutique/orders') && r.request().method() === 'POST'),
            page.getByTestId('btn-submit-order').click(),
        ]);
        const orderJson = await orderRes.json();
        expect(orderJson.code).toBe(201);
        const orderId: number = Number(orderJson.data.order.id);
        const orderNumber: string = orderJson.data.order.order_number;
        await expect(page.getByTestId('cart-order-confirmation')).toContainText(orderNumber);

        await page.getByTestId('boutique-tab-orders').click();
        await expect(page.getByTestId('boutique-orders-table')).toContainText(orderNumber);
        await uiLogout(page);

        // 3. Président vérifie la commande dans /boutique, teste l'annulation UI (restaure le stock pour les autres navigateurs) et consulte /members + /registrations
        await uiLogin(page, 'president@kanso-ya.local');
        await page.goto('/boutique');
        await page.getByTestId('boutique-tab-orders').click();
        await expect(page.getByTestId('boutique-orders-table')).toContainText(orderNumber);

        await page.getByTestId(`order-status-select-${orderId}`).selectOption('annulee');
        const [cancelRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/api/v1/modules/boutique/orders/${orderId}/status`) && r.request().method() === 'PATCH'),
            page.getByTestId(`btn-update-order-status-${orderId}`).click(),
        ]);
        expect((await cancelRes.json()).code).toBe(200);
        await expect(page.getByTestId(`order-status-badge-${orderId}`)).toContainText('Annulée');

        await page.goto('/members');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('table').first()).toBeVisible();

        await page.goto('/registrations');
        await page.waitForLoadState('networkidle');
        await expect(page.locator('table').first()).toBeVisible();
        await uiLogout(page);
    });
});
