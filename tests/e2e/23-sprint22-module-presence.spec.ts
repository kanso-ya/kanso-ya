import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const PROJECT_ROOT = process.cwd();
const PUBLIC_PRESENCE_ASSETS = path.join(PROJECT_ROOT, 'public', 'assets', 'modules', 'presence');

test.describe.serial('Sprint 22 (Lot 5) — Module Présences & Suivi Tatami (PresenceApi / PresenceUi)', () => {
    const activatePresenceModule = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/scan');
        const toggleRes = await request.patch('/api/v1/sys/modules/PresenceApi/toggle', {
            data: { enabled: true },
        });
        expect(toggleRes.status()).toBe(200);
        const toggleJson = await toggleRes.json();
        expect(toggleJson.status).toBe('success');
        expect(Boolean(toggleJson.data.is_active)).toBe(true);
    };

    const uninstallPresenceModule = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/PresenceApi/uninstall');
    };

    test.afterAll(async ({ request }) => {
        await uninstallPresenceModule(request);
    });

    test('S22-01 : Cycle de vie SDK v2 — Scan, activation PresenceApi et publication des assets statiques', async ({ request }) => {
        await activatePresenceModule(request);

        expect(fs.existsSync(path.join(PUBLIC_PRESENCE_ASSETS, 'presence.css'))).toBe(true);
        expect(fs.existsSync(path.join(PUBLIC_PRESENCE_ASSETS, 'presence.js'))).toBe(true);

        const cssRes = await request.get('/assets/modules/presence/presence.css');
        expect(cssRes.status()).toBe(200);
        const jsRes = await request.get('/assets/modules/presence/presence.js');
        expect(jsRes.status()).toBe(200);
    });

    test('S22-02 : Persona 1 (Entraîneur) — Feuille d\'appel /presences, pointage idempotent, historique RGPD & onglet /stats', async ({ page, request }) => {
        await activatePresenceModule(request);

        // Connexion Entraîneur
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });

        await page.goto('/presences');
        await expect(page.getByTestId('presence-discipline-select')).toBeVisible();
        await expect(page.getByTestId('presence-date-input')).toBeVisible();

        // 1. Ouvre / crée une feuille d'appel
        await page.getByTestId('presence-date-input').fill('2026-11-21');
        await page.getByTestId('presence-slot-input').fill('18:00-19:30');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/presence/sessions') && r.request().method() === 'POST'),
            page.getByTestId('btn-open-presence-session').click(),
        ]);

        const rosterList = page.getByTestId('presence-roster-list');
        await expect(rosterList).toBeVisible();

        // Récupère 3 pratiquants présents dans le roster affiché
        const memberRows = rosterList.locator('[data-roster-member-id]');
        const count = await memberRows.count();
        expect(count).toBeGreaterThanOrEqual(3);

        const m1 = Number(await memberRows.nth(0).getAttribute('data-roster-member-id'));
        const m2 = Number(await memberRows.nth(1).getAttribute('data-roster-member-id'));
        const m3 = Number(await memberRows.nth(2).getAttribute('data-roster-member-id'));

        // Vérifie l'absence d'adresse email dans le DOM de la feuille d'appel (minimisation RGPD)
        const rosterText = await rosterList.innerText();
        expect(rosterText).not.toContain('@');

        // 2. Pointage initial : Présent / Absent / Excusé
        await page.getByTestId(`presence-btn-present-${m1}`).click();
        await page.getByTestId(`presence-btn-absent-${m2}`).click();
        await page.getByTestId(`presence-btn-excused-${m3}`).click();

        const [markRes1] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/mark') && r.request().method() === 'POST'),
            page.getByTestId('btn-save-presence-roll').click(),
        ]);
        const markJson1 = await markRes1.json();
        expect(markJson1.code).toBe(200);
        expect(markJson1.data.summary.present).toBeGreaterThanOrEqual(1);
        expect(markJson1.data.summary.absent).toBeGreaterThanOrEqual(1);
        expect(markJson1.data.summary.excused).toBeGreaterThanOrEqual(1);

        // 3. Idempotence : réouverture de la même séance et correction m2 -> Présent
        await page.getByTestId(`presence-btn-present-${m2}`).click();
        const [markRes2] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/mark') && r.request().method() === 'POST'),
            page.getByTestId('btn-save-presence-roll').click(),
        ]);
        const markJson2 = await markRes2.json();
        expect(markJson2.code).toBe(200);

        // 4. Historique individuel d'assiduité (modale)
        await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/members/${m2}/history`)),
            page.getByTestId(`btn-member-history-${m2}`).click(),
        ]);
        await expect(page.getByTestId('presence-history-modal')).toBeVisible();
        await expect(page.getByTestId('presence-history-rate')).toHaveText('100%');

        // 5. Onglet analytique « Assiduité » injecté dans /stats via ui_tabs
        await page.goto('/stats');
        const presenceTab = page.getByTestId('stats-tab-presence');
        await expect(presenceTab).toBeVisible();
        await presenceTab.click();
        await expect(page.getByTestId('stats-panel-presence')).toBeVisible();
        await expect(page.getByTestId('presence-stats-table')).toBeVisible();
    });

    test('S22-03 : Persona 2 — Contrôles RBAC/RGPD (403) et désinstallation propre (404 + purge assets + disparition onglet /stats)', async ({ page, request }) => {
        await activatePresenceModule(request);

        // 1. Rédacteur (sans stats.view) bloqué en 403 sur les endpoints d'appel
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'redacteur' } });
        const redSessions = await (await request.get('/api/v1/modules/presence/sessions')).json();
        expect(redSessions.code).toBe(403);

        // 2. Adhérent autorisé sur son enfant rattaché (#1 -> 200) mais bloqué en 403 sur un adhérent tiers (#19 -> 403)
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        const adhOwnChildHist = await (await request.get('/api/v1/modules/presence/members/1/history')).json();
        expect(adhOwnChildHist.code).toBe(200);
        const adhOtherHist = await (await request.get('/api/v1/modules/presence/members/19/history')).json();
        expect(adhOtherHist.code).toBe(403);

        // 3. Désinstallation propre par le Superadmin
        await uninstallPresenceModule(request);

        // Assets purgés
        expect(fs.existsSync(PUBLIC_PRESENCE_ASSETS)).toBe(false);

        // Route UI /presences -> HTTP 404 et API -> JSend 404
        const pageRes = await request.get('/presences');
        expect(pageRes.status()).toBe(404);

        const apiRes = await (await request.get('/api/v1/modules/presence/sessions')).json();
        expect(apiRes.code).toBe(404);

        // Onglet Assiduité disparu de /stats
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/stats');
        await expect(page.getByTestId('stats-tab-presence')).toHaveCount(0);
    });
});
