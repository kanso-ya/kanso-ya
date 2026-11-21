/**
 * @file 16-sprint15-security-r0.spec.ts
 * @description SPRINT 15 (Lot R0) — Durcissement Sécurité :
 *  - S15-01 : Présence des 4 en-têtes HTTP de sécurité sur `/` et `/login`
 *  - S15-02 : Blocage HTTP (403 ou 404) des fichiers et dossiers internes sensibles
 *  - S15-03 : Accès Visiteur anonyme bloqué (Accès Protégé) sur `/dashboard`, `/settings`, `/stats` sans fuite de données
 *  - S15-04 : Isolation de `isQaHooksEnabled()` en sous-processus PHP (APP_ENV=production ou QA_HOOKS_ENABLED=0)
 */

import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import * as path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const PROJECT_ROOT = path.resolve(__dirname, '..', '..');
const API_ENTRY = path.join(PROJECT_ROOT, 'public', 'api.php');

function runApiSubprocess(
    envOverrides: Record<string, string>,
    serverOverrides: Record<string, string>
): Record<string, unknown> {
    const phpCode = [
        ...Object.entries(serverOverrides).map(
            ([k, v]) => `$_SERVER[${JSON.stringify(k)}] = ${JSON.stringify(v)};`
        ),
        `require ${JSON.stringify(API_ENTRY)};`,
    ].join(' ');

    const stdout = execFileSync('php', ['-r', phpCode], {
        cwd: PROJECT_ROOT,
        env: {
            ...process.env,
            ...envOverrides,
        },
        encoding: 'utf-8',
        stdio: ['ignore', 'pipe', 'pipe'],
    });

    return JSON.parse(stdout.trim()) as Record<string, unknown>;
}

test.describe('SPRINT 15 (Lot R0) — Durcissement Sécurité & Verrouillage Environnement', () => {

    test('S15-01 : Présence des 4 en-têtes HTTP de sécurité sur / et /login', async ({ request }) => {
        for (const route of ['/', '/login']) {
            const response = await request.get(route);
            expect(response.status(), `Statut HTTP attendu 200 sur ${route}`).toBe(200);

            const headers = response.headers();
            expect(headers['x-content-type-options'], `X-Content-Type-Options manquant sur ${route}`).toBe('nosniff');
            expect(headers['x-frame-options'], `X-Frame-Options manquant sur ${route}`).toBe('SAMEORIGIN');
            expect(headers['referrer-policy'], `Referrer-Policy manquant sur ${route}`).toBe('strict-origin-when-cross-origin');
            expect(headers['permissions-policy'], `Permissions-Policy manquant sur ${route}`).toContain('geolocation=()');
            expect(headers['permissions-policy']).toContain('microphone=()');
            expect(headers['permissions-policy']).toContain('camera=()');
        }
    });

    test('S15-02 : Blocage HTTP (403 ou 404) sur /.env, /.htaccess, /Core/Api/Database/Database.php et /storage/db/kanso-ya.sqlite', async ({ request }) => {
        const sensitivePaths = [
            '/.env',
            '/.htaccess',
            '/Core/Api/Database/Database.php',
            '/storage/db/kanso-ya.sqlite',
        ];

        for (const target of sensitivePaths) {
            const response = await request.get(target);
            expect(
                [403, 404],
                `Accès sensible non bloqué sur ${target} (statut reçu: ${response.status()})`
            ).toContain(response.status());

            const body = await response.text();
            expect(body).not.toContain('APP_ENV=');
            expect(body).not.toContain('QA_HOOKS_ENABLED=');
            expect(body).not.toContain('declare(strict_types=1)');
            expect(body).not.toContain('SQLite format 3');
        }
    });

    test('S15-03 : Visiteur anonyme bloqué sur /dashboard, /settings et /stats sans fuite de données', async ({ page }) => {
        // Garantit une session strictement anonyme (aucun cookie de session)
        await page.context().clearCookies();

        const protectedRoutes = ['/dashboard', '/settings', '/stats'];

        for (const route of protectedRoutes) {
            await page.goto(route);

            await expect(page.locator('body')).toContainText('Accès Protégé');
            await expect(page.locator('#main-workspace a[href="/login"]')).toBeVisible();

            const workspaceText = await page.locator('#main-workspace').innerText();
            const html = await page.content();

            // Aucune donnée de profil, paramétrage club, trésorerie ou effectifs ne doit fuiter
            expect(workspaceText).not.toContain('Informations Personnelles');
            expect(workspaceText).not.toContain('Cotisations & Inscriptions');
            expect(workspaceText).not.toContain('Recettes Validées');
            expect(workspaceText).not.toContain('Recettes en Attente');
            expect(workspaceText).not.toContain('Effectif Total');
            expect(workspaceText).not.toContain('Taux de Renouvellement');
            expect(html).not.toContain('id="form-my-profile"');
            expect(html).not.toContain('id="form-club-general"');
            expect(html).not.toContain('name="membership_base_price"');
        }
    });

    test('S15-04 : Isolation de isQaHooksEnabled() en sous-processus PHP (production / désactivé)', async () => {
        // 1. APP_ENV=production même si QA_HOOKS_ENABLED=1 => switch-persona doit retourner JSend 404
        const prodRes = runApiSubprocess(
            { APP_ENV: 'production', QA_HOOKS_ENABLED: '1' },
            { REQUEST_METHOD: 'POST', REQUEST_URI: '/api/v1/auth/switch-persona' }
        );
        expect(prodRes.success).toBe(false);
        expect(prodRes.code).toBe(404);

        // 2. QA_HOOKS_ENABLED=0 même si APP_ENV=testing => switch-persona doit retourner JSend 404
        const disabledRes = runApiSubprocess(
            { APP_ENV: 'testing', QA_HOOKS_ENABLED: '0' },
            { REQUEST_METHOD: 'POST', REQUEST_URI: '/api/v1/auth/switch-persona' }
        );
        expect(disabledRes.success).toBe(false);
        expect(disabledRes.code).toBe(404);

        // 3. APP_ENV=production + stub-superadmin-token => token ignoré, accès refusé (401/403)
        const stubBlockedRes = runApiSubprocess(
            { APP_ENV: 'production', QA_HOOKS_ENABLED: '0' },
            {
                REQUEST_METHOD: 'GET',
                REQUEST_URI: '/api/v1/members',
                HTTP_AUTHORIZATION: 'Bearer stub-superadmin-token',
            }
        );
        expect(stubBlockedRes.success).toBe(false);
        expect([401, 403]).toContain(stubBlockedRes.code);

        // 4. Contrôle positif : APP_ENV=testing & QA_HOOKS_ENABLED=1 => switch-persona actif (200)
        const testingRes = runApiSubprocess(
            { APP_ENV: 'testing', QA_HOOKS_ENABLED: '1' },
            { REQUEST_METHOD: 'POST', REQUEST_URI: '/api/v1/auth/switch-persona' }
        );
        expect(testingRes.success).toBe(true);
        expect(testingRes.code).toBe(200);
    });

});
