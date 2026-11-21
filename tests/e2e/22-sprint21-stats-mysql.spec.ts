import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import path from 'node:path';

const PROJECT_ROOT = process.cwd();

test.describe.serial('Sprint 21 (Lot 4) — Données Réelles Stats (RGPD) & Compatibilité MySQL/MariaDB', () => {
    test('S21-01 : Persona 1 — Statistiques démographiques réelles sur /stats & GET /api/v1/stats/demographics (fin du 54%/46% statique)', async ({ page, request }) => {
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });

        // 1. Vérification API démographie réelle
        const demoRes1 = await request.get('/api/v1/stats/demographics');
        expect(demoRes1.status()).toBe(200);
        const demo1 = (await demoRes1.json()).data;
        const gr1 = demo1.gender_ratio;

        expect(gr1.male + gr1.female + gr1.other + gr1.unspecified).toBe(demo1.total_members);
        expect(gr1.male_pct).toBe(Math.round((gr1.male / demo1.total_members) * 100));
        expect(gr1.female_pct).toBe(Math.round((gr1.female / demo1.total_members) * 100));
        expect(gr1.other).toBeGreaterThan(0);
        expect(gr1.unspecified).toBeGreaterThan(0);
        expect(`${gr1.male_pct}% / ${gr1.female_pct}%`).not.toBe('54% / 46%');

        // 2. Vérification UI /stats : données réelles affichées, mention "Répartition estimée" supprimée
        await page.goto('/stats');
        await expect(page.getByTestId('kpi-gender-ratio')).toHaveText(`${gr1.male_pct}% / ${gr1.female_pct}%`);
        await expect(page.getByTestId('kpi-gender-details')).toContainText('Non renseigné');
        await expect(page.getByTestId('kpi-gender-details')).not.toContainText('Répartition estimée');

        // 3. Création d'un adhérent avec gender='F' -> impact immédiat sur les compteurs réels
        const createRes = await request.post('/api/v1/members', {
            data: {
                first_name: 'Claire',
                last_name: 'StatsTest',
                birth_date: '1998-05-12',
                gender: 'F',
                status: 'active',
            },
        });
        const createJson = await createRes.json();
        expect(createJson.code).toBe(201);
        const createdId: number = createJson.data.id;

        try {
            const demo2 = (await (await request.get('/api/v1/stats/demographics')).json()).data;
            expect(demo2.gender_ratio.female).toBe(gr1.female + 1);
            expect(demo2.total_members).toBe(demo1.total_members + 1);

            // 4. Modification du genre en 'O' via PUT /api/v1/members/{id}
            const updRes = await request.put(`/api/v1/members/${createdId}`, {
                data: {
                    first_name: 'Claire',
                    last_name: 'StatsTest',
                    birth_date: '1998-05-12',
                    gender: 'O',
                    status: 'active',
                },
            });
            expect((await updRes.json()).code).toBe(200);

            const demo3 = (await (await request.get('/api/v1/stats/demographics')).json()).data;
            expect(demo3.gender_ratio.female).toBe(gr1.female);
            expect(demo3.gender_ratio.other).toBe(gr1.other + 1);
        } finally {
            await request.delete(`/api/v1/members/${createdId}`);
        }
    });

    test('S21-02 : Persona 2 — Champ gender strictement optionnel RGPD (/register, /members) et rejet API 422 si invalide', async ({ page, request }) => {
        // 1. /register : sélecteur optionnel présent, défaut vide ("Non renseigné"), non required
        await page.goto('/register');
        const regGender = page.getByTestId('reg-member-gender');
        await expect(regGender).toBeVisible();
        await expect(regGender).toHaveValue('');
        await expect(regGender).not.toHaveAttribute('required', /.*/);

        // 2. /members : sélecteurs optionnels présents en création et édition
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/members');
        await expect(page.getByTestId('create-member-gender')).toBeAttached();
        await expect(page.getByTestId('create-member-gender')).not.toHaveAttribute('required', /.*/);
        await expect(page.getByTestId('edit-member-gender')).toBeAttached();
        await expect(page.getByTestId('edit-member-gender')).not.toHaveAttribute('required', /.*/);

        // 3. API : création sans gender autorisée (NULL) et rejet 422 si valeur invalide
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const okNoGender = await (await request.post('/api/v1/members', {
            data: {
                first_name: 'Alex',
                last_name: 'RgpdNoGender',
                birth_date: '2001-03-10',
                status: 'active',
            },
        })).json();
        expect(okNoGender.code).toBe(201);
        await request.delete(`/api/v1/members/${okNoGender.data.id}`);

        const badMemberGender = await (await request.post('/api/v1/members', {
            data: {
                first_name: 'Alex',
                last_name: 'InvalidGender',
                birth_date: '2001-03-10',
                gender: 'INVALID_VALUE',
            },
        })).json();
        expect(badMemberGender.code).toBe(422);

        const badRegGender = await (await request.post('/api/v1/registrations', {
            data: {
                members: [
                    {
                        first_name: 'Test',
                        last_name: 'InvalidReg',
                        birth_date: '1995-02-02',
                        gender: 'X_UNKNOWN',
                        email: 'invalid.gender@kanso-ya.fr',
                        discipline_id: 1,
                        emergency_contact_name: 'Contact',
                        emergency_contact_phone: '0600000000',
                    },
                ],
                payment_method: 'cheque',
                installments: 1,
            },
        })).json();
        expect(badRegGender.code).toBe(422);
    });

    test('S21-03 : Audit compatibilité MySQL/MariaDB (0 syntaxe SQLite-only non abstraite & traduction DDL InnoDB/utf8mb4)', async () => {
        const output = execFileSync(
            'php',
            [path.join(PROJECT_ROOT, 'Core', 'Api', 'Database', 'check_mysql_compat.php')],
            { cwd: PROJECT_ROOT, encoding: 'utf8' }
        );
        expect(output).toContain('[OK] Audit SQLite-only : 0 syntaxe non abstraite dans Core/Api/.');
        expect(output).toContain('[OK] Traduction DDL MySQL 8 / MariaDB 10.6+ (InnoDB, utf8mb4, AUTO_INCREMENT) : valide.');
    });
});
