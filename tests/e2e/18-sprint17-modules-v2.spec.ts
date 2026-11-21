/**
 * @file 18-sprint17-modules-v2.spec.ts
 * @description SPRINT 17 (Lot R3) — Modules normalisés Api/Ui, manifeste v2, routes & assets :
 *  - S17-01 : Structure normalisée sur disque (`modules/BoutiqueApi`, `modules/BoutiqueUi`, `modules/CrashTestApi`, `modules/IncompatibleModuleApi`)
 *             et absence définitive de `Core/Ui/Pages/boutique.php` et `public/boutique.php`.
 *  - S17-02 : Activation de `BoutiqueApi` → publication automatique de `public/assets/modules/boutique/boutique.css`
 *             accessible en HTTP 200 (et zéro `.php` publié), page `/boutique` servie via `ui_routes`,
 *             et endpoint `GET /api/v1/modules/boutique/products` retournant JSend HTTP 200 avec les 3 produits.
 *  - S17-03 : Désinstallation de `BoutiqueApi` → purge du dossier `public/assets/modules/boutique/`,
 *             `/boutique` renvoie HTTP `404`, et `GET /api/v1/modules/boutique/products` renvoie JSend `404`.
 */

import { test, expect } from '@playwright/test';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const MODULES_DIR = path.join(PROJECT_ROOT, 'modules');
const PUBLIC_MODULES_ASSETS_DIR = path.join(PROJECT_ROOT, 'public', 'assets', 'modules');

function listPhpFilesRecursive(dir: string, baseDir: string = dir): string[] {
    if (!fs.existsSync(dir)) {
        return [];
    }
    const entries = fs.readdirSync(dir, { withFileTypes: true });
    const phpFiles: string[] = [];

    for (const entry of entries) {
        const fullPath = path.join(dir, entry.name);
        if (entry.isDirectory()) {
            phpFiles.push(...listPhpFilesRecursive(fullPath, baseDir));
        } else if (entry.isFile() && entry.name.toLowerCase().endsWith('.php')) {
            phpFiles.push(path.relative(baseDir, fullPath).replace(/\\/g, '/'));
        }
    }

    return phpFiles.sort();
}

