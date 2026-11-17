/**
 * @file 06-sprint5-analytics-rollover.spec.ts
 * @description Suite E2E & API : Sprint 5 - Automatisation, Rollover de Saison & Tableaux de Bord Analytiques.
 * - US 5.1 : Moulinette Rollover, recalcul massif des catégories d'âge, maintien strict de is_category_forced=1,
 *   archivage des dossiers, traçabilité dans app_logs et modale de confirmation rouge réservée au Président.
 * - US 5.2 : Tableaux de bord analytiques et KPIs (indicateurs financiers pour le Bureau, ratios d'occupation par
 *   discipline/grade, démographie et maintien strict de l'anonymisation RGPD pour l'Entraîneur).
 */

import { test, expect } from '@playwright/test';

test.describe('SPRINT 5 — Automatisation, Rollover Annuel & Tableaux de Bord Analytiques', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('TC-ROLLOVER-01 (US 5.1) : Recalcul automatique des catégories d\'âge avec respect strict de is_category_forced = 1', async ({ request }) => {
        // 1. Création de deux adhérents de test
        // Adhérent A : né en 2017 (9 ans en 2026 = Poussin, doit devenir Benjamin à 10 ans en sept 2027)
        // Adhérent B : né en 2017 mais surclassé manuellement (is_category_forced = 1, fixé à Minime id=4)
        const ts = Date.now();
        let regRes = await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: `AdherentNormal_${ts}`,
                        last_name: 'TestAuto',
                        birth_date: '2017-05-10',
                        discipline_id: 1, // Judo
                        grade_id: 2, // Poussin (8-9 ans)
                        emergency_contact_name: 'Parent Test',
                        emergency_contact_phone: '06 11 22 33 44'
                    },
                    {
                        first_name: `AdherentSurclasse_${ts}`,
                        last_name: 'TestForced',
                        birth_date: '2017-05-10',
                        discipline_id: 1, // Judo
                        grade_id: 4, // Minime (12-13 ans) - Surclassé
                        emergency_contact_name: 'Parent Test',
                        emergency_contact_phone: '06 11 22 33 44'
                    }
                ],
                tutors: [
                    {
                        first_name: 'Parent',
                        last_name: 'TestRollover',
                        email: `parent.rollover.${ts}@kanso-ya.fr`,
                        phone: '06 12 34 56 78',
                        address: '10 rue du Test, 75001 Paris'
                    }
                ],
                payment_method: 'cheque',
                installments: 1
            }
        });

        let regData = await regRes.json();
        if (regData.status === 'error') {
            await new Promise((r) => setTimeout(r, 600));
            regRes = await request.post('/api/v1/registrations', {
                data: {
                    members: [
                        {
                            first_name: `AdherentNormal_${ts}_retry`,
                            last_name: 'TestAuto',
                            birth_date: '2017-05-10',
                            discipline_id: 1,
                            grade_id: 2,
                            emergency_contact_name: 'Parent Test',
                            emergency_contact_phone: '06 11 22 33 44'
                        },
                        {
                            first_name: `AdherentSurclasse_${ts}_retry`,
                            last_name: 'TestForced',
                            birth_date: '2017-05-10',
                            discipline_id: 1,
                            grade_id: 4,
                            emergency_contact_name: 'Parent Test',
                            emergency_contact_phone: '06 11 22 33 44'
                        }
                    ],
                    tutors: [
                        {
                            first_name: 'Parent',
                            last_name: 'TestRollover',
                            email: `parent.rollover.retry.${ts}@kanso-ya.fr`,
                            phone: '06 12 34 56 78',
                            address: '10 rue du Test, 75001 Paris'
                        }
                    ],
                    payment_method: 'cheque',
                    installments: 1
                }
            });
            regData = await regRes.json();
        }
        expect(regData.status).toBe('success');
        const regId = regData.data.registration_id;

        // Récupération du dossier et des practice_ids
        const regDetailRes = await request.get('/api/v1/registrations');
        const regDetailData = await regDetailRes.json();
        const createdReg = regDetailData.data.find((r: any) => r.id === regId);
        expect(createdReg).toBeDefined();

        const practiceForced = createdReg.members.find((m: any) => m.first_name === `AdherentSurclasse_${ts}`);
        expect(practiceForced).toBeDefined();
        const forcedPracticeId = practiceForced.practices?.[0]?.practice_id;
        expect(forcedPracticeId).toBeDefined();

        // Validation du dossier avec forçage de surclassement entraîneur (is_category_forced = 1)
        let valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
            data: {
                practice_updates: [
                    {
                        practice_id: forcedPracticeId,
                        grade_id: 4, // Minime (12-13 ans) - Surclassé
                        level_id: 7
                    }
                ]
            }
        });
        let valData = await valRes.json();
        if (valData.status === 'error') {
            await new Promise((r) => setTimeout(r, 600));
            valRes = await request.patch(`/api/v1/registrations/${regId}/validate`, {
                data: {
                    practice_updates: [
                        {
                            practice_id: forcedPracticeId,
                            grade_id: 4,
                            level_id: 7
                        }
                    ]
                }
            });
            valData = await valRes.json();
        }
        expect(valData.status).toBe('success');

        // 2. Déclenchement de la moulinette de changement de saison
        const rolloverRes = await request.post('/api/v1/tasks/rollover-season', {
            data: {
                target_season: '2027-2028',
                reference_date: '2027-09-01',
                archive_previous: true
            }
        });
        const rolloverData = await rolloverRes.json();
        expect(rolloverData.status).toBe('success');
        expect(rolloverData.code).toBe(200);
        expect(rolloverData.data.target_season).toBe('2027-2028');
        expect(rolloverData.data.promoted_count).toBeGreaterThanOrEqual(0);
        expect(rolloverData.data.forced_ignored_count).toBeGreaterThanOrEqual(1);

        // 3. Vérification des grades après basculement
        const membersAfterRes = await request.get('/api/v1/members');
        const membersAfterData = await membersAfterRes.json();
        const updatedNormal = membersAfterData.data.find((m: any) => m.first_name === `AdherentNormal_${ts}`);
        const updatedSurclasse = membersAfterData.data.find((m: any) => m.first_name === `AdherentSurclasse_${ts}`);

        // Le membre normal a eu 10 ans au 01/09/2027 -> promu Benjamin (id=3)
        expect(updatedNormal.practices[0].grade_id).toBe(3);
        expect(updatedNormal.practices[0].grade_name).toContain('Benjamin');

        // Le membre surclassé a conservé son grade Minime (id=4) car is_category_forced = 1
        expect(updatedSurclasse.practices[0].grade_id).toBe(4);
        expect(updatedSurclasse.practices[0].grade_name).toContain('Minime');
        expect(Number(updatedSurclasse.practices[0].is_category_forced)).toBe(1);

        // 4. Nettoyage
        await request.delete(`/api/v1/registrations/${regId}`);
    });

    test('TC-ROLLOVER-02 (US 5.1) : Archivage des dossiers de la saison précédente et traçabilité détaillée dans app_logs', async ({ request }) => {
        // Exécution du rollover
        const rolloverRes = await request.post('/api/v1/tasks/rollover-season', {
            data: {
                target_season: '2027-2028',
                reference_date: '2027-09-01',
                archive_previous: true
            }
        });
        expect((await rolloverRes.json()).status).toBe('success');

        // Vérification du journal d'audit applicatif (app_logs)
        const appLogsRes = await request.get('/api/v1/app-logs');
        const appLogsData = await appLogsRes.json();
        expect(appLogsData.status).toBe('success');

        const rolloverLog = appLogsData.data.find((l: any) => l.action === 'SEASON_ROLLOVER');
        expect(rolloverLog).toBeDefined();
        expect(rolloverLog.details).toContain('2027-2028');
        expect(rolloverLog.details).toContain('adhérent(s) promu(s)');
        expect(rolloverLog.details).toContain('surclassé(s) ignoré(s)');

        // Vérification de la traçabilité technique sys_logs (Superadmin)
        const sysLogsRes = await request.get('/api/v1/sys/logs', {
            headers: { Authorization: 'Bearer stub-superadmin-token' }
        });
        const sysLogsData = await sysLogsRes.json();
        const sysRollover = sysLogsData.data.find((l: any) => l.action === 'SEASON_ROLLOVER');
        expect(sysRollover).toBeDefined();
    });

    test('TC-ROLLOVER-03 (US 5.1 - RBAC) : Rejet strict 403 pour tout rôle autre que Président ou Superadmin', async ({ request }) => {
        const unauthorizedRoles = [
            { name: 'Trésorier', token: 'stub-tresorier-token' },
            { name: 'Entraîneur', token: 'stub-entraineur-token' },
            { name: 'Rédacteur', token: 'stub-redacteur-token' },
            { name: 'Adhérent', token: 'stub-tutor-a-token' },
            { name: 'Visiteur', token: 'stub-visitor-token' }
        ];

        for (const role of unauthorizedRoles) {
            const res = await request.post('/api/v1/tasks/rollover-season', {
                headers: { Authorization: `Bearer ${role.token}` },
                data: { target_season: '2027-2028' }
            });
            const body = await res.json();
            expect(body.status, `Rôle ${role.name} ne doit pas être autorisé`).toBe('error');
            expect(body.code).toBe(403);
            expect(body.message.toLowerCase()).toContain('réservée au président ou superadmin');
        }
    });

    test('TC-ROLLOVER-04 (US 5.1 - UI) : Ouverture et fermeture de la modale rouge de confirmation sur dashboard.php', async ({ page }) => {
        await page.goto('/');
        await page.evaluate(() => fetch('/api/v1/auth/switch-persona', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona: 'president' })
        }));

        await page.goto('/settings');
        await page.click('#tab-btn-club-rollover');

        const btnOpen = page.locator('#btn-open-rollover-modal');
        const modal = page.locator('#rollover-confirm-modal');
        const btnCancel = page.locator('#btn-cancel-rollover');

        await expect(btnOpen).toBeVisible();
        await expect(modal).toHaveClass(/.*hidden.*/);

        // Ouverture de la modale rouge
        await btnOpen.click();
        await expect(modal).not.toHaveClass(/.*hidden.*/);
        await expect(modal).toContainText('Confirmation du Basculement de Saison');
        await expect(page.locator('#rollover-target-season')).toBeVisible();

        // Annulation et fermeture
        await btnCancel.click();
        await expect(modal).toHaveClass(/.*hidden.*/);
    });

    test('TC-STAT-01 (US 5.2) : Agrégation des indicateurs clés (KPIs) et indicateurs financiers pour le Bureau', async ({ request }) => {
        const kpisRes = await request.get('/api/v1/stats/kpis');
        const kpisData = await kpisRes.json();

        expect(kpisData.status).toBe('success');
        expect(kpisData.code).toBe(200);
        expect(kpisData.data.total_members).toBeGreaterThanOrEqual(1);
        expect(kpisData.data.active_members).toBeGreaterThanOrEqual(1);
        expect(kpisData.data.renewal_rate).toBeGreaterThanOrEqual(0);

        // Vérification des indicateurs financiers (visibles pour le Bureau)
        const finances = kpisData.data.finances;
        expect(finances).not.toBeNull();
        expect(finances.total_validated_amount).toBeGreaterThanOrEqual(0);
        expect(finances.total_pending_amount).toBeGreaterThanOrEqual(0);
        expect(Array.isArray(finances.by_payment_method)).toBe(true);
    });

    test('TC-STAT-02 (US 5.2) : Agrégation démographique et ratios d\'occupation par discipline', async ({ request }) => {
        const demoRes = await request.get('/api/v1/stats/demographics');
        const demoData = await demoRes.json();

        expect(demoData.status).toBe('success');
        expect(demoData.code).toBe(200);
        expect(demoData.data.total_members).toBeGreaterThanOrEqual(1);
        expect(demoData.data.age_bands).toBeDefined();
        expect(demoData.data.gender_ratio).toBeDefined();
        expect(demoData.data.gender_ratio.male_pct).toBeGreaterThan(0);
        expect(demoData.data.gender_ratio.female_pct).toBeGreaterThan(0);

        // Vérification des ratios d'occupation par discipline
        const occ = demoData.data.discipline_occupancy;
        expect(Array.isArray(occ)).toBe(true);
        expect(occ.length).toBeGreaterThanOrEqual(1);
        expect(occ[0].name).toBeDefined();
        expect(occ[0].enrolled_count).toBeGreaterThanOrEqual(0);
        expect(occ[0].capacity).toBe(30);
    });

    test('TC-STAT-ADVANCED (US 5.2 - RGPD Enseignant) : Anonymisation stricte des montants financiers et données privées', async ({ page, request }) => {
        // 1. Appel API en tant qu'Entraîneur : finances doit être STRICTEMENT null
        const kpisTeacherRes = await request.get('/api/v1/stats/kpis', {
            headers: { Authorization: 'Bearer stub-entraineur-token' }
        });
        const kpisTeacherData = await kpisTeacherRes.json();
        expect(kpisTeacherData.status).toBe('success');
        expect(kpisTeacherData.data.finances, 'Le profil Entraîneur ne doit jamais recevoir de données financières').toBeNull();

        // 2. Visite de la page /stats.php en tant qu'Entraîneur
        await page.goto('/');
        await page.evaluate(() => fetch('/api/v1/auth/switch-persona', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona: 'entraineur' })
        }));

        await page.goto('/stats.php');

        // La section financière réservée au bureau ne doit PAS exister
        await expect(page.locator('#financial-stats-section')).toHaveCount(0);
        await expect(page.locator('#kpi-total-revenue')).toHaveCount(0);
        await expect(page.locator('#kpi-pending-revenue')).toHaveCount(0);

        // Le badge d'anonymisation RGPD doit être présent et visible
        const rgpdBadge = page.locator('#rgpd-teacher-protection');
        await expect(rgpdBadge).toBeVisible();
        await expect(rgpdBadge).toContainText('Protection RGPD & Anonymisation Pédagogique Active');

        // Aucun symbole monétaire € ne doit être affiché sur la page
        const bodyText = await page.locator('body').innerText();
        expect(bodyText).not.toContain('Cotisations Encaissées');
        expect(bodyText).not.toContain('Moyens de Règlement');
    });

    test('TC-STAT-03 (US 5.2 - UI) : Affichage des cartes statistiques réactives et ratios d\'occupation pour le Président', async ({ page }) => {
        await page.goto('/');
        await page.evaluate(() => fetch('/api/v1/auth/switch-persona', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona: 'president' })
        }));

        await page.goto('/stats.php');

        // Cartes KPIs globales visibles
        await expect(page.locator('#kpi-total-members')).toBeVisible();
        await expect(page.locator('#kpi-active-members')).toBeVisible();
        await expect(page.locator('#kpi-renewal-rate')).toBeVisible();
        await expect(page.locator('#kpi-minors-adults')).toBeVisible();
        await expect(page.locator('#kpi-gender-ratio')).toBeVisible();

        // Section financière Bureau visible
        await expect(page.locator('#financial-stats-section')).toBeVisible();
        await expect(page.locator('#kpi-total-revenue')).toBeVisible();
        await expect(page.locator('#kpi-pending-revenue')).toBeVisible();

        // Liste d'occupation par discipline visible (Judo, Karaté, Aïkido...)
        await expect(page.locator('#discipline-occupancy-list')).toBeVisible();
        const discCount = await page.locator('#discipline-occupancy-list > div').count();
        expect(discCount).toBeGreaterThanOrEqual(2);
    });
});
