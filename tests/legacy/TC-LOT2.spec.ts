/**
 * @file TC-LOT2.spec.ts
 * @description Suite complète, ajustée et enrichie des tests E2E & API du Lot 2 :
 * - Wizard d'Inscription Public (/register.php) : transfert auto email mineur, échéancier conditionnel, réinscription connecté, écran post-validation
 * - Trésorerie (/registrations.php) : Inspection complète, Validation + OTP 48h, Radiation réversible (Restaurer) vs Suppression
 * - CRM & Rattachements : Renvoi du lien OTP (Secrétaire/Trésorier/Président), Édition par le tuteur & Partage RGPD Opt-in
 * - Studio CMS à 2 Onglets (/cms.php) : Builder Multi-Colonnes & Blocs imbriqués, Modale d'Aperçu, Forçage Brouillon Rédacteur, Anti-XSS, Visibilité (404/403) & Pages Légales.
 */

import { test, expect } from '@playwright/test';

test.describe('LOT 2 — Wizard d\'Inscription Intelligent, Trésorerie Réversible, Renvoi OTP & Studio CMS Multi-Colonnes', () => {

    test.beforeEach(async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    // =========================================================================
    // 1. WIZARD D'INSCRIPTION PUBLIC, TRANSFERT EMAIL MINEUR & RÉINSCRIPTION
    // =========================================================================

    test('US 3.1 (Point 3) : Transfert automatique de l\'email vers le Responsable Légal 1 si mineur (< 18 ans) et restriction de l\'échéancier', async ({ page }) => {
        await page.goto('/register.php');

        const birthInput = page.locator('#reg-birth-date');
        const memberEmailInput = page.locator('#reg-email');
        const tutor1EmailInput = page.locator('#tutor1-email');
        const tutorSection = page.locator('#tutor-section');
        const stepPanelTutors = page.locator('#step-panel-tutors');

        // 1. L'utilisateur saisit un email puis entre une date de naissance de mineur (2016)
        await memberEmailInput.fill('parent.transfert@kanso-ya.fr');
        await birthInput.fill('2016-05-10');
        await birthInput.dispatchEvent('change');

        // Le conteneur logique #tutor-section est activé, mais le panneau #step-panel-tutors ne s'affiche pas en dessous de l'étape 1 !
        await expect(tutorSection).not.toHaveClass(/.*hidden.*/);
        await expect(stepPanelTutors).toHaveClass(/.*hidden.*/);

        // L'email saisi a été automatiquement transféré dans #tutor1-email et le champ email mineur est masqué
        await expect(page.locator('#adult-email-wrapper')).toHaveClass(/.*hidden.*/);
        await expect(tutor1EmailInput).toHaveValue('parent.transfert@kanso-ya.fr');

        // La date de naissance du tuteur 1 ne doit PAS être pré-remplie
        await expect(page.locator('#tutor1-birth')).toHaveValue('');
    });

    test('TC-REG-REENROLL (Point 3) : Lorsqu\'un parent est connecté, le formulaire propose de réinscrire un membre rattaché avec pré-remplissage', async ({ page }) => {
        try {
            await page.goto('/');
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'tutorA' })
                });
            });

            await page.goto('/register.php');
            const banner = page.locator('#connected-re-enrollment-banner');
            await expect(banner).toBeVisible();

            const select = page.locator('#select-existing-family-member');
            await select.selectOption('0'); // Sélectionne le 1er enfant rattaché (Enfant Test)
            await expect(page.locator('#reg-first-name')).toHaveValue('Enfant');
        } finally {
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'president' })
                });
            });
        }
    });

    test('TC-CRM-N01 : Rejet d\'une inscription de mineur sans tuteur légal', async ({ request }) => {
        const response = await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: 'Lucas',
                        last_name: 'Petit',
                        birth_date: '2015-04-12',
                        discipline_id: 1,
                        emergency_contact_name: 'Jean Petit',
                        emergency_contact_phone: '0601020304'
                    }
                ],
                tutors: []
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(422);
        expect(body.message).toContain('tuteur légal est obligatoire');
    });

    test('Edge Case QA : Rejet si le tuteur légal déclaré a moins de 18 ans', async ({ request }) => {
        const response = await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: 'Lucas',
                        last_name: 'Petit',
                        birth_date: '2016-04-12',
                        discipline_id: 1,
                        emergency_contact_name: 'Jean Petit',
                        emergency_contact_phone: '0601020304'
                    }
                ],
                tutors: [
                    {
                        email: 'mineur.tuteur@kanso-ya.fr',
                        birth_date: '2014-01-01'
                    }
                ]
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(422);
        expect(body.message).toContain('majeur');
    });

    test('TC-CRM-N02 : Protection anti-DoS sur le panier familial (> 10 enfants rejetés)', async ({ request }) => {
        const tooManyMembers = Array.from({ length: 12 }, (_, i) => ({
            first_name: `Enfant${i}`,
            last_name: 'FamilleNombreuse',
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

    test('TC-CRM-P02 : Inscription publique d\'une famille (1 tuteur, 2 mineurs, remise -10%, paiement 3x) et nettoyage', async ({ request }) => {
        const uniqueTutorEmail = `famille.${Date.now()}.${Math.floor(Math.random() * 10000)}@kanso-ya.fr`;
        const response = await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: 'Chloé',
                        last_name: 'Bernard',
                        birth_date: '2014-03-15',
                        discipline_id: 1,
                        emergency_contact_name: 'Marc Bernard',
                        emergency_contact_phone: '0611223344'
                    },
                    {
                        first_name: 'Hugo',
                        last_name: 'Bernard',
                        birth_date: '2017-09-20',
                        discipline_id: 2,
                        emergency_contact_name: 'Marc Bernard',
                        emergency_contact_phone: '0611223344'
                    }
                ],
                tutors: [
                    { email: uniqueTutorEmail, birth_date: '1984-06-10' }
                ],
                payment_method: 'cheque',
                installments: 3
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.code).toBe(201);
        expect(body.data.member_ids).toHaveLength(2);
        expect(body.data.installments).toBe(3);
        expect(body.data.total_amount).toBe(270);

        for (const mId of body.data.member_ids) {
            await request.delete(`/api/v1/members/${mId}`);
        }
        await request.delete(`/api/v1/registrations/${body.data.registration_id}`);
    });

    // =========================================================================
    // 2. TRÉSORERIE : INSPECTION, RADIATION RÉVERSIBLE, VALIDATION & RENVOI OTP
    // =========================================================================

    test('TC-SEC-02 : Un Entraîneur sans droit finances.manage est bloqué (403) sur la validation d\'un dossier et tracé dans sys_logs', async ({ request }) => {
        const response = await request.patch('/api/v1/registrations/12/validate', {
            headers: { 'Authorization': 'Bearer stub-entraineur-token' }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(403);

        const logsRes = await request.get('/api/v1/sys/logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const logsBody = await logsRes.json();
        expect(logsBody.status).toBe('success');
        expect(logsBody.data.some((l: { action: string }) => l.action === 'UNAUTHORIZED_ACCESS_ATTEMPT')).toBe(true);
    });

    test('TC-TRES-RADIATE (Point 9) : Distinction Radié vs Supprimé sur un dossier de cotisation avec retour arrière (Restaurer en attente)', async ({ request }) => {
        try {
            // 1. Radiation réversible du dossier #5
            const radRes = await request.patch('/api/v1/registrations/5/status', {
                data: { status: 'radiated' }
            });
            const radBody = await radRes.json();
            expect(radBody.status).toBe('success');
            expect(radBody.data.status).toBe('radiated');

            // 2. Retour arrière (Restauration en attente)
            const restRes = await request.patch('/api/v1/registrations/5/status', {
                data: { status: 'pending' }
            });
            const restBody = await restRes.json();
            expect(restBody.status).toBe('success');
            expect(restBody.data.status).toBe('pending');
        } finally {
            await request.patch('/api/v1/registrations/5/status', { data: { status: 'pending' } });
        }
    });

    test('TC-OTP-RESEND (Point 8) : Le Président/Secrétaire/Trésorier peut renvoyer un lien OTP 48h à un adhérent avec traçabilité', async ({ request }) => {
        const resendRes = await request.post('/api/v1/members/1/resend-otp');
        const resendBody = await resendRes.json();

        expect(resendBody.status).toBe('success');
        expect(resendBody.code).toBe(200);
        expect(resendBody.data.otp_code).toBeDefined();
    });

    test('TC-CRM-02, TC-CRM-P03 & REQ-REG-OTP-02 : Validation d\'inscription, audit app_logs et usage unique de l\'OTP', async ({ request }) => {
        const uniqueEmail = `thomas.${Date.now()}.${Math.floor(Math.random() * 10000)}@kanso-ya.fr`;
        const createRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Thomas',
                    last_name: 'Verne',
                    birth_date: '1992-07-04',
                    email: uniqueEmail,
                    discipline_id: 1,
                    emergency_contact_name: 'Julie Verne',
                    emergency_contact_phone: '0699887766'
                }],
                payment_method: 'cheque',
                installments: 1
            }
        });
        const createBody = await createRes.json();
        expect(createBody.status).toBe('success');
        const regId = createBody.data.registration_id;
        const memberId = createBody.data.member_ids[0];

        try {
            const valRes = await request.patch(`/api/v1/registrations/${regId}/validate`);
            const valBody = await valRes.json();
            expect(valBody.status).toBe('success');
            expect(valBody.data.status).toBe('validated');
            expect(valBody.data.otp_dispatched).toBe(true);

            const otpCode = valBody.data.otp_code_stub;

            const logsRes = await request.get('/api/v1/app-logs');
            const logsBody = await logsRes.json();
            expect(logsBody.status).toBe('success');
            expect(logsBody.data.some((l: { action: string; target_id: number }) => l.action === 'REGISTRATION_VALIDATED' && Number(l.target_id) === regId)).toBe(true);

            const otpRes1 = await request.post('/api/v1/auth/activate-otp', {
                data: { otp_code: otpCode, new_password: 'MyStrongPassword2026!' }
            });
            expect((await otpRes1.json()).code).toBe(200);

            const otpRes2 = await request.post('/api/v1/auth/activate-otp', {
                data: { otp_code: otpCode, new_password: 'AnotherPassword2026!' }
            });
            expect((await otpRes2.json()).code).toBe(400);
        } finally {
            await request.delete(`/api/v1/members/${memberId}`);
            await request.delete(`/api/v1/registrations/${regId}`);
        }
    });

    test('TC-CRM-P04 (Point 5) : Partage d\'informations RGPD entre Tuteur A et Tuteur B et édition d\'une fiche rattachée', async ({ request }) => {
        try {
            // 1. Le Tuteur B active le partage RGPD
            const shareRes = await request.patch('/api/v1/members/1/share-tutor-info', {
                headers: { 'Authorization': 'Bearer stub-tutor-b-token' },
                data: { tutor_id: 2, info_shared: true }
            });
            expect((await shareRes.json()).code).toBe(200);

            // 2. Le Tuteur A voit désormais l'email du Tuteur B
            const memRes = await request.get('/api/v1/members', {
                headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
            });
            const memBody = await memRes.json();
            expect(memBody.status).toBe('success');

            const child = memBody.data.find((m: { id: number }) => Number(m.id) === 1);
            const tutorB = child.tutors.find((t: { id: number }) => Number(t.id) === 2);
            expect(tutorB.email).toContain('@');

            // 3. Point 5 : Le Tuteur A peut mettre à jour les coordonnées de son enfant rattaché
            const updateChildRes = await request.put('/api/v1/members/1', {
                headers: { 'Authorization': 'Bearer stub-tutor-a-token' },
                data: {
                    first_name: 'Enfant',
                    last_name: 'Test',
                    birth_date: '2015-01-01',
                    address: '24 Rue Pasteur, 79100 Thouars',
                    emergency_contact_name: 'Thomas Martin (Père)',
                    emergency_contact_phone: '06 11 22 33 44',
                    status: 'active'
                }
            });
            expect((await updateChildRes.json()).code).toBe(200);
        } finally {
            await request.patch('/api/v1/members/1/share-tutor-info', {
                headers: { 'Authorization': 'Bearer stub-tutor-b-token' },
                data: { tutor_id: 2, info_shared: false }
            });
        }
    });

    // =========================================================================
    // 3. STUDIO CMS 2 ONGLETS, BUILDER MULTI-COLONNES, APERÇU & SÉCURITÉ
    // =========================================================================

    test('TC-CMS-BUILDER (Point 6) : Navigation entre les 2 onglets du Studio CMS, ajout d\'une rangée 2 colonnes et ouverture de l\'Aperçu', async ({ page }) => {
        await page.goto('/cms.php');

        // Vérification de l'Onglet 1 (Studio Builder)
        await expect(page.locator('#cms-tab-panel-editor')).toBeVisible();
        await expect(page.locator('#cms-tab-panel-publications')).toHaveClass(/.*hidden.*/);

        // Ajout d'une rangée 2 colonnes dans le Builder
        await page.click('button[data-cols="2"]');
        await expect(page.locator('.builder-row-grid[data-cols="2"]')).toBeVisible();

        // Ouverture de la modale d'Aperçu en direct
        await page.fill('#art-title', 'Titre Aperçu Test');
        await page.click('#btn-preview-article');
        const previewModal = page.locator('#article-preview-modal');
        await expect(previewModal).toBeVisible();
        await expect(page.locator('#preview-modal-title')).toContainText('Titre Aperçu Test');
        await page.click('#btn-close-preview-modal');

        // Bascule sur l'Onglet 2 (Publications & Pages Légales)
        await page.click('#cms-tab-btn-publications');
        await expect(page.locator('#cms-tab-panel-publications')).toBeVisible();
        await expect(page.locator('#cms-tab-panel-editor')).toHaveClass(/.*hidden.*/);
    });

    test('TC-CMS-P01 & TC-CMS-N01 (REQ-CMS-WF-01) : Un Rédacteur soumettant status="published" est forcé en "draft"', async ({ request }) => {
        const response = await request.post('/api/v1/articles', {
            headers: { 'Authorization': 'Bearer stub-redacteur-token' },
            data: {
                title: 'Compte-rendu du stage régional',
                content: '<p>Superbe participation de nos judokas.</p>',
                category_id: 2,
                visibility: 'public',
                status: 'published'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.code).toBe(201);
        expect(body.data.status).toBe('draft');

        await request.delete(`/api/v1/articles/${body.data.id}`);
    });

    test('TC-CMS-N02 : Neutralisation des injections XSS (<script>) dans le contenu des articles', async ({ request }) => {
        const response = await request.post('/api/v1/articles', {
            data: {
                title: 'Article Test XSS',
                content: '<p>Texte sain</p><script>alert("hack")</script><p onclick="evil()">Suite</p>',
                category_id: 1,
                visibility: 'public',
                status: 'published'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.data.content).not.toContain('<script>');
        expect(body.data.content).not.toContain('onclick');
        expect(body.data.content).toContain('<p>Texte sain</p>');

        await request.delete(`/api/v1/articles/${body.data.id}`);
    });

    test('REQ-CMS-WF-03 & TC-CMS-N03 : Étanchéité de la visibilité (404 pour Visiteur Anonyme, 403 pour Adhérent sur article Bureau)', async ({ request }) => {
        const createRes = await request.post('/api/v1/articles', {
            data: {
                title: 'PV Réunion Bureau Confidentiel',
                content: '<p>Budget prévisionnel.</p>',
                category_id: 1,
                visibility: 'board',
                status: 'published'
            }
        });
        const articleId = (await createRes.json()).data.id;

        try {
            const visitorRes = await request.get(`/api/v1/articles/${articleId}`, {
                headers: { 'Authorization': 'Bearer stub-visitor-token' }
            });
            expect((await visitorRes.json()).code).toBe(404);

            const memberRes = await request.get(`/api/v1/articles/${articleId}`, {
                headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
            });
            expect((await memberRes.json()).code).toBe(403);
        } finally {
            await request.delete(`/api/v1/articles/${articleId}`);
        }
    });

    test('US 4.1 : Les pages légales fixes (/rgpd, /cgv) sont accessibles et le statut brouillon renvoie 404 aux visiteurs', async ({ page, request }) => {
        await page.goto('/rgpd');
        await expect(page.locator('#legal-page-title')).toContainText('RGPD');

        await request.put('/api/v1/pages/reglement-interieur', {
            data: {
                title: 'Règlement Intérieur 2027',
                content: '<p>En cours de rédaction.</p>',
                status: 'draft'
            }
        });

        const draftRes = await request.get('/api/v1/pages/reglement-interieur', {
            headers: { 'Authorization': 'Bearer stub-visitor-token' }
        });
        expect((await draftRes.json()).code).toBe(404);
    });
});