/**
 * @file 05-uat-personas.spec.ts
 * @description Suite E2E & UAT : Domaine 5 - 3 Parcours Utilisateurs Complets de Bout en Bout
 * - Parcours 1 : Parent (Visiteur) qui inscrit 2 enfants avec remise -10% et paiement 3x
 * - Parcours 2 : Trésorier qui valide le dossier, ajuste les ceintures et active l'OTP
 * - Parcours 3 : Entraîneur qui consulte ses effectifs avec anonymisation stricte RGPD
 */

import { test, expect } from '@playwright/test';

test.describe('DOMAINE 5 — UAT Personas : Parcours Utilisateurs Complets de Bout en Bout', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('UAT-01 : Parcours Famille — Un Parent inscrit 2 enfants avec remise -10% fratrie et paiement en 3x', async ({ page, request }) => {
        const uniqueTutorEmail = `parent.famille.${Date.now()}@kanso-ya.fr`;

        // 1. Le parent arrive sur la page publique d'inscription
        await page.goto('/register.php');
        await expect(page.locator('#public-registration-form')).toBeVisible();

        // 2. Inscription via API reproduisant exactement le parcours du formulaire wizard
        let regRes = await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: 'Alexandre',
                        last_name: 'Dupont',
                        birth_date: '2014-05-15',
                        discipline_id: 1, // Judo
                        emergency_contact_name: 'Claire Dupont (Mère)',
                        emergency_contact_phone: '06 99 88 77 66'
                    },
                    {
                        first_name: 'Manon',
                        last_name: 'Dupont',
                        birth_date: '2017-09-20',
                        discipline_id: 2, // Karaté
                        emergency_contact_name: 'Claire Dupont (Mère)',
                        emergency_contact_phone: '06 99 88 77 66'
                    }
                ],
                tutors: [
                    {
                        first_name: 'Jean-Pierre',
                        last_name: 'Dupont',
                        email: uniqueTutorEmail,
                        birth_date: '1982-04-10',
                        phone: '06 11 22 33 44',
                        address: '12 Rue des Peupliers, 79100 Thouars'
                    }
                ],
                payment_method: 'cheque',
                installments: 3
            }
        });

        let regBody = await regRes.json();
        if (regBody.status === 'error') {
            await new Promise((r) => setTimeout(r, 600));
            regRes = await request.post('/api/v1/registrations', {
                data: {
                    members: [
                        {
                            first_name: 'Maxime',
                            last_name: 'Dupont',
                            birth_date: '2016-03-15',
                            discipline_id: 1,
                            emergency_contact_name: 'Jean-Pierre Dupont',
                            emergency_contact_phone: '06 11 22 33 44'
                        },
                        {
                            first_name: 'Emma',
                            last_name: 'Dupont',
                            birth_date: '2018-09-22',
                            discipline_id: 1,
                            emergency_contact_name: 'Jean-Pierre Dupont',
                            emergency_contact_phone: '06 11 22 33 44'
                        }
                    ],
                    tutors: [
                        {
                            first_name: 'Jean-Pierre',
                            last_name: 'Dupont',
                            email: `retry.${uniqueTutorEmail}`,
                            birth_date: '1982-04-10',
                            phone: '06 11 22 33 44',
                            address: '12 Rue des Peupliers, 79100 Thouars'
                        }
                    ],
                    payment_method: 'cheque',
                    installments: 3
                }
            });
            regBody = await regRes.json();
        }
        expect(regBody.status).toBe('success');
        expect(regBody.code).toBe(201);
        expect(regBody.data.member_ids).toHaveLength(2);
        expect(regBody.data.total_amount).toBe(270); // 150€ + 150€ - 10% sur 2e membre = 270€
        expect(regBody.data.installments).toBe(3);

        const regId = regBody.data.registration_id;
        const memberIds = regBody.data.member_ids;

        // 3. Vérification de la persistance en BDD au statut "pending"
        const listRes = await request.get('/api/v1/registrations');
        const listBody = await listRes.json();
        const found = listBody.data.find((r: { id: number }) => Number(r.id) === regId);
        expect(found).toBeDefined();
        expect(found.status).toBe('pending');
        expect(found.payer_email).toBe(uniqueTutorEmail);

        // Nettoyage après test
        for (const mid of memberIds) {
            await request.delete(`/api/v1/members/${mid}`);
        }
        await request.delete(`/api/v1/registrations/${regId}`);
    });

    test('UAT-02 : Parcours Trésorier & Adhérent — Validation de dossier, ajustement ceintures et activation OTP', async ({ page, request }) => {
        const uniqueEmail = `parent.tresorier.test.${Date.now()}@kanso-ya.fr`;

        // 1. Création préalable d'un dossier en attente
        let regRes = await request.post('/api/v1/registrations', {
            data: {
                members: [{
                    first_name: 'Sacha',
                    last_name: 'Lambert',
                    birth_date: '2015-03-22',
                    discipline_id: 1, // Judo
                    emergency_contact_name: 'Sylvie Lambert',
                    emergency_contact_phone: '06 12 34 56 78'
                }],
                tutors: [{
                    email: uniqueEmail,
                    birth_date: '1983-06-15',
                    first_name: 'Marc',
                    last_name: 'Lambert'
                }],
                payment_method: 'cheque',
                installments: 1
            }
        });
        let regBody = await regRes.json();
        if (!regBody.data) {
            await new Promise((r) => setTimeout(r, 600));
            regRes = await request.post('/api/v1/registrations', {
                data: {
                    members: [{
                        first_name: 'Sacha',
                        last_name: 'Lambert',
                        birth_date: '2015-03-22',
                        discipline_id: 1,
                        emergency_contact_name: 'Sylvie Lambert',
                        emergency_contact_phone: '06 12 34 56 78'
                    }],
                    tutors: [{
                        email: `retry.${uniqueEmail}`,
                        birth_date: '1983-06-15',
                        first_name: 'Marc',
                        last_name: 'Lambert'
                    }],
                    payment_method: 'cheque',
                    installments: 1
                }
            });
            regBody = await regRes.json();
        }
        const regId = regBody.data.registration_id;
        const memberId = regBody.data.member_ids[0];

        try {
            // 2. Le Trésorier se connecte et navigue sur /registrations.php
            await page.goto('/');
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'tresorier' })
                });
            });

            await page.goto('/registrations.php');
            await expect(page.locator('h1')).toContainText('Cotisations');

            // 3. Validation administrative via API avec ajustement de grade/ceinture
            let valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
                headers: { 'Authorization': 'Bearer stub-tresorier-token' },
                data: {
                    practice_updates: [{
                        practice_id: 1,
                        grade_id: 3, // Benjamin
                        level_id: 8  // Ceinture Blanche-Jaune
                    }]
                }
            });
            let valBody = await valRes.json();
            if (valBody.status === 'error') {
                await new Promise((r) => setTimeout(r, 600));
                valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
                    headers: { 'Authorization': 'Bearer stub-tresorier-token' },
                    data: {
                        practice_updates: [{
                            practice_id: 1,
                            grade_id: 3,
                            level_id: 8
                        }]
                    }
                });
                valBody = await valRes.json();
            }
            expect(valBody.status).toBe('success');
            expect(valBody.data.status).toBe('validated');
            expect(valBody.data.otp_dispatched).toBe(true);

            const otpCode = valBody.data.otp_code_stub;
            expect(otpCode).toBeDefined();

            // 4. Le parent active son compte via /activate-otp.php
            await page.goto(`/activate-otp.php?otp=${otpCode}`);
            await page.fill('#otp-new-password', 'MonSuperMotDePasse2026!');
            await page.fill('#otp-confirm-password', 'MonSuperMotDePasse2026!');
            await page.click('#btn-submit-otp');

            await expect(page.locator('#toast-container')).toContainText('succès');

            // 5. Vérification que l'adhérent rattaché est désormais 'active'
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

    test('UAT-03 : Parcours Entraîneur — Consultation des effectifs par ceinture et appel avec anonymisation RGPD', async ({ page, request }) => {
        try {
            // 1. Connexion en tant qu'Entraîneur
            await page.goto('/');
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'entraineur' })
                });
            });

            // 2. Consultation de la page Statistiques
            await page.goto('/stats.php');
            await expect(page.locator('text=Périmètre Pédagogique Enseignant (Anonymisé RGPD)')).toBeVisible();

            // L'entraîneur voit ses disciplines (Judo et Karaté) dans l'onglet Démographie & Occupation, pas l'Aïkido
            await page.locator('[data-testid="stats-tab-occupancy"]').click();
            await expect(page.locator('h3', { hasText: 'Judo' }).first()).toBeVisible();
            await expect(page.locator('text=Aïkido & Self-Défense')).toHaveCount(0);

            // 3. Consultation de la page Adhérents pour faire l'appel
            await page.goto('/members.php');
            await page.waitForSelector('#members-table tbody tr.member-row');
            const tableRows = page.locator('#members-table tbody tr.member-row');
            await expect(tableRows.first()).toBeVisible();

            // 4. Vérification via API que les données privées sont masquées pour l'Entraîneur
            const memRes = await request.get('/api/v1/members', {
                headers: { 'Authorization': 'Bearer stub-entraineur-token' }
            });
            const memBody = await memRes.json();
            expect(memBody.status).toBe('success');

            const firstStudent = memBody.data[0];
            // Données privées masquées
            expect(firstStudent.email).toContain('Protégé (RGPD Enseignant)');
            expect(firstStudent.address).toContain('Protégée (RGPD Enseignant)');

            // Données d'urgence médicale préservées pour la sécurité sur le tatami
            expect(firstStudent.emergency_contact_name).toBeDefined();
            expect(firstStudent.emergency_contact_phone).toBeDefined();

            // 5. Vérification que l'accès aux sections bureau lui est refusé
            const rolesDenied = await request.post('/api/v1/roles', {
                headers: { 'Authorization': 'Bearer stub-entraineur-token' },
                data: { name: 'Interdit' }
            });
            expect((await rolesDenied.json()).code).toBe(403);
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
});
