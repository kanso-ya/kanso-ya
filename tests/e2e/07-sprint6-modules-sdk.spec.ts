/**
 * @file 07-sprint6-modules-sdk.spec.ts
 * @description Suite E2E & API : Sprint 6 - Moteur de Modules & SDK Kanso-ya (Lot 3).
 * - US 6.1 : Table `modules` en BDD, Scanner de dossiers dans `/modules/` et validation stricte des manifests.
 * - US 6.2 : Singleton `Core\Sdk\EventManager` gérant les Actions et les Filtres (zone `#module-hooks-zone` et contrôleurs Core).
 * - US 6.3 : Cycle de vie des modules (install, activate, deactivate, uninstall), isolation des tables SQL (`mod_*`),
 *   interface d'administration sur `sys-console.php`, et Safe Mode automatique garantissant zéro plantage du CMS.
 * - US 6.4 : Module démonstrateur `Boutique` (catalogue, lien sidebar, table `mod_boutique_products`) et module défaillant `CrashTest`.
 */

import { test, expect } from '@playwright/test';

test.describe('SPRINT 6 — Moteur de Modules & SDK Kanso-ya (Lot 3)', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('TC-MOD-01 (US 6.1) : Scanner de modules, validation de manifest.json et contrôle RBAC', async ({ request }) => {
        // 1. Contrôle RBAC : un adhérent ne peut pas lister les modules
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        const resForbidden = await request.get('/api/v1/sys/modules');
        expect(resForbidden.status()).toBe(200);
        const jsonForbidden = await resForbidden.json();
        expect(jsonForbidden.status).toBe('error');
        expect(jsonForbidden.code).toBe(403);

        // 2. Accès autorisé pour le Président
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const resScan = await request.post('/api/v1/sys/modules/scan');
        expect(resScan.status()).toBe(200);
        const jsonScan = await resScan.json();
        expect(jsonScan.status).toBe('success');
        expect(Array.isArray(jsonScan.data)).toBe(true);

        const modules: any[] = jsonScan.data;
        const boutique = modules.find((m) => m.folder_name === 'BoutiqueApi');
        const crashTest = modules.find((m) => m.folder_name === 'CrashTestApi');
        const incompatible = modules.find((m) => m.folder_name === 'IncompatibleModuleApi');

        expect(boutique).toBeDefined();
        expect(boutique.name).toBe('Boutique Officielle du Club');
        expect(String(boutique.version)).toMatch(/^\d+\.\d+\.\d+$/);
        expect(boutique.is_compatible).toBe(true);

        expect(crashTest).toBeDefined();
        expect(crashTest.name).toBe('Module Crash Test (Safe Mode)');

        expect(incompatible).toBeDefined();
        expect(incompatible.is_compatible).toBe(false);
    });

    test('TC-MOD-02 (US 6.1 / US 6.3) : Blocage d\'activation sur module incompatible', async ({ request, page }) => {
        // Tentative d'activation via API
        const resToggle = await request.patch('/api/v1/sys/modules/IncompatibleModuleApi/toggle', {
            data: { enabled: true }
        });
        expect(resToggle.status()).toBe(200);
        const jsonToggle = await resToggle.json();
        expect(jsonToggle.status).toBe('error');
        expect(jsonToggle.code).toBe(422);
        expect(jsonToggle.message).toMatch(/incompatible/i);

        // Connexion président sur le navigateur
        await page.goto('/');
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'president' })
            });
        });

        // Vérification sur sys-console.php
        await page.goto('/sys-console');
        await expect(page.locator('#modules-tbody')).toBeVisible();

        const row = page.locator('#module-row-IncompatibleModuleApi');
        await expect(row).toBeVisible();
        await expect(row).toContainText('Incompatible');

        // Le bouton doit être disabled
        const toggleBtn = row.locator('button[disabled]');
        await expect(toggleBtn).toBeVisible();
        await expect(toggleBtn).toContainText('Incompatible');
    });

    test('TC-MOD-03 (US 6.3 & US 6.4) : Cycle de vie du module Boutique (Install -> Activate -> Boot)', async ({ request, page }) => {
        // 1. Activer le module Boutique via API
        const resToggle = await request.patch('/api/v1/sys/modules/BoutiqueApi/toggle', {
            data: { enabled: true }
        });
        expect(resToggle.status()).toBe(200);
        const jsonToggle = await resToggle.json();
        expect(jsonToggle.status).toBe('success');
        expect(Boolean(jsonToggle.data.is_active)).toBe(true);

        // Connexion président dans la page
        await page.goto('/');
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'president' })
            });
        });

        // 2. Vérifier la page de la boutique /boutique
        await page.goto('/boutique');
        const grid = page.locator('#boutique-products-grid');
        await expect(grid).toBeVisible();
        await expect(page.locator('text=Kimono Club Kanso-ya')).toBeVisible();
        await expect(page.locator('text=Ceinture Noire Broderie Or')).toBeVisible();
        await expect(page.locator('text=Gourde Isotherme')).toBeVisible();

        // 3. Vérifier que le menu apparaît dans la sidebar pour le Président (qui a finances.manage)
        const sidebarLink = page.locator('#module-hooks-zone a[href="/boutique"]');
        await expect(sidebarLink).toBeVisible();
        await expect(sidebarLink).toContainText('Boutique Club');

        // 4. Vérifier que pour un rôle sans finances.manage (ex: Secrétaire), le lien est masqué par le filtre
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'secretaire' })
            });
        });
        await page.goto('/dashboard');
        await expect(page.locator('#module-hooks-zone a[href="/boutique"]')).toHaveCount(0);

        // Rétablir persona president sur la page
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'president' })
            });
        });
    });

    test('TC-MOD-04 (US 6.3 & US 6.4) : Désactivation et Désinstallation avec purge des tables mod_*', async ({ request, page }) => {
        // S'assurer que la session de page est Président
        await page.goto('/');
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'president' })
            });
        });

        // 1. Désactivation du module Boutique
        const resDeactivate = await request.patch('/api/v1/sys/modules/BoutiqueApi/toggle', {
            data: { enabled: false }
        });
        expect(resDeactivate.status()).toBe(200);
        const jsonDeact = await resDeactivate.json();
        expect(jsonDeact.status).toBe('success');
        expect(Boolean(jsonDeact.data.is_active)).toBe(false);

        // Le lien ne doit plus être dans la sidebar
        await page.goto('/dashboard');
        await expect(page.locator('#module-hooks-zone a[href="/boutique"]')).toHaveCount(0);

        // 2. Désinstallation complète (suppression des données mod_boutique_products)
        const resUninstall = await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
        expect(resUninstall.status()).toBe(200);
        const jsonUninstall = await resUninstall.json();
        expect(jsonUninstall.status).toBe('success');

        // Visiter /boutique : la route n'existe plus dans le Core une fois le module désinstallé (HTTP 404)
        const resNotFound = await page.goto('/boutique');
        expect(resNotFound?.status()).toBe(404);
    });

    test('TC-MOD-SAFE-MODE (US 6.3 / CA3) : Résilience automatique en Safe Mode sur module défaillant', async ({ request, page }) => {
        // Session président
        await page.goto('/');
        await page.evaluate(async () => {
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'president' })
            });
        });

        // 1. Activer le module défaillant CrashTestApi
        const resToggle = await request.patch('/api/v1/sys/modules/CrashTestApi/toggle', {
            data: { enabled: true }
        });
        expect(resToggle.status()).toBe(200);

        // 2. Charger une page quelconque du CMS (layout.php déclenche bootActiveModules())
        // Le crash volontaire est levé dans boot(), capturé par le Safe Mode sans jamais faire planter la page (HTTP 200)
        const responsePage = await page.goto('/dashboard');
        expect(responsePage?.status()).toBe(200);
        await expect(page.locator('h1')).toBeVisible();

        // 3. Vérifier que le module a été automatiquement désactivé avec le statut 'crashed'
        const resModules = await request.get('/api/v1/sys/modules');
        expect(resModules.status()).toBe(200);
        const jsonModules = await resModules.json();
        const crashMod = jsonModules.data.find((m: any) => m.folder_name === 'CrashTestApi');

        expect(crashMod).toBeDefined();
        expect(Boolean(crashMod.is_active)).toBe(false);
        expect(crashMod.status).toBe('crashed');
        expect(crashMod.last_error).toContain('Crash volontaire déclenché dans boot()');

        // 4. Vérifier la trace dans sys_logs
        const resLogs = await request.get('/api/v1/sys/logs');
        const jsonLogs = await resLogs.json();
        const crashLog = jsonLogs.data.find((l: any) => l.action === 'MODULE_SAFE_MODE_TRIGGERED');
        expect(crashLog).toBeDefined();
        expect(crashLog.details).toContain('Crash du module CrashTestApi');

        // 5. Vérifier l'affichage sur la console d'administration
        await page.goto('/sys-console');
        const rowCrash = page.locator('#module-row-CrashTestApi');
        await expect(rowCrash).toBeVisible({ timeout: 10000 });
        await expect(rowCrash).toContainText('Crashé (Safe Mode)');
        await expect(rowCrash).toContainText('Crash volontaire déclenché');
    });

    test('TC-EVENT-MANAGER-SDK (US 6.2) : Câblage des événements Core (Member, Registration, CMS)', async ({ request }) => {
        const ts = Date.now();

        // 1. Événement on_member_created : création directe d'un membre
        const resMem = await request.post('/api/v1/members', {
            data: {
                first_name: `EventTest_${ts}`,
                last_name: 'Member',
                birth_date: '2010-06-15',
                email: `event.member.${ts}@kanso-ya.fr`,
                phone: '06 99 88 77 66',
                address: '1 rue des Hooks, 75010 Paris'
            }
        });
        expect(resMem.status()).toBe(200);
        const jsonMem = await resMem.json();
        expect(jsonMem.status).toBe('success');

        // 2. Événement on_article_published : publication d'un article
        const resArt = await request.post('/api/v1/articles', {
            data: {
                title: `Article Hook Test ${ts}`,
                content: '<p>Contenu test pour valider EventManager on_article_published</p>',
                category_id: 1,
                visibility: 'public',
                status: 'published'
            }
        });
        expect(resArt.status()).toBe(200);
        const jsonArt = await resArt.json();
        expect(jsonArt.status).toBe('success');
        expect(jsonArt.data.status).toBe('published');

        // 3. Événement on_member_deleted : suppression du membre
        const memId = jsonMem.data.id;
        const resDel = await request.delete(`/api/v1/members/${memId}`);
        expect(resDel.status()).toBe(200);
        const jsonDel = await resDel.json();
        expect(jsonDel.status).toBe('success');
    });

});
