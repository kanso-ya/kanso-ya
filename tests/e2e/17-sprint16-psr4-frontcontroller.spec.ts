/**
 * @file 17-sprint16-psr4-frontcontroller.spec.ts
 * @description SPRINT 16 (Lots R1 & R2) — Arborescence PSR-4 & Front-Controller Unique :
 *  - S16-01 : `public/` ne contient strictement que `index.php` et `api.php` comme fichiers `.php`
 *  - S16-02 : Garde `KANSO_ENTRY` active sur `Core/Ui/Pages/home.php` en CLI (`Direct access forbidden`)
 *  - S16-03 : Routage complet via `public/index.php` → `PageRouter` + HTTP 404 propre sur route inconnue
 *  - S16-04 : Conformité PSR-4 (`composer dump-autoload -o --strict-psr`) et API via `ApiKernel` (`GET /api/v1/settings`)
 */

import { test, expect } from '@playwright/test';
import { execFileSync, execSync } from 'node:child_process';
import * as fs from 'node:fs';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const PUBLIC_DIR = path.join(PROJECT_ROOT, 'public');

function listPhpFilesRecursive(dir: string, baseDir: string = dir): string[] {
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

test.describe('SPRINT 16 (Lots R1 & R2) — PSR-4 Core/Api & Core/Ui + Front-Controller Unique', () => {

    test('S16-01 : public/ ne contient strictement que index.php et api.php comme fichiers .php', async () => {
        const rootPhpFiles = fs
            .readdirSync(PUBLIC_DIR, { withFileTypes: true })
            .filter((entry) => entry.isFile() && entry.name.toLowerCase().endsWith('.php'))
            .map((entry) => entry.name)
            .sort();

        expect(rootPhpFiles).toEqual(['api.php', 'index.php']);

        const allPhpFilesInPublic = listPhpFilesRecursive(PUBLIC_DIR);
        expect(allPhpFilesInPublic).toEqual(['api.php', 'index.php']);
    });

    test('S16-02 : Garde KANSO_ENTRY active — exécuter php Core/Ui/Pages/home.php en CLI retourne Direct access forbidden', async () => {
        const homePagePath = path.join(PROJECT_ROOT, 'Core', 'Ui', 'Pages', 'home.php');
        expect(fs.existsSync(homePagePath)).toBe(true);

        const stdout = execFileSync('php', [homePagePath], {
            cwd: PROJECT_ROOT,
            encoding: 'utf-8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        expect(stdout).toContain('Direct access forbidden');
        expect(stdout).not.toContain('<!DOCTYPE html');
        expect(stdout).not.toContain('<html');
        expect(stdout).not.toContain('id="main-workspace"');
    });

    test('S16-03 : Routes clés via public/index.php -> PageRouter et HTTP 404 propre sur /page-inexistante-v2', async ({ request }) => {
        const publicRoutes = ['/', '/horaires', '/login', '/register', '/rgpd'];
        for (const route of publicRoutes) {
            const response = await request.get(route);
            expect(response.status(), `Statut HTTP 200 attendu sur ${route}`).toBe(200);

            const html = await response.text();
            expect(html, `Document HTML complet attendu sur ${route}`).toContain('<!DOCTYPE html>');
            expect(html, `Ne doit pas afficher la vue 404 sur ${route}`).not.toContain('Page introuvable');
        }

        const protectedRoutes = ['/stats', '/members', '/sys-console'];
        for (const route of protectedRoutes) {
            const response = await request.get(route);
            expect(
                [200, 401, 403],
                `Route protégée ${route} doit être routée par PageRouter (reçu ${response.status()})`
            ).toContain(response.status());

            const html = await response.text();
            expect(html, `Document HTML complet attendu sur ${route}`).toContain('<!DOCTYPE html>');
            expect(html, `Gabarit layout attendu sur ${route}`).toContain('id="main-workspace"');
            expect(html, `Ne doit pas afficher la vue 404 sur ${route}`).not.toContain('Page introuvable');
        }

        const notFoundResponse = await request.get('/page-inexistante-v2');
        expect(notFoundResponse.status()).toBe(404);
        const notFoundHtml = await notFoundResponse.text();
        expect(notFoundHtml).toContain('Page introuvable');
        expect(notFoundHtml).toContain('Retour à l\'accueil');
    });

    test('S16-04 : Conformité PSR-4 stricte (composer dump-autoload -o --strict-psr) et API via ApiKernel (GET /api/v1/settings)', async ({ request }) => {
        const composerOutput = execSync('composer dump-autoload -o --strict-psr 2>&1', {
            cwd: PROJECT_ROOT,
            encoding: 'utf-8',
            stdio: ['ignore', 'pipe', 'pipe'],
        });

        expect(composerOutput).toContain('Generated optimized autoload files');
        expect(composerOutput).not.toContain('does not comply with psr-4');

        const apiResponse = await request.get('/api/v1/settings');
        expect(apiResponse.status()).toBe(200);

        const payload = await apiResponse.json() as Record<string, unknown>;
        expect(payload.success).toBe(true);
        expect(payload.code).toBe(200);
        expect(payload.data).toBeDefined();
        expect(typeof payload.data).toBe('object');
    });

});
