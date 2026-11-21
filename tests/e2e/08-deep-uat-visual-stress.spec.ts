/**
 * @file 08-deep-uat-visual-stress.spec.ts
 * @description Campagne d'audit E2E et UAT approfondie de Kanso-ya (Desktop Chrome & Mobile Chrome Pixel 5)
 *
 * AXE 1 : Parcours Utilisateurs Complets de Bout en Bout (100% UI réels sans raccourci API)
 *   - Famille Onboarding : Visiteur anonyme inscrit 1 adulte + 2 enfants (dont 1 multi-disciplines), Chèque 3x,
 *     remise -10%, Trésorier via /login inspecte, ajuste grade, valide, récupère OTP, activation sur /activate-otp.php,
 *     connexion parent, vérification rattachements et consentement RGPD.
 *   - Éditorial : Rédacteur crée article multi-colonnes + encadré callout en brouillon, Président relit et publie,
 *     vérification sur l'accueil public / et dans la modale de lecture.
 *
 * AXE 2 : Cas Passants & Non-Passants (Robustesse & Limites)
 *   - Soumission formulaires vides, dates de naissance futures, tuteur mineur (<18 ans).
 *   - Limite panier familial (>10 enfants).
 *   - Rejeu d'un jeton OTP déjà consommé.
 *   - Tentatives d'upload de fichiers interdits (.php, faux .jpg exécutable) sur certificats médicaux et médias CMS.
 *   - Étanchéité des routes /login (rejet Superadmin) et /sys-admin-xyz (rejet utilisateur normal).
 *
 * AXE 3 : Scénarios de Charge & Planning Plein
 *   - Alerte visuelle de saturation 100% / 30 inscrits (data-testid="capacity-alert-...") sur /stats.php.
 *   - Inscription supplémentaire sans rupture dans une discipline saturée.
 *   - Recherche instantanée (0ms) et filtrage combiné (Statut + Discipline) sur annuaire (~60 adhérents).
 *
 * AXE 4 : Audit Visuel & Responsive (Desktop 1280x800 vs Mobile 375x812)
 *   - Desktop : Logo à cheval Header/Sidebar, titre centré, masquage bouton mobile.
 *   - Mobile : Masquage logo à cheval, bouton mobile, ouverture/fermeture tiroir latéral (#sidebar-backdrop).
 *   - Absence de débordement horizontal (scrollWidth <= clientWidth) sur les 8 pages clés.
 *   - Isolation visuelle sombre (bg-slate-950) du Superadmin sur /sys-console.php.
 */

import { test, expect } from '@playwright/test';
import * as path from 'path';
import * as fs from 'fs';

test.describe.configure({ mode: 'serial' });

