import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';

const PROJECT_ROOT = process.cwd();

test.describe('Sprint 19 (Lots R5-R7) — Verrouillage HTTP Définitif, Tailwind Sans CDN & Qualité Production', () => {
    test('S19-01 : Refus strict 403/404 sur tous les répertoires internes, dotfiles et scripts dans uploads/', async ({ request }) => {
        const forbiddenPaths = [
            '/Core/',
            '/Core/Api/Database/Database.php',
            '/modules/',
            '/modules/BoutiqueApi/manifest.json',
            '/storage/',
            '/storage/db/kanso-ya.sqlite',
            '/docs/',
            '/tests/',
            '/vendor/',
            '/.env',
            '/.htaccess',
            '/uploads/test.php',
        ];

        for (const p of forbiddenPaths) {
            const res = await request.get(p);
            expect([403, 404], `Chemin ${p} doit être bloqué en 403 ou 404 (reçu ${res.status()})`).toContain(res.status());
            const body = await res.text();
            expect(body).not.toContain('DB_CONNECTION=');
            expect(body).not.toContain('declare(strict_types=1)');
        }
    });

    test('S19-02 : Blocage HTTP 403 sur tout accès direct .php hors index.php et api.php', async ({ request }) => {
        const legacyPhpUrls = [
            '/stats.php',
            '/members.php',
            '/dashboard.php',
            '/login.php',
            '/register.php',
            '/sys-console.php',
            '/boutique.php',
            '/horaires.php',
        ];

        for (const url of legacyPhpUrls) {
            const res = await request.get(url);
            expect(res.status(), `Accès direct ${url} doit retourner HTTP 403`).toBe(403);
        }

        // Les URLs propres correspondantes fonctionnent normalement via PageRouter
        const homeRes = await request.get('/');
        expect(homeRes.status()).toBe(200);
        const horairesRes = await request.get('/horaires');
        expect(horairesRes.status()).toBe(200);
    });

    test('S19-03 : Tailwind CSS compilé localement (sans CDN) sur /, /login et /sys-admin-xyz', async ({ request }) => {
        for (const url of ['/', '/login', '/sys-admin-xyz']) {
            const res = await request.get(url);
            expect(res.status()).toBe(200);
            const html = await res.text();
            expect(html).not.toContain('cdn.tailwindcss.com');
            expect(html).toContain('/assets/css/tailwind.css');
        }

        const cssRes = await request.get('/assets/css/tailwind.css');
        expect(cssRes.status()).toBe(200);
        const cssBody = await cssRes.text();
        expect(cssBody.length).toBeGreaterThan(20000);
    });

    test('S19-04 : Rétention automatique des sauvegardes SQLite (<= 10) et conformité PHPStan niveau 5', async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const backupRes = await request.post('/api/v1/sys/backup');
        expect(backupRes.status()).toBe(200);
        const backupJson = await backupRes.json();
        expect(backupJson.status).toBe('success');

        const backupDir = path.join(PROJECT_ROOT, 'storage', 'backups');
        const backups = fs.readdirSync(backupDir).filter((f) => f.startsWith('kanso-ya-backup-') && f.endsWith('.sqlite'));
        expect(backups.length).toBeGreaterThanOrEqual(1);
        expect(backups.length).toBeLessThanOrEqual(10);

        // Vérification PHPStan niveau 5 via phpstan.neon
        expect(fs.existsSync(path.join(PROJECT_ROOT, 'phpstan.neon'))).toBe(true);
        const phpstanOut = execFileSync(
            'php',
            [path.join(PROJECT_ROOT, 'vendor', 'phpstan', 'phpstan', 'phpstan'), 'analyse', '--no-progress'],
            { cwd: PROJECT_ROOT, encoding: 'utf8' }
        );
        expect(phpstanOut).toContain('[OK] No errors');
    });
});
