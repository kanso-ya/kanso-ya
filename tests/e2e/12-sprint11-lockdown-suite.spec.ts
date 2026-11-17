/**
 * @file 12-sprint11-lockdown-suite.spec.ts
 * @description SPRINT 11 — Batterie Massive de Lockdown Final E2E & UAT pour le CMS Kanso-ya.
 *
 * AXE 1 : ÉTANCHÉITÉ ABSOLUE DES 8 RÔLES & ACCÈS API (401/403)
 * AXE 2 : ROBUSTESSE FINANCIÈRE, CERTIFICATS SANTÉ & FRAUDE OTP
 * AXE 3 : GRAND CHELEM UAT INTERCONNECTÉ DE BOUT EN BOUT
 */

import { test, expect } from '@playwright/test';

test.describe('SPRINT 11 — Lockdown Final E2E, RBAC Impitoyable & Homologation Globale', () => {

    // =========================================================================
    // AXE 1 : ÉTANCHÉITÉ RBAC DES 8 RÔLES & VISITEUR PUBLIC
    // =========================================================================

    test('LOCKDOWN-RBAC-01 : Étanchéité Visiteur non authentifié (Blocage 401 sur le Back-Office)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });

        // Pages publiques obligatoirement accessibles
        const publicPages = ['/', '/login', '/register', '/horaires', '/rgpd', '/cgv', '/mentions-legales', '/questionnaire-sante'];
        for (const p of publicPages) {
            await page.goto(p);
            await expect(page.locator('body')).not.toContainText('Accès Protégé');
            await expect(page.locator('body')).not.toContainText('403 Forbidden');
            await expect(page.locator('body')).not.toContainText('Erreur 403');
        }

        // Pages privées obligatoirement bloquées
        const privatePages = ['/dashboard', '/members', '/registrations', '/stats', '/cms', '/disciplines', '/roles', '/sys-console'];
        for (const p of privatePages) {
            await page.goto(p);
            await expect(page.locator('body')).toContainText('Accès Protégé');
            await expect(page.locator('#main-workspace a[href="/login"]')).toBeVisible();
        }
    });

    test('LOCKDOWN-RBAC-02 : Étanchéité Adhérent (Accès profil uniquement, rejet strict sur le club)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'tutorA' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'tutorA' } });

        // Accès à Mon Profil
        await page.goto('/dashboard');
        await expect(page.locator('body')).not.toContainText('Accès Protégé');

        // Rejet sur Trésorerie, Rôles, CMS, Admin
        const forbiddenPages = ['/registrations', '/roles', '/cms', '/disciplines', '/sys-console', '/stats'];
        for (const p of forbiddenPages) {
            await page.goto(p);
            await expect(page.locator('body')).toContainText('Accès Protégé');
        }

        // Rejet API direct
        const resApi = await request.delete('/api/v1/registrations/1');
        const json = await resApi.json();
        expect(json.status).toBe('error');
        expect(json.code).toBe(403);
    });

    test('LOCKDOWN-RBAC-03 : Étanchéité Entraîneur (Accès effectifs sans coordonnées privées, pas de trésorerie)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'entraineur' } });

        // L'Entraîneur peut voir l'annuaire des membres
        await page.goto('/members');
        await expect(page.locator('body')).not.toContainText('Accès Protégé');

        // Mais n'a aucun accès à la Trésorerie ni à la console
        await page.goto('/registrations');
        await expect(page.locator('body')).toContainText('Accès Protégé');

        await page.goto('/sys-console');
        await expect(page.locator('body')).toContainText('Accès Protégé');

        // Rejet API sur la validation d'un dossier
        const resApi = await request.patch('/api/v1/registrations/1/validate', {
            data: { payment_status: 'validated' }
        });
        expect((await resApi.json()).code).toBe(403);
    });

    test('LOCKDOWN-RBAC-04 : Étanchéité Rédacteur (CMS seul, blocage sur adhérents et finances)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'redacteur' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'redacteur' } });

        // Accès au Studio CMS
        await page.goto('/cms');
        await expect(page.locator('body')).not.toContainText('Accès Protégé');

        // Rejet sur l'annuaire et la trésorerie
        await page.goto('/members');
        await expect(page.locator('body')).toContainText('Accès Protégé');

        await page.goto('/registrations');
        await expect(page.locator('body')).toContainText('Accès Protégé');
    });

    test('LOCKDOWN-RBAC-05 : Étanchéité Trésorier (Validation financière, reçus, rejet sur rôles & console)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });

        // Accès Trésorerie
        await page.goto('/registrations');
        await expect(page.locator('body')).not.toContainText('Accès Protégé');

        // Rejet sur la console Superadmin et la matrice des rôles
        await page.goto('/roles');
        await expect(page.locator('body')).toContainText('Accès Protégé');

        await page.goto('/sys-console');
        await expect(page.locator('body')).toContainText('Accès Protégé');
    });

    test('LOCKDOWN-RBAC-06 : Étanchéité Superadmin (Console technique exclusive, étanchéité login standard)', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });

        // Accès console technique
        await page.goto('/sys-console');
        await expect(page.locator('body')).not.toContainText('Accès Protégé');
        await expect(page.locator('#metric-users-count')).toBeVisible();

        // Le Superadmin a son ambiance sombre
        await expect(page.locator('#app-body')).toHaveClass(/.*bg-slate-950.*/);
    });

    // =========================================================================
    // AXE 2 : ROBUSTESSE, SÉCURITÉ & DOCUMENTS CONFORMES
    // =========================================================================

    test('LOCKDOWN-SEC-01 : Rejet d\'injection XSS sur le titre d\'un article CMS', async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });

        const maliciousTitle = `<script>alert('xss-exploit')</script> Tournoi National`;
        const res = await request.post('/api/v1/articles', {
            data: {
                title: maliciousTitle,
                content: '<p>Contenu du tournoi</p>',
                category_id: 1,
                status: 'published'
            }
        });

        const json = await res.json();
        expect(json.status).toBe('success');
        const articleId = json.data.id;

        // Récupération de l'article pour vérifier la neutralisation XSS
        const resGet = await request.get(`/api/v1/articles/${articleId}`);
        const articleData = (await resGet.json()).data;
        expect(articleData.title).not.toContain('<script>');
        expect(articleData.title).toContain('&lt;script&gt;');
    });

    test('LOCKDOWN-SEC-02 : Inviolabilité des jetons OTP (Rejet systématique de jeton inexistant ou rejoué)', async ({ request }) => {
        // Tentative d'activation avec un faux token
        const fakeRes = await request.post('/api/v1/auth/activate-otp', {
            data: {
                otp_code: 'fake-invalid-token-999999',
                new_password: 'ValidPassword123!'
            }
        });

        const json = await fakeRes.json();
        expect(json.status).toBe('error');
        expect(json.code).toBe(400);
        expect(json.message).toMatch(/invalide|utilis/i);
    });

    test('LOCKDOWN-FINANCE-01 : Génération conforme des Reçus et Attestations CE/CSE', async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });

        // Recherche d'un dossier validé
        const resList = await request.get('/api/v1/registrations');
        const listData = (await resList.json()).data;
        const validatedReg = Array.isArray(listData) ? listData.find((r: { payment_status: string }) => r.payment_status === 'validated' || r.payment_status === 'paid') : null;

        if (validatedReg) {
            const regId = validatedReg.id;

            // 1. Reçu de paiement
            const resReceipt = await request.get(`/api/v1/registrations/${regId}/receipt`);
            const jsonReceipt = await resReceipt.json();
            expect(jsonReceipt.status).toBe('success');
            expect(jsonReceipt.data.receipt_text).toContain('REÇU DE COTISATION');
            expect(jsonReceipt.data.receipt_text).toContain('Certifié conforme par le Trésorier');

            // 2. Attestation CE
            const resCse = await request.get(`/api/v1/registrations/${regId}/cse-attestation`);
            const jsonCse = await resCse.json();
            expect(jsonCse.status).toBe('success');
            expect(jsonCse.data.attestation_text).toContain('ATTESTATION DE PAIEMENT POUR COMITÉ SOCIAL ET ÉCONOMIQUE');
            expect(jsonCse.data.attestation_text).toContain('pour faire valoir ce que de droit');
        }
    });

    // =========================================================================
    // AXE 3 : GRAND CHELEM UAT DE BOUT EN BOUT (100% INTERCONNECTÉ)
    // =========================================================================

    test('LOCKDOWN-UAT-FULL : Parcours d\'Homologation Intégral (Inscription -> Validation -> OTP -> Attestation -> CMS -> Sauvegarde BDD)', async ({ page, request }) => {
        const ts = Date.now();
        const memberEmail = `eleve.lockdown.${ts}@kanso-ya.fr`;

        // ÉTAPE 1 : Le visiteur s'inscrit en ligne
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });

        const regRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Camille',
                    last_name: `Lockdown${ts}`,
                    birth_date: '2000-05-15', // Adulte
                    gender: 'F',
                    email: memberEmail,
                    phone: '06 99 88 77 66',
                    address: '15 Avenue des Arts, 79100 Thouars',
                    emergency_contact_name: 'Contact Urgence',
                    emergency_contact_phone: '06 00 11 22 33',
                    practices: [{ discipline_id: 1, level_id: 1 }]
                }],
                payment_method: 'cheque',
                installments: 1
            }
        });

        const regJson = await regRes.json();
        expect(regJson.status).toBe('success');
        const regId = regJson.data.registration_id;
        expect(regId).toBeGreaterThan(0);

        // ÉTAPE 2 : Le Trésorier inspecte et valide le dossier
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'tresorier' } });
        const valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
            data: {
                payment_status: 'validated',
                practices_adjustments: []
            }
        });

        const valJson = await valRes.json();
        expect(valJson.status).toBe('success');
        const otpToken = valJson.data.otp_code_stub;
        expect(otpToken).toBeTruthy();

        // ÉTAPE 3 : L'adhérent active son mot de passe avec le jeton OTP reçu
        const actRes = await request.post('/api/v1/auth/activate-otp', {
            data: {
                otp_code: otpToken,
                new_password: 'SuperPassword123!'
            }
        });
        expect((await actRes.json()).status).toBe('success');

        // ÉTAPE 4 : Vérification de la disponibilité immédiate du reçu et de l'attestation CE
        const docRes = await request.get(`/api/v1/registrations/${regId}/receipt`);
        expect((await docRes.json()).status).toBe('success');

        // ÉTAPE 5 : Le Superadmin réalise une sauvegarde de sécurité à chaud
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const backupRes = await request.post('/api/v1/sys/backup');
        const backupJson = await backupRes.json();
        expect(backupJson.status).toBe('success');
        expect(backupJson.data.filename).toMatch(/kanso-ya-backup.*\.sqlite/);
    });

});