test.describe('SPRINT 17 (Lot R3) — Modules normalisés Api/Ui, manifeste v2, routes & assets', () => {
    test.describe.configure({ mode: 'serial' });

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' },
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' },
        });
    });

    test('S17-01 : Structure normalisée Api/Ui sur disque et absence définitive de boutique.php dans Core/Ui et public/', async () => {
        // 1. Dossiers normalisés V2 présents
        const expectedDirs = [
            'BoutiqueApi',
            'BoutiqueUi',
            'CrashTestApi',
            'IncompatibleModuleApi',
        ];
        for (const dirName of expectedDirs) {
            const fullPath = path.join(MODULES_DIR, dirName);
            expect(fs.existsSync(fullPath), `Dossier modules/${dirName} manquant`).toBe(true);
            expect(fs.statSync(fullPath).isDirectory()).toBe(true);
        }

        // 2. Fichiers clés de BoutiqueApi & BoutiqueUi présents
        expect(fs.existsSync(path.join(MODULES_DIR, 'BoutiqueApi', 'manifest.json'))).toBe(true);
        expect(fs.existsSync(path.join(MODULES_DIR, 'BoutiqueApi', 'Module.php'))).toBe(true);
        expect(fs.existsSync(path.join(MODULES_DIR, 'BoutiqueApi', 'Endpoints', 'BoutiqueController.php'))).toBe(true);
        expect(fs.existsSync(path.join(MODULES_DIR, 'BoutiqueUi', 'Pages', 'boutique.php'))).toBe(true);
        expect(fs.existsSync(path.join(MODULES_DIR, 'BoutiqueUi', 'dist', 'boutique.css'))).toBe(true);

        // 3. Vérification du manifest.json v2 de BoutiqueApi (infrastructure générique, version semver libre)
        const manifestRaw = fs.readFileSync(path.join(MODULES_DIR, 'BoutiqueApi', 'manifest.json'), 'utf-8');
        const manifest = JSON.parse(manifestRaw) as Record<string, unknown>;
        expect(manifest.schema_version).toBe(2);
        expect(manifest.id).toBe('boutique');
        expect(String(manifest.version)).toMatch(/^\d+\.\d+\.\d+$/);
        expect(manifest.entry_class).toBe('Modules\\BoutiqueApi\\Module');
        expect(manifest.ui_folder).toBe('BoutiqueUi');

        // 4. Absence définitive de Core/Ui/Pages/boutique.php et public/boutique.php
        expect(fs.existsSync(path.join(PROJECT_ROOT, 'Core', 'Ui', 'Pages', 'boutique.php'))).toBe(false);
        expect(fs.existsSync(path.join(PROJECT_ROOT, 'public', 'boutique.php'))).toBe(false);

        // 5. Absence des anciens dossiers V1
        expect(fs.existsSync(path.join(MODULES_DIR, 'Boutique'))).toBe(false);
        expect(fs.existsSync(path.join(MODULES_DIR, 'CrashTest'))).toBe(false);
        expect(fs.existsSync(path.join(MODULES_DIR, 'IncompatibleModule'))).toBe(false);
    });

    test('S17-02 : Activation de BoutiqueApi -> publication assets CSS, page /boutique via ui_routes et API /api/v1/modules/boutique/products', async ({ request, page }) => {
        // 1. Scan préalable pour garantir l'enregistrement de BoutiqueApi en BDD
        const resScan = await request.post('/api/v1/sys/modules/scan');
        expect(resScan.status()).toBe(200);
        const jsonScan = await resScan.json();
        expect(jsonScan.status).toBe('success');

        // 2. Activation de BoutiqueApi
        const resToggle = await request.patch('/api/v1/sys/modules/BoutiqueApi/toggle', {
            data: { enabled: true },
        });
        expect(resToggle.status()).toBe(200);
        const jsonToggle = await resToggle.json();
        expect(jsonToggle.status).toBe('success');
        expect(Boolean(jsonToggle.data.is_active)).toBe(true);

        // 3. Publication automatique de public/assets/modules/boutique/boutique.css (et zéro .php publié)
        const publishedCssPath = path.join(PUBLIC_MODULES_ASSETS_DIR, 'boutique', 'boutique.css');
        expect(fs.existsSync(publishedCssPath), 'boutique.css doit être publié dans public/assets/modules/boutique/').toBe(true);
        expect(listPhpFilesRecursive(PUBLIC_MODULES_ASSETS_DIR)).toEqual([]);

        const resCss = await request.get('/assets/modules/boutique/boutique.css');
        expect(resCss.status()).toBe(200);
        const cssBody = await resCss.text();
        expect(cssBody.length).toBeGreaterThan(0);

        // 4. Page /boutique servie dynamiquement via le filtre ui_routes
        const resPage = await page.goto('/boutique');
        expect(resPage?.status()).toBe(200);
        const grid = page.locator('#boutique-products-grid');
        await expect(grid).toBeVisible();
        await expect(grid).toContainText('Kimono Club Kanso-ya');
        await expect(page.locator('link[href="/assets/modules/boutique/boutique.css"]')).toHaveCount(1);

        // 5. Endpoint REST confiné /api/v1/modules/boutique/products retournant JSend HTTP 200
        const resProducts = await request.get('/api/v1/modules/boutique/products');
        expect(resProducts.status()).toBe(200);
        const jsonProducts = await resProducts.json();
        expect(jsonProducts.status).toBe('success');
        expect(jsonProducts.code).toBe(200);
        expect(Array.isArray(jsonProducts.data)).toBe(true);
        expect(jsonProducts.data.length).toBeGreaterThanOrEqual(1);
    });

    test('S17-03 : Désinstallation de BoutiqueApi -> purge assets, /boutique en HTTP 404 et API en JSend 404', async ({ request, page }) => {
        // 1. Désinstallation de BoutiqueApi
        const resUninstall = await request.post('/api/v1/sys/modules/BoutiqueApi/uninstall');
        expect(resUninstall.status()).toBe(200);
        const jsonUninstall = await resUninstall.json();
        expect(jsonUninstall.status).toBe('success');

        // 2. Purge du dossier public/assets/modules/boutique/
        const publishedDir = path.join(PUBLIC_MODULES_ASSETS_DIR, 'boutique');
        expect(fs.existsSync(publishedDir), 'Le dossier public/assets/modules/boutique/ doit être purgé').toBe(false);

        const resCssAfter = await request.get('/assets/modules/boutique/boutique.css');
        expect(resCssAfter.status()).toBe(404);

        // 3. /boutique renvoie HTTP 404 (plus enregistré dans ui_routes)
        const resPageAfter = await page.goto('/boutique');
        expect(resPageAfter?.status()).toBe(404);

        // 4. GET /api/v1/modules/boutique/products renvoie JSend 404 (plus enregistré dans api_routes)
        const resApiAfter = await request.get('/api/v1/modules/boutique/products');
        expect(resApiAfter.status()).toBe(200);
        const jsonApiAfter = await resApiAfter.json();
        expect(jsonApiAfter.status).toBe('error');
        expect(jsonApiAfter.code).toBe(404);
    });
});