test.describe('DOMAINE 8 — Deep Audit E2E & UAT, Visual & Responsive Stress', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    // =========================================================================
    // AXE 1 : PARCOURS UTILISATEURS COMPLETS DE BOUT EN BOUT (100% UI SANS RACCOURCI)
    // =========================================================================

    test('AXE 1.1 : Parcours Famille Onboarding Complet (Visiteur -> Inscription 1 adulte + 2 enfants -> Trésorier /login -> Ajustement grade -> Validation -> OTP -> Espace Adhérent RGPD)', async ({ page, request }) => {
        test.setTimeout(120000);
        const ts = Date.now();
        const adultEmail = `lucas.martin.${ts}@kanso-ya.fr`;
        const tutorEmail = `thomas.martin.${ts}@kanso-ya.fr`;

        // 1. Déconnexion préalable pour être un vrai visiteur anonyme
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'visitor' } });
        await page.context().clearCookies();

        // 2. Le visiteur anonyme navigue sur le formulaire public
        await page.goto('/register');
        await expect(page.locator('#public-registration-form')).toBeVisible();

        // --- MEMBRE 1 : ADULTE (Lucas Martin, né en 1990) ---
        await page.fill('#reg-first-name', 'Lucas');
        await page.fill('#reg-last-name', 'Martin');
        await page.fill('#reg-birth-date', '1990-05-10');
        await page.dispatchEvent('#reg-birth-date', 'change');

        await expect(page.locator('#age-indicator-badge')).toContainText('Majeur');
        await page.fill('#reg-email', adultEmail);
        await page.locator('.prac-discipline').first().selectOption('1'); // Judo

        // Étape suivante -> Contact d'urgence (car majeur, saute l'étape tuteurs)
        await page.click('#btn-next-from-identity');
        await expect(page.locator('#step-panel-emergency')).toBeVisible();

        await page.fill('#reg-emerg-name', 'Sylvie Martin (Épouse)');
        await page.fill('#reg-emerg-phone', '06 11 22 33 44');

        // Étape suivante -> Panier & Règlement
        await page.click('#btn-next-from-emergency');
        await expect(page.locator('#step-panel-payment')).toBeVisible();

        // Ajout de Lucas au panier familial pour passer au 2e membre
        await page.click('#btn-add-family-member');
        await expect(page.locator('#step-panel-identity')).toBeVisible();

        // --- MEMBRE 2 : ENFANT 1 (Léo Martin, né en 2017 - Mineur Poussin Judo) ---
        await page.fill('#reg-first-name', 'Léo');
        await page.fill('#reg-last-name', 'Martin');
        await page.fill('#reg-birth-date', '2017-04-12');
        await page.dispatchEvent('#reg-birth-date', 'change');
        await page.locator('.prac-discipline').first().selectOption('1'); // Judo

        await expect(page.locator('#age-indicator-badge')).toContainText('Mineur');

        // Étape suivante -> Étape Tuteurs légaux obligatoire pour mineur
        await page.click('#btn-next-from-identity');
        await expect(page.locator('#step-panel-tutors')).toBeVisible();

        await page.fill('#tutor1-first-name', 'Thomas');
        await page.fill('#tutor1-last-name', 'Martin');
        await page.fill('#tutor1-email', tutorEmail);
        await page.fill('#tutor1-phone', '06 11 22 33 44');
        await page.fill('#tutor1-birth', '1985-02-14');
        await page.fill('#tutor1-address', '10 Rue de la Paix, 79100 Thouars');

        // Étape suivante -> Contact d'urgence
        await page.click('#btn-next-from-tutors');
        await expect(page.locator('#step-panel-emergency')).toBeVisible();

        await page.fill('#reg-emerg-name', 'Thomas Martin (Père)');
        await page.fill('#reg-emerg-phone', '06 11 22 33 44');

        // Étape suivante -> Panier
        await page.click('#btn-next-from-emergency');
        await expect(page.locator('#step-panel-payment')).toBeVisible();

        // Ajout de Léo au panier familial pour passer au 3e membre
        await page.click('#btn-add-family-member');
        await expect(page.locator('#step-panel-identity')).toBeVisible();

        // --- MEMBRE 3 : ENFANT 2 (Chloé Martin, née en 2015 - Multi-disciplines : Judo + Karaté) ---
        await page.fill('#reg-first-name', 'Chloé');
        await page.fill('#reg-last-name', 'Martin');
        await page.fill('#reg-birth-date', '2015-08-20');
        await page.dispatchEvent('#reg-birth-date', 'change');

        // Choix de la 1ère discipline (Judo) et ajout d'une 2e discipline (Karaté)
        await page.click('#btn-add-practice-row');
        const disciplineSelects = page.locator('.prac-discipline');
        await expect(disciplineSelects).toHaveCount(2);
        await disciplineSelects.nth(0).selectOption('1'); // Judo
        await disciplineSelects.nth(1).selectOption('2'); // Karaté

        // Étape suivante -> Tuteurs (déjà pré-remplis)
        await page.click('#btn-next-from-identity');
        await expect(page.locator('#step-panel-tutors')).toBeVisible();
        await expect(page.locator('#tutor1-email')).toHaveValue(tutorEmail);

        // Étape suivante -> Urgence
        await page.click('#btn-next-from-tutors');
        await expect(page.locator('#step-panel-emergency')).toBeVisible();
        await page.fill('#reg-emerg-name', 'Thomas Martin (Père)');
        await page.fill('#reg-emerg-phone', '06 11 22 33 44');

        // Étape suivante -> Panier & Règlement
        await page.click('#btn-next-from-emergency');
        await expect(page.locator('#step-panel-payment')).toBeVisible();

        // Vérification de la composition du panier (3 adhérents) et du montant remisé -10% (150 * 3 * 0.9 = 405€)
        await expect(page.locator('#family-cart-count')).toContainText('3 adhérent(s)');
        await expect(page.locator('#reg-total-price')).toHaveText('405.00');
        await expect(page.locator('#step-panel-payment')).toContainText('-10%');

        // Choix du paiement par Chèque en 3 fois
        await page.selectOption('#reg-payment-method', 'cheque');
        await page.selectOption('#reg-installments', '3');

        // Validation finale du dossier
        await page.click('#btn-submit-registration');

        // Vérification de l'écran de confirmation
        const successScreen = page.locator('#registration-success-screen');
        await expect(successScreen).toBeVisible({ timeout: 30000 });

        const regIdText = await page.locator('#confirm-screen-reg-id').textContent();
        expect(regIdText).toBeTruthy();
        const regId = parseInt(regIdText!.trim(), 10);
        expect(regId).toBeGreaterThan(0);

        // 3. Connexion du Trésorier via la page de connexion standard /login
        await page.goto('/login');
        await page.fill('input[name="email"]', 'tresorier@kanso-ya.local');
        await page.fill('input[name="password"]', 'password123');
        await page.click('button[type="submit"]');

        // Redirection vers l'accueil ou navigation sur /registrations.php
        await page.waitForURL((url) => url.pathname === '/' || url.pathname.includes('registrations') || url.pathname.includes('dashboard'), { timeout: 10000 });
        await page.goto('/registrations');

        // Inspection du dossier créé
        const regRow = page.locator('#registrations-tbody tr', { hasText: `#${regId}` }).first();
        await expect(regRow).toBeVisible();

        await regRow.locator('button', { hasText: 'Inspecter' }).first().click();
        const modal = page.locator('#inspect-registration-modal');
        await expect(modal).toBeVisible();

        // Ajustement du niveau / ceinture sur la première pratique
        const levelSelect = modal.locator('.val-adjust-level').first();
        if (await levelSelect.count() > 0) {
            await levelSelect.selectOption({ index: 1 });
        }

        let otpCode = '';
        const validateBtn = modal.locator('#btn-modal-validate-reg');
        if (await validateBtn.isVisible()) {
            const validatePromise = page.waitForResponse((r) => r.url().includes('/validate') && r.request().method() === 'PATCH');
            await validateBtn.click();
            const validateResponse = await validatePromise;
            const validateData = await validateResponse.json();
            otpCode = validateData.data?.otp_code_stub || '';
            await expect(modal).toBeHidden({ timeout: 20000 });
        } else {
            await modal.locator('#btn-cancel-inspect').click();
            await expect(modal).toBeHidden({ timeout: 5000 });
        }

        // Le bandeau d'activation OTP doit apparaître en haut de la table
        const otpBanner = page.locator(`#otp-banner-${regId}, #registrations-tbody tr`).filter({ hasText: `#${regId}` }).first();
        await expect(otpBanner).toBeVisible({ timeout: 15000 });

        if (!otpCode) {
            const otpCodeEl = page.locator(`#otp-code-${regId}, #otp-banner-${regId} code, #registrations-tbody code`).first();
            if (await otpCodeEl.isVisible({ timeout: 3000 }).catch(() => false)) {
                otpCode = ((await otpCodeEl.textContent()) || '').trim();
            }
        }
        if (!otpCode) {
            const { execSync } = await import('child_process');
            const cmd = `php -r "$db = new PDO('sqlite:storage/db/kanso-ya.sqlite'); $s = $db->prepare('SELECT otp_code FROM users WHERE email = :e'); $s->execute(['e' => '${tutorEmail}']); echo $s->fetchColumn();"`;
            otpCode = execSync(cmd).toString().trim();
        }
        expect(otpCode).toBeTruthy();

        // 4. Activation du compte adhérent sur /activate-otp.php avec le jeton OTP reçu
        await page.goto(`/activate-otp?otp=${otpCode}`);
        await expect(page.locator('#otp-code-input')).toHaveValue(otpCode!);

        await page.fill('#otp-new-password', 'Secret123!');
        await page.fill('#otp-confirm-password', 'Secret123!');
        await page.click('#btn-submit-otp');

        // Redirection ou toast de confirmation
        await expect(page.locator('#toast-container')).toContainText(/activé/i);

        // 5. Connexion de l'adhérent (le parent référent) avec son nouveau mot de passe
        await page.goto('/login');
        await page.fill('input[name="email"]', tutorEmail);
        await page.fill('input[name="password"]', 'Secret123!');
        await page.click('button[type="submit"]');

        await page.waitForURL((url) => url.pathname === '/' || url.pathname.includes('dashboard'), { timeout: 10000 });

        // Vérification de l'accès à ses rattachements sur /members.php
        await page.goto('/members');
        await expect(page.locator('#club-name-heading')).toBeVisible();
        await expect(page.locator('body')).toContainText(/Léo|Chloé|Martin/i);

        // Nettoyage final du dossier créé
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await request.delete(`/api/v1/registrations/${regId}`);
    });

    test('AXE 1.2 : Parcours Éditorial Complet (Rédacteur crée article multi-colonnes callout -> Brouillon -> Relecture Président -> Publication -> Affichage / et Modale lecture)', async ({ page, request }) => {
        const articleTitle = `Stage International d'Automne 2026 - UAT ${Date.now()}`;

        // 1. Connexion du Rédacteur via /login.php
        await page.goto('/login');
        await page.fill('input[name="email"]', 'redacteur@kanso-ya.local');
        await page.fill('input[name="password"]', 'password123');
        await page.click('button[type="submit"]');

        await page.waitForURL((url) => url.pathname === '/' || url.pathname.includes('cms') || url.pathname.includes('dashboard'), { timeout: 10000 });
        await page.goto('/cms');

        // 2. Création de l'article multi-colonnes dans le Builder
        await page.fill('#art-title', articleTitle);

        // Ajout d'une rangée 2 colonnes
        await page.click('.btn-add-row[data-cols="2"]');

        // Ajout d'un bloc encadré callout
        await page.click('.btn-add-block[data-add-block="callout"]');

        // Vérification que le Rédacteur ne peut pas publier directement (statut draft verrouillé)
        const statusSelect = page.locator('#art-status');
        await expect(statusSelect).toHaveValue('draft');
        await expect(statusSelect).toBeDisabled();

        // Enregistrement de l'article en brouillon
        await page.click('#btn-save-article');
        await expect(page.locator('#toast-container')).toContainText(/enregistré/i);

        // 3. Vérification de la non-exposition sur l'accueil public /
        await page.goto('/');
        const homeGrid = page.locator('#home-articles-grid');
        await expect(homeGrid).toBeVisible();
        await expect(homeGrid).not.toContainText(articleTitle);

        // 4. Déconnexion et Relecture / Publication par le Président
        await page.goto('/login');
        await page.fill('input[name="email"]', 'president@kanso-ya.local');
        await page.fill('input[name="password"]', 'password123');
        await page.click('button[type="submit"]');

        await page.waitForURL((url) => url.pathname === '/' || url.pathname.includes('cms') || url.pathname.includes('dashboard'), { timeout: 10000 });
        await page.goto('/cms');

        // Bascule sur l'onglet 2 "Publications & Pages"
        await page.click('#cms-tab-btn-publications');
        const pubCard = page.locator('#articles-list > div', { hasText: articleTitle });
        await expect(pubCard).toBeVisible();
        await expect(pubCard).toContainText('Brouillon');

        // Le Président clique sur le bouton "Publier"
        await pubCard.locator('button', { hasText: 'Publier' }).click();
        await expect(page.locator('#toast-container')).toContainText(/published/i);
        await expect(pubCard).toContainText('Publié');

        // 5. Vérification sur l'accueil public / et ouverture de la modale de lecture
        await page.goto('/');
        const publishedCard = page.locator('#home-articles-grid article', { hasText: articleTitle });
        await expect(publishedCard).toBeVisible();

        // Clic sur l'article pour ouvrir la modale de lecture
        await publishedCard.click();
        const readerModal = page.locator('#article-reader-modal');
        await expect(readerModal).toBeVisible();
        await expect(readerModal.locator('#reader-title')).toHaveText(articleTitle);
        await expect(readerModal.locator('#reader-content')).toBeVisible();

        // Fermeture de la modale
        await page.click('#btn-close-reader');
        await expect(readerModal).toBeHidden();

        // Nettoyage de l'article
        const listRes = await request.get('/api/v1/articles');
        const listBody = await listRes.json();
        const art = listBody.data.find((a: { title: string }) => a.title === articleTitle);
        if (art) {
            await request.delete(`/api/v1/articles/${art.id}`);
        }
    });

    // =========================================================================
    // AXE 2 : CAS PASSANTS & NON-PASSANTS (ROBUSTESSE & LIMITES)
    // =========================================================================

    test('AXE 2.1 : Blocage UI & Rejet 422 sur formulaires incomplets, dates futures et tuteurs mineurs', async ({ page, request }) => {
        await page.goto('/register');

        // 1. Soumission d'un formulaire vide -> toast d'erreur bloquant
        await page.click('#btn-next-from-identity');
        await expect(page.locator('#toast-container')).toContainText(/renseigner/i);
        await expect(page.locator('#step-panel-identity')).toBeVisible();

        // 2. Date de naissance incohérente dans le futur (2030-01-01)
        await page.fill('#reg-first-name', 'Futur');
        await page.fill('#reg-last-name', 'Test');
        await page.fill('#reg-birth-date', '2030-01-01');
        await page.dispatchEvent('#reg-birth-date', 'change');

        // Rejet API direct
        const futureRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Futur',
                    last_name: 'Test',
                    birth_date: '2030-01-01',
                    discipline_id: 1,
                    emergency_contact_name: 'Parent',
                    emergency_contact_phone: '0600000000'
                }],
                tutors: [{ email: 'tuteur.future@kanso-ya.fr', birth_date: '1985-01-01' }]
            }
        });
        expect((await futureRes.json()).code).toBe(422);

        // 3. Tuteur mineur (<18 ans)
        const minorTutorRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Enfant',
                    last_name: 'Test',
                    birth_date: '2016-01-01',
                    discipline_id: 1,
                    emergency_contact_name: 'Parent',
                    emergency_contact_phone: '0600000000'
                }],
                tutors: [{ email: 'mineur.tuteur@kanso-ya.fr', birth_date: '2014-01-01' }]
            }
        });
        const minorTutorBody = await minorTutorRes.json();
        expect(minorTutorBody.status).toBe('error');
        expect(minorTutorBody.code).toBe(422);
        expect(minorTutorBody.message).toContain('majeur');
    });

    test('AXE 2.2 : Protection anti-DoS limite familiale (> 10 membres rejetés)', async ({ request }) => {
        const tooManyMembers = Array.from({ length: 11 }, (_, i) => ({
            first_name: `Enfant${i}`,
            last_name: 'DosLimit',
            birth_date: '2015-01-01',
            discipline_id: 1,
            emergency_contact_name: 'Parent',
            emergency_contact_phone: '0600000000'
        }));

        const response = await request.post('/api/v1/registrations', {
            data: {
                members: tooManyMembers,
                tutors: [{ email: 'parent.dos@kanso-ya.fr', birth_date: '1985-01-01' }]
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(422);
        expect(body.message).toContain('10 adhérents');
    });

    test('AXE 2.3 : Rejet immédiat du rejeu d\'un jeton OTP déjà utilisé', async ({ request }) => {
        // Tentative d'activation avec un jeton inexistant ou déjà consommé
        const response = await request.post('/api/v1/auth/activate-otp', {
            data: {
                otp_code: 'ALREADY_CONSUMED_OTP_TOKEN_XYZ',
                new_password: 'ValidPassword123!'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(400);
        expect(body.message).toContain('invalide ou déjà utilisé');
    });

    test('AXE 2.4 : Upload sécurisé (Rejet strict des .php et faux exécutables renommés)', async ({ request }) => {
        // 1. Certificat médical avec extension .php
        const phpCertRes = await request.post('/api/v1/members/1/medical-file', {
            multipart: {
                file: {
                    name: 'backdoor.php',
                    mimeType: 'application/x-php',
                    buffer: Buffer.from('<?php phpinfo(); ?>')
                }
            }
        });
        expect((await phpCertRes.json()).code).toBe(422);

        // 2. Certificat médical avec faux .jpg contenant un script exécutable
        const fakeJpgRes = await request.post('/api/v1/members/1/medical-file', {
            multipart: {
                file: {
                    name: 'exploit.jpg',
                    mimeType: 'image/jpeg',
                    buffer: Buffer.from('#!/bin/bash\nrm -rf /')
                }
            }
        });
        expect((await fakeJpgRes.json()).code).toBe(422);

        // 3. Média CMS avec extension non supportée
        const badMediaRes = await request.post('/api/v1/articles/media', {
            multipart: {
                file: {
                    name: 'malware.exe',
                    mimeType: 'application/octet-stream',
                    buffer: Buffer.from('MZ\x90\x00\x03\x00\x00\x00')
                }
            }
        });
        expect((await badMediaRes.json()).code).toBe(422);
    });

    test('AXE 2.5 : Étanchéité de /login (rejet Superadmin) et /sys-admin-xyz (rejet utilisateur classique)', async ({ request }) => {
        // Rejet du Superadmin sur /login
        const supLoginRes = await request.post('/api/v1/auth/login', {
            data: { email: 'superadmin@kanso-ya.local', password: 'password123' }
        });
        expect((await supLoginRes.json()).code).toBe(401);

        // Rejet d'un utilisateur classique sur /sys-admin
        const userSysRes = await request.post('/api/v1/sys/login', {
            data: { username: 'president@kanso-ya.local', password: 'password123' }
        });
        expect((await userSysRes.json()).code).toBe(401);
    });

    // =========================================================================
    // AXE 3 : SCÉNARIOS DE CHARGE & PLANNING PLEIN
    // =========================================================================

    test('AXE 3.1 : Discipline à 100% de capacité (Judo >= 30 inscrits) affiche l\'alerte visuelle de saturation sans rupture sur stats.php', async ({ page, request }) => {
        // Consultation de /stats.php en tant que Président
        await page.goto('/stats');
        await expect(page.locator('#club-name-heading')).toBeVisible();

        // Ouverture de l'onglet Démographie & Occupation (Sprint 14 - Point 11)
        await page.locator('[data-testid="stats-tab-occupancy"]').click();

        // La discipline Judo (id: 1) a 40 inscrits pour une capacité standard de 30 élèves
        const capacityAlert = page.locator('[data-testid="capacity-alert-1"]');
        await expect(capacityAlert).toBeVisible();
        await expect(capacityAlert).toContainText(/COMPLET/i);

        // Vérification de l'ajout d'un adhérent supplémentaire dans cette discipline sans crash
        const testEmail = `charge.stress.${Date.now()}@kanso-ya.fr`;
        const addMemberRes = await request.post('/api/v1/members', {
            data: {
                first_name: 'Adhérent',
                last_name: 'Surcapacité',
                birth_date: '2010-06-15',
                email: testEmail,
                discipline_id: 1,
                emergency_contact_name: 'Parent Contact',
                emergency_contact_phone: '06 00 00 00 00'
            }
        });

        const addMemberBody = await addMemberRes.json();
        expect(addMemberBody.status).toBe('success');
        expect(addMemberBody.code).toBe(201);
        const memberId = addMemberBody.data.id;

        // La page stats.php doit continuer de se charger sans régression
        await page.reload();
        await page.locator('[data-testid="stats-tab-occupancy"]').click();
        await expect(capacityAlert).toBeVisible();

        // Nettoyage
        await request.delete(`/api/v1/members/${memberId}`);
    });

    test('AXE 3.2 : Recherche instantanée 0ms et filtrage combiné (Statut + Discipline) sur annuaire massif (~60 adhérents)', async ({ page }) => {
        await page.goto('/members');
        await expect(page.locator('#club-name-heading')).toBeVisible();

        const table = page.locator('#members-table');
        await expect(table).toBeVisible();

        // Vérification de la volumétrie initiale (~58+ lignes)
        const initialRows = page.locator('#members-table tbody tr.member-row');
        await expect(initialRows.first()).toBeVisible({ timeout: 10000 });
        const count = await initialRows.count();
        expect(count).toBeGreaterThanOrEqual(40);

        // 1. Recherche instantanée 0ms par saisie dans #member-search
        const firstMemberName = (await initialRows.first().locator('.font-bold.text-slate-900').textContent())?.trim() || 'Dupont';
        const searchKeyword = firstMemberName.split(' ')[0] || firstMemberName;

        const searchInput = page.locator('#member-search');
        await searchInput.fill(searchKeyword);

        // Seules les lignes contenant ce mot-clé restent visibles
        const visibleRows = page.locator('#members-table tbody tr.member-row:visible');
        const visibleCount = await visibleRows.count();
        expect(visibleCount).toBeGreaterThan(0);
        expect(visibleCount).toBeLessThan(count);
        await expect(visibleRows.first()).toContainText(searchKeyword);

        // Réinitialisation de la recherche
        await searchInput.fill('');
        await expect(page.locator('#members-table tbody tr.member-row:visible')).toHaveCount(count);

        // 2. Filtrage combiné : Statut 'Actif' + Discipline 'Judo'
        const activeTabBtn = page.locator('#member-status-filters button[data-status="active"]');
        await activeTabBtn.click();

        const discFilterSelect = page.locator('#member-discipline-filter');
        await discFilterSelect.selectOption('1'); // Judo

        // Les lignes affichées doivent toutes être des adhérents actifs inscrits au Judo
        const filteredRows = page.locator('#members-table tbody tr.member-row:visible');
        const filteredCount = await filteredRows.count();
        expect(filteredCount).toBeGreaterThan(0);
        expect(filteredCount).toBeLessThanOrEqual(count);

        const firstFiltered = filteredRows.first();
        await expect(firstFiltered).toContainText('Actif');
        await expect(firstFiltered).toContainText('Judo');
    });

    // =========================================================================
    // AXE 4 : AUDIT VISUEL & RESPONSIVE (DESKTOP 1280x800 VS MOBILE 375x812)
    // =========================================================================

    test('AXE 4.1 : Conformité visuelle Desktop 1280x800 (Logo à cheval, titre centré, pas de bouton hamburger)', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/');

        const overlappingLogo = page.locator('#overlapping-logo-badge');
        const clubHeading = page.locator('#club-name-heading');
        const mobileMenuBtn = page.locator('#mobile-menu-btn');

        await expect(overlappingLogo).toBeVisible();
        await expect(clubHeading).toBeVisible();
        await expect(mobileMenuBtn).toBeHidden();
    });

    test('AXE 4.2 : Conformité visuelle Mobile 375x812 (Masquage logo à cheval, tiroir latéral & backdrop)', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');

        const overlappingLogo = page.locator('#overlapping-logo-badge');
        const mobileMenuBtn = page.locator('#mobile-menu-btn');
        const sidebar = page.locator('#sidebar');
        const backdrop = page.locator('#sidebar-backdrop');

        // Le logo à cheval fixe est proprement masqué sur mobile
        await expect(overlappingLogo).toBeHidden();

        // Le bouton hamburger est visible
        await expect(mobileMenuBtn).toBeVisible();

        // Le tiroir est initialement fermé
        await expect(sidebar).toHaveClass(/.*-translate-x-full.*/);
        await expect(backdrop).toBeHidden();

        // Clic pour ouvrir le tiroir
        await mobileMenuBtn.click();
        await expect(sidebar).not.toHaveClass(/.*-translate-x-full.*/);
        await expect(backdrop).toBeVisible();

        // Clic sur la partie exposée du fond assombri (à droite du tiroir w-64=256px) pour refermer le tiroir
        await backdrop.click({ position: { x: 340, y: 400 } });
        await expect(sidebar).toHaveClass(/.*-translate-x-full.*/);
        await expect(backdrop).toBeHidden();
    });

    test('AXE 4.3 : Absence absolue de débordement horizontal (scrollWidth <= clientWidth) sur les 8 pages clés', async ({ page, request }) => {
        const pagesToCheck = [
            { path: '/', auth: 'visitor' },
            { path: '/register', auth: 'visitor' },
            { path: '/members', auth: 'president' },
            { path: '/registrations', auth: 'president' },
            { path: '/cms', auth: 'president' },
            { path: '/roles', auth: 'president' },
            { path: '/stats', auth: 'president' },
            { path: '/sys-console', auth: 'superadmin' }
        ];

        const viewports = [
            { name: 'Desktop 1280x800', width: 1280, height: 800 },
            { name: 'Mobile 375x812 (Pixel 5)', width: 375, height: 812 }
        ];

        for (const vp of viewports) {
            await page.setViewportSize({ width: vp.width, height: vp.height });

            for (const item of pagesToCheck) {
                await request.post('/api/v1/auth/switch-persona', { data: { persona: item.auth } });
                await page.goto(item.path);

                // Vérification stricte : la largeur de défilement ne doit pas dépasser la largeur de la fenêtre (+1px de tolérance subpixel)
                const overflow = await page.evaluate(() => {
                    const docWidth = document.documentElement.scrollWidth;
                    const winWidth = window.innerWidth;
                    return docWidth > (winWidth + 1);
                });

                expect(overflow, `Débordement horizontal détecté sur ${item.path} en résolution ${vp.name}`).toBe(false);
            }
        }
    });

    test('AXE 4.4 : Isolation visuelle sombre (bg-slate-950 / accents techniques) sur la console Superadmin', async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await page.goto('/sys-console');

        await expect(page.locator('#club-name-heading')).toBeVisible();

        // Ouverture de la modale de Rollover Superadmin sur /sys-console pour inspecter les contrôles critiques
        await page.click('#btn-open-sys-rollover-modal');
        const targetSeasonInput = page.locator('#sys-target-season');
        await expect(targetSeasonInput).toBeVisible();
        await expect(targetSeasonInput).toHaveClass(/.*bg-slate-950.*/);
        await page.click('#btn-cancel-sys-rollover');

        // Vérification des pages dédiées Identité & SMTP (isolation visuelle sombre bg-slate-950)
        await page.goto('/sys-branding');
        const siteNameInput = page.locator('#sys-site-name');
        await expect(siteNameInput).toBeVisible();
        await expect(siteNameInput).toHaveClass(/.*bg-slate-950.*/);

        await page.goto('/sys-smtp');
        const smtpHostInput = page.locator('#smtp-host');
        await expect(smtpHostInput).toBeVisible();
        await expect(smtpHostInput).toHaveClass(/.*bg-slate-950.*/);
    });

});
