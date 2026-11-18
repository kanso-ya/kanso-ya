import { test, expect } from '@playwright/test';

test.describe('Sprint 14 - Tableaux Compacts, Onglets Fonctionnels & Épuration Superadmin', () => {

    test('S14-01 : Tableaux Adhérents & Cotisations compacts (largeur standard max-w-6xl, 0 scroll horizontal Desktop, actions sur 2 lignes avec infobulles)', async ({ page, isMobile }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });

        // 1. Page /members
        await page.goto('/members');
        const membersTable = page.locator('[data-testid="members-table"]');
        await expect(membersTable).toBeVisible();
        const firstMemberGrid = page.locator('[data-testid="member-actions-grid"]').first();
        await expect(firstMemberGrid).toBeVisible({ timeout: 10000 });

        // Vérifier que les boutons d'action possèdent tous une infobulle (title)
        const memberBtns = firstMemberGrid.locator('button');
        const memberBtnCount = await memberBtns.count();
        expect(memberBtnCount).toBeGreaterThanOrEqual(4);
        for (let i = 0; i < memberBtnCount; i++) {
            const titleAttr = await memberBtns.nth(i).getAttribute('title');
            expect(titleAttr && titleAttr.trim().length).toBeGreaterThan(3);
        }

        // Vérifier l'absence de débordement horizontal sur Desktop (1280px) et la largeur max-w-6xl
        if (!isMobile) {
            const membersOverflow = await page.evaluate(() => {
                const table = document.querySelector('#members-table') as HTMLElement | null;
                const container = table?.parentElement as HTMLElement | null;
                const mainInner = document.querySelector('#main-workspace > div') as HTMLElement | null;
                return {
                    tableWidth: table?.scrollWidth ?? 0,
                    containerWidth: container?.clientWidth ?? 0,
                    hasMaxW6xl: mainInner?.classList.contains('max-w-6xl') ?? false
                };
            });
            expect(membersOverflow.hasMaxW6xl).toBe(true);
            expect(membersOverflow.tableWidth).toBeLessThanOrEqual(membersOverflow.containerWidth + 2);

            // Vérifier que les actions tiennent sur 2 lignes compactes (hauteur <= 72px)
            const gridBox = await firstMemberGrid.boundingBox();
            expect(gridBox).not.toBeNull();
            expect(gridBox!.height).toBeLessThanOrEqual(72);
            expect(gridBox!.width).toBeLessThanOrEqual(112);
        }

        // 2. Page /registrations
        await page.goto('/registrations');
        const regTable = page.locator('[data-testid="registrations-table"]');
        await expect(regTable).toBeVisible();
        const firstRegGrid = page.locator('[data-testid="registration-actions-grid"]').first();
        await expect(firstRegGrid).toBeVisible({ timeout: 10000 });

        const regBtns = firstRegGrid.locator('button');
        const regBtnCount = await regBtns.count();
        expect(regBtnCount).toBeGreaterThanOrEqual(3);
        for (let i = 0; i < regBtnCount; i++) {
            const titleAttr = await regBtns.nth(i).getAttribute('title');
            expect(titleAttr && titleAttr.trim().length).toBeGreaterThan(3);
        }

        if (!isMobile) {
            const regOverflow = await page.evaluate(() => {
                const table = document.querySelector('#registrations-table') as HTMLElement | null;
                const container = table?.parentElement as HTMLElement | null;
                return {
                    tableWidth: table?.scrollWidth ?? 0,
                    containerWidth: container?.clientWidth ?? 0
                };
            });
            expect(regOverflow.tableWidth).toBeLessThanOrEqual(regOverflow.containerWidth + 2);

            const gridBox = await firstRegGrid.boundingBox();
            expect(gridBox).not.toBeNull();
            expect(gridBox!.height).toBeLessThanOrEqual(72);
            expect(gridBox!.width).toBeLessThanOrEqual(112);
        }
    });

    test('S14-02 : Onglets fonctionnels sur /stats (1 seul panneau visible à la fois) et masquage Finances pour Entraîneur', async ({ page }) => {
        // 1. En tant que Président : 4 onglets (Vue Générale, Finances, Démographie & Occupation, Liste d'Appel)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/stats');

        const panelKpis = page.locator('[data-testid="stats-panel-kpis"]');
        const panelFinances = page.locator('[data-testid="stats-panel-finances"]');
        const panelOccupancy = page.locator('[data-testid="stats-panel-occupancy"]');
        const panelAttendance = page.locator('[data-testid="stats-panel-attendance"]');

        // Par défaut, seul Vue Générale est visible
        await expect(panelKpis).toBeVisible();
        await expect(panelFinances).toBeHidden();
        await expect(panelOccupancy).toBeHidden();
        await expect(panelAttendance).toBeHidden();

        // Clic sur Finances
        await page.locator('[data-testid="stats-tab-finances"]').click();
        await expect(panelFinances).toBeVisible();
        await expect(panelKpis).toBeHidden();
        await expect(panelOccupancy).toBeHidden();
        await expect(panelAttendance).toBeHidden();

        // Clic sur Démographie & Occupation -> 1 seule discipline visible à la fois
        await page.locator('[data-testid="stats-tab-occupancy"]').click();
        await expect(panelOccupancy).toBeVisible();
        await expect(panelFinances).toBeHidden();

        const discTabs = page.locator('#stats-discipline-tabs [data-testid="discipline-tab-item"]');
        const discCount = await discTabs.count();
        expect(discCount).toBeGreaterThanOrEqual(2);

        // Vérifier que 1 seul panneau discipline est visible
        const visibleStatsCardsInitial = await page.locator('#discipline-occupancy-list .stats-disc-card:visible').count();
        expect(visibleStatsCardsInitial).toBe(1);

        // Cliquer sur le 2ème onglet discipline
        const secondDiscId = await discTabs.nth(1).getAttribute('data-discipline-id');
        await discTabs.nth(1).click();
        await expect(page.locator(`#discipline-occupancy-list [data-testid="discipline-panel-${secondDiscId}"]`)).toBeVisible();
        const visibleStatsCardsAfter = await page.locator('#discipline-occupancy-list .stats-disc-card:visible').count();
        expect(visibleStatsCardsAfter).toBe(1);

        // Clic sur Liste d'Appel Tatami
        await page.locator('[data-testid="stats-tab-attendance"]').click();
        await expect(panelAttendance).toBeVisible();
        await expect(panelOccupancy).toBeHidden();

        // 2. En tant qu'Entraîneur : l'onglet Finances et son panneau sont absents du DOM (RGPD)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });
        await page.goto('/stats');
        await expect(page.locator('[data-testid="stats-tab-finances"]')).toHaveCount(0);
        await expect(page.locator('[data-testid="stats-panel-finances"]')).toHaveCount(0);
        await expect(page.locator('#rgpd-teacher-protection')).toBeVisible();
    });

    test('S14-03 : Affichage d\'une seule discipline à la fois par onglets sur /horaires et /disciplines', async ({ page }) => {
        // 1. Page publique /horaires
        await page.goto('/horaires');
        const horairesTabs = page.locator('#horaires-discipline-tabs [data-testid="discipline-tab-item"]');
        const hCount = await horairesTabs.count();
        expect(hCount).toBeGreaterThanOrEqual(2);

        expect(await page.locator('#schedules-grid .horaires-disc-card:visible').count()).toBe(1);
        const secondId = await horairesTabs.nth(1).getAttribute('data-discipline-id');
        await horairesTabs.nth(1).click();
        await expect(page.locator(`#schedules-grid [data-testid="discipline-panel-${secondId}"]`)).toBeVisible();
        expect(await page.locator('#schedules-grid .horaires-disc-card:visible').count()).toBe(1);

        // 2. Page /disciplines (Président)
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/disciplines');
        const discTabs = page.locator('#disciplines-tabs-bar [data-testid="discipline-tab-item"]');
        await expect(discTabs.first()).toBeVisible({ timeout: 10000 });
        expect(await discTabs.count()).toBeGreaterThanOrEqual(2);
        expect(await page.locator('#disciplines-list > div[role="tabpanel"]').count()).toBe(1);

        const secondManageId = await discTabs.nth(1).getAttribute('data-discipline-id');
        await discTabs.nth(1).click();
        await expect(page.locator(`#disciplines-list [data-testid="discipline-panel-${secondManageId}"]`)).toBeVisible();
        expect(await page.locator('#disciplines-list > div[role="tabpanel"]').count()).toBe(1);
    });

    test('S14-04 : Épuration Superadmin (aucune sous-nav horizontale), Vue d\'ensemble synthétique & Mode Démo, et 3 onglets fonctionnels sur /sys-smtp', async ({ page }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

        // 1. /sys-console : absence de sys-nav, absence des formulaires doublonnés Branding & SMTP, présence des 4 cartes synthétiques
        await page.goto('/sys-console');
        await expect(page.locator('nav[aria-label="Sous-navigation Administration"]')).toHaveCount(0);
        await expect(page.locator('#branding-form')).toHaveCount(0);
        await expect(page.locator('#smtp-settings-form')).toHaveCount(0);

        await expect(page.locator('[data-testid="sys-overview-cards"]')).toBeVisible();
        await expect(page.locator('[data-testid="sys-demo-mode-badge"]')).toBeVisible();
        await expect(page.locator('[data-testid="metric-active-modules"]')).toBeVisible();
        await expect(page.locator('[data-testid="sys-recent-logins"]')).toBeVisible();

        // Test du toggle Mode Démo (activation puis restauration à Inactif pour laisser la BDD propre)
        const demoBadge = page.locator('[data-testid="sys-demo-mode-badge"]');
        const demoToggle = page.locator('[data-testid="sys-demo-mode-toggle"]');
        const initialText = (await demoBadge.textContent())?.trim() || 'Inactif';

        await demoToggle.click();
        await expect(demoBadge).not.toHaveText(initialText, { timeout: 5000 });
        await expect(page.locator('#toast-container')).toContainText(/Mode Démo/i);

        // Restaurer l'état initial (Inactif)
        if ((await demoBadge.textContent())?.trim() === 'Actif') {
            await demoToggle.click();
            await expect(demoBadge).toHaveText('Inactif', { timeout: 5000 });
        }

        // 2. Vérification absence de sous-nav horizontale sur /sys-branding, /sys-modules, /sys-logs
        for (const path of ['/sys-branding', '/sys-modules', '/sys-logs']) {
            await page.goto(path);
            await expect(page.locator('nav[aria-label="Sous-navigation Administration"]')).toHaveCount(0);
            await expect(page.locator(`#sidebar-nav a[href="${path}"]`)).toHaveClass(/bg-red-700/);
        }

        // 3. /sys-smtp : 3 onglets distincts et fonctionnels (1 seul panneau visible à la fois)
        await page.goto('/sys-smtp');
        await expect(page.locator('nav[aria-label="Sous-navigation Administration"]')).toHaveCount(0);
        await expect(page.locator('#sidebar-nav a[href="/sys-smtp"]')).toHaveClass(/bg-red-700/);

        const panelConfig = page.locator('[data-testid="smtp-panel-config"]');
        const panelTemplates = page.locator('[data-testid="smtp-panel-templates"]');
        const panelHistory = page.locator('[data-testid="smtp-panel-history"]');

        await expect(panelConfig).toBeVisible();
        await expect(panelTemplates).toBeHidden();
        await expect(panelHistory).toBeHidden();

        await page.locator('[data-testid="smtp-tab-templates"]').click();
        await expect(panelTemplates).toBeVisible();
        await expect(panelConfig).toBeHidden();
        await expect(panelHistory).toBeHidden();

        await page.locator('[data-testid="smtp-tab-history"]').click();
        await expect(panelHistory).toBeVisible();
        await expect(panelConfig).toBeHidden();
        await expect(panelTemplates).toBeHidden();
    });
});
