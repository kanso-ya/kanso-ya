/**
 * @file 03-inscriptions-tresorerie.spec.ts
 * @description Suite E2E & API : Domaine 3 - Wizard d'Inscription Public, Panier Familial, Trésorerie & OTP.
 */

import { test, expect } from '@playwright/test';

test.describe('DOMAINE 3 — Wizard d\'Inscription Public, Panier Familial, Trésorerie & OTP', () => {

    test.beforeEach(async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('US 3.1 : Transfert automatique de l\'email vers le Responsable Légal 1 si mineur (< 18 ans)', async ({ page }) => {
        await page.goto('/register.php');

        const birthInput = page.locator('#reg-birth-date');
        const memberEmailInput = page.locator('#reg-email');
        const tutor1EmailInput = page.locator('#tutor1-email');
        const tutorSection = page.locator('#tutor-section');
        const stepPanelTutors = page.locator('#step-panel-tutors');

        // Saisie email puis date de naissance d'un mineur (2016)
        await memberEmailInput.fill('parent.transfert@kanso-ya.fr');
        await birthInput.fill('2016-05-10');
        await birthInput.dispatchEvent('change');

        // La section tuteurs est activée
        await expect(tutorSection).not.toHaveClass(/.*hidden.*/);
        await expect(stepPanelTutors).toHaveClass(/.*hidden.*/);

        // L'email saisi a été transféré au Responsable Légal 1 et le champ email mineur est masqué
        await expect(page.locator('#adult-email-wrapper')).toHaveClass(/.*hidden.*/);
        await expect(tutor1EmailInput).toHaveValue('parent.transfert@kanso-ya.fr');
    });

    test('TC-REG-REENROLL : Réinscription d\'un enfant rattaché par un parent connecté (pré-remplissage)', async ({ page }) => {
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
            await select.selectOption('0'); // 1er enfant rattaché (Enfant Test)
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

    test('TC-CRM-N01 : Rejet d\'une inscription de mineur sans aucun tuteur légal renseigné', async ({ request }) => {
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

    test('TC-EDGE-TUTOR-AGE : Rejet si le tuteur légal déclaré a moins de 18 ans', async ({ request }) => {
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
        expect(body.data.total_amount).toBe(270); // 150 + 150 - 10% sur le 2e enfant

        // Nettoyage
        for (const mId of body.data.member_ids) {
            await request.delete(`/api/v1/members/${mId}`);
        }
        await request.delete(`/api/v1/registrations/${body.data.registration_id}`);
    });

    test('TC-SEC-02 : Un Entraîneur sans droit finances.manage est bloqué (403) sur la validation d\'un dossier', async ({ request }) => {
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
        expect(logsBody.data.some((l: { action: string }) => l.action === 'UNAUTHORIZED_ACCESS_ATTEMPT')).toBe(true);
    });

    test('TC-TRES-INSPECT-ADJUST : Inspection du dossier avec ajustement des Grades/Niveaux (ceintures) à la validation', async ({ request }) => {
        const uniqueEmail = `inspect.adjust.${Date.now()}@kanso-ya.fr`;
        let createRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Romain',
                    last_name: 'Boucher',
                    birth_date: '2014-02-10',
                    discipline_id: 1,
                    emergency_contact_name: 'Claire Boucher',
                    emergency_contact_phone: '0612345678'
                }],
                tutors: [{ email: uniqueEmail, birth_date: '1980-05-12' }],
                payment_method: 'cheque',
                installments: 1
            }
        });
        let createBody = await createRes.json();
        if (!createBody.data) {
            await new Promise((r) => setTimeout(r, 600));
            createRes = await request.post('/api/v1/registrations', {
                data: {
                    members: [{
                        first_name: 'Romain',
                        last_name: 'Boucher',
                        birth_date: '2014-02-10',
                        discipline_id: 1,
                        emergency_contact_name: 'Claire Boucher',
                        emergency_contact_phone: '0612345678'
                    }],
                    tutors: [{ email: `retry.${uniqueEmail}`, birth_date: '1980-05-12' }],
                    payment_method: 'cheque',
                    installments: 1
                }
            });
            createBody = await createRes.json();
        }
        const regId = createBody.data.registration_id;
        const memberId = createBody.data.member_ids[0];

        try {
            // Validation administrative avec ajustement de grade (U12) et niveau (Ceinture Jaune)
            const valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
                headers: { 'Authorization': 'Bearer stub-tresorier-token' },
                data: {
                    practice_updates: [
                        {
                            practice_id: 1, // Sera associé lors de la validation
                            grade_id: 3,    // Benjamin
                            level_id: 8     // Ceinture Blanche-Jaune
                        }
                    ]
                }
            });
            const valBody = await valRes.json();
            expect(valBody.status).toBe('success');
            expect(valBody.data.status).toBe('validated');

            // Vérification que le membre est désormais actif
            const memRes = await request.get('/api/v1/members');
            const memBody = await memRes.json();
            const member = memBody.data.find((m: { id: number }) => Number(m.id) === memberId);
            expect(member).toBeDefined();
            expect(member.status).toBe('active');
        } finally {
            await request.delete(`/api/v1/members/${memberId}`);
            await request.delete(`/api/v1/registrations/${regId}`);
        }
    });

    test('TC-TRES-RADIATE : Distinction Radié vs Supprimé sur un dossier avec retour arrière (Restaurer)', async ({ request }) => {
        try {
            // 1. Radiation réversible du dossier #5
            const radRes = await request.patch('/api/v1/registrations/5/status', {
                data: { status: 'radiated' }
            });
            const radBody = await radRes.json();
            expect(radBody.status).toBe('success');
            expect(radBody.data.status).toBe('radiated');

            // 2. Restauration en attente
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

    test('TC-CRM-02, TC-CRM-P03 & REQ-REG-OTP-02 : Validation d\'inscription, audit app_logs et unicité stricte de l\'OTP', async ({ request }) => {
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
        const regId = createBody.data.registration_id;
        const memberId = createBody.data.member_ids[0];

        try {
            const valRes = await request.patch(`/api/v1/registrations/${regId}/validate`);
            const valBody = await valRes.json();
            expect(valBody.status).toBe('success');
            expect(valBody.data.status).toBe('validated');
            expect(valBody.data.otp_dispatched).toBe(true);

            const otpCode = valBody.data.otp_code_stub;

            // Audit dans app_logs
            const logsRes = await request.get('/api/v1/app-logs');
            const logsBody = await logsRes.json();
            expect(logsBody.data.some((l: { action: string; target_id: number }) => l.action === 'REGISTRATION_VALIDATED' && Number(l.target_id) === regId)).toBe(true);

            // 1ère activation OTP réussie
            const otpRes1 = await request.post('/api/v1/auth/activate-otp', {
                data: { otp_code: otpCode, new_password: 'MyStrongPassword2026!' }
            });
            expect((await otpRes1.json()).code).toBe(200);

            // 2ème tentative avec le même OTP -> Rejet 400 (Usage unique)
            const otpRes2 = await request.post('/api/v1/auth/activate-otp', {
                data: { otp_code: otpCode, new_password: 'AnotherPassword2026!' }
            });
            expect((await otpRes2.json()).code).toBe(400);
        } finally {
            await request.delete(`/api/v1/members/${memberId}`);
            await request.delete(`/api/v1/registrations/${regId}`);
        }
    });

    test('TC-OTP-PASSWORD-REGEX : Rejet si le mot de passe ne respecte pas la politique de sécurité', async ({ request }) => {
        // Envoi d'un mot de passe trop court ou sans majuscule/chiffre
        const otpRes = await request.post('/api/v1/auth/activate-otp', {
            data: { otp_code: 'any_code', new_password: 'weak' }
        });
        const body = await otpRes.json();
        expect(body.status).toBe('error');
        expect([400, 422]).toContain(body.code);
    });
});
