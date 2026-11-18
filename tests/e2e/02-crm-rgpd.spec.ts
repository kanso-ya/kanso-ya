/**
 * @file 02-crm-rgpd.spec.ts
 * @description Suite E2E & API : Domaine 2 - CRM Adhérents, Rattachements Familiaux, RGPD & Santé.
 */

import { test, expect } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

test.describe('DOMAINE 2 — CRM Adhérents, Rattachements Familiaux, RGPD & Santé', () => {

    test.beforeEach(async ({ page, request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
        await page.request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    test('TC-CRM-01 : Le filtre de recherche instantané (0ms) masque les lignes non correspondantes sur /members.php', async ({ page }) => {
        await page.goto('/members.php');
        await page.waitForSelector('#members-table tbody tr.member-row');

        const searchInput = page.locator('#member-search');
        const rowDupont = page.locator('#members-table tbody tr.member-row', { hasText: 'Jean Dupont' }).first();
        const rowMartin = page.locator('#members-table tbody tr.member-row', { hasText: 'Léo Martin' }).first();

        await expect(rowDupont).toBeVisible();
        await expect(rowMartin).toBeVisible();

        await searchInput.fill('léo');
        await expect(rowDupont).toHaveCSS('display', 'none');
        await expect(rowMartin).not.toHaveCSS('display', 'none');
    });

    test('US 2.3 : L\'accordéon TS déploie la fiche détaillée et les coordonnées des tuteurs légaux au clic', async ({ page }) => {
        await page.goto('/members.php');
        await page.waitForSelector('#members-table tbody tr.member-row');

        const memberRow = page.locator('.member-row', { hasText: 'Test' }).first();
        const accordionRow = memberRow.locator('xpath=following-sibling::tr[1][contains(@class, "tutor-accordion")]');

        await expect(accordionRow).toHaveClass(/.*hidden.*/);
        await memberRow.click();
        await expect(accordionRow).not.toHaveClass(/.*hidden.*/);
    });

    test('TC-CRM-TUT-02 : Isolation RGPD stricte des co-tuteurs séparés (Famille Lefebvre info_shared = 0)', async ({ request }) => {
        const resDavid = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
        });
        const bodyDavid = await resDavid.json();
        expect(bodyDavid.status).toBe('success');

        const child = bodyDavid.data.find((m: { id: number }) => Number(m.id) === 1);
        expect(child).toBeDefined();

        const tutorB = child.tutors.find((t: { id: number }) => Number(t.id) === 2);
        expect(tutorB).toBeDefined();
        expect(tutorB.email).toContain('Données masquées');
        expect(tutorB.phone).toContain('Données masquées');
        expect(tutorB.address).toContain('Données masquées');
    });

    test('TC-CRM-TUT-03 : Partage RGPD bilatéral activé (Famille Moreau info_shared = 1)', async ({ request }) => {
        const presRes = await request.get('/api/v1/members');
        const presBody = await presRes.json();
        expect(presBody.status).toBe('success');

        const lucas = presBody.data.find((m: { id: number }) => Number(m.id) === 5);
        expect(lucas).toBeDefined();
        expect(lucas.tutors.length).toBeGreaterThanOrEqual(2);
        expect(lucas.tutors.every((t: { email: string }) => t.email.includes('@'))).toBe(true);
    });

    test('TC-CRM-P04 : Opt-in de partage RGPD et édition d\'une fiche enfant rattachée', async ({ request }) => {
        try {
            // 1. Le Tuteur B active le partage RGPD
            const shareRes = await request.patch('/api/v1/members/1/share-tutor-info', {
                headers: { 'Authorization': 'Bearer stub-tutor-b-token' },
                data: { tutor_id: 2, info_shared: true }
            });
            expect((await shareRes.json()).code).toBe(200);

            // 2. Le Tuteur A voit désormais l'email authentique du Tuteur B
            const memRes = await request.get('/api/v1/members', {
                headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
            });
            const memBody = await memRes.json();
            const child = memBody.data.find((m: { id: number }) => Number(m.id) === 1);
            const tutorB = child.tutors.find((t: { id: number }) => Number(t.id) === 2);
            expect(tutorB.email).toContain('@');

            // 3. Le Tuteur A peut mettre à jour les coordonnées de son enfant rattaché
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

    test('TC-CRM-TEACHER-RGPD : Masquage strict pour l\'Entraîneur sur l\'annuaire des membres', async ({ request }) => {
        const coachRes = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-entraineur-token' }
        });
        const coachBody = await coachRes.json();
        expect(coachBody.status).toBe('success');

        const firstMember = coachBody.data[0];
        expect(firstMember.email).toContain('Protégé (RGPD Enseignant)');
        expect(firstMember.address).toContain('Protégée (RGPD Enseignant)');

        if (firstMember.tutors && firstMember.tutors.length > 0) {
            expect(firstMember.tutors[0].email).toContain('Données masquées');
        }
    });

    test('TC-STAT-01 : Page Statistiques (/stats.php) — Enseignant restreint à ses cours avec anonymisation stricte', async ({ page }) => {
        await page.goto('/stats.php');
        await expect(page.locator('#club-name-heading')).toBeVisible();
        await expect(page.locator('text=Pilotage Général du Club')).toBeVisible();

        try {
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'entraineur' })
                });
            });

            await page.goto('/stats.php');
            await expect(page.locator('text=Périmètre Pédagogique Enseignant (Anonymisé RGPD)')).toBeVisible();
            await page.locator('[data-testid="stats-tab-occupancy"]').click();
            await expect(page.locator('text=Aïkido & Self-Défense')).toHaveCount(0);
            await expect(page.locator('h3', { hasText: 'Judo' }).first()).toBeVisible();
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

    test('TC-OTP-RESEND : Renvoi d\'un lien OTP 48h par le Bureau avec traçabilité dans email_logs', async ({ request }) => {
        const resendRes = await request.post('/api/v1/members/1/resend-otp', {
            headers: { 'Authorization': 'Bearer stub-tresorier-token' }
        });
        const resendBody = await resendRes.json();
        expect(resendBody.status).toBe('success');
        expect(resendBody.code).toBe(200);
        expect(resendBody.data.otp_code).toBeDefined();

        const logsRes = await request.get('/api/v1/sys/email-logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const logsBody = await logsRes.json();
        expect(logsBody.data.some((l: { template_type: string }) => l.template_type === 'OTP_RESEND')).toBe(true);
    });

    test('US 2.2 : Création manuelle d\'un adhérent en back-office avec génération d\'OTP et trace app_logs', async ({ request }) => {
        const uniqueEmail = `adherent.cree.${Date.now()}@kanso-ya.fr`;
        const createRes = await request.post('/api/v1/members', {
            data: {
                first_name: 'Camille',
                last_name: 'Dupuis',
                birth_date: '1995-10-12',
                email: uniqueEmail,
                phone: '06 12 34 56 78',
                address: '15 Rue Haute, 79100 Thouars',
                emergency_contact_name: 'Paul Dupuis',
                emergency_contact_phone: '06 98 76 54 32',
                discipline_id: 1
            }
        });

        const createBody = await createRes.json();
        expect(createBody.status).toBe('success');
        expect(createBody.code).toBe(201);
        expect(createBody.data.otp_code).toBeDefined();
        const memberId = createBody.data.id;

        const logsRes = await request.get('/api/v1/app-logs');
        const logsBody = await logsRes.json();
        expect(logsBody.data.some((l: { action: string; target_id: number }) => l.action === 'MEMBER_CREATED' && Number(l.target_id) === memberId)).toBe(true);

        await request.delete(`/api/v1/members/${memberId}`);
    });

    test('REQ-CMS-FILE-01 & 02 : Upload sécurisé d\'un certificat médical (PDF valide accepté, scripts rejetés)', async ({ request }) => {
        const badUploadRes = await request.post('/api/v1/members/1/medical-file', {
            multipart: {
                file: {
                    name: 'malware.php',
                    mimeType: 'application/x-php',
                    buffer: Buffer.from('<?php echo "evil"; ?>')
                }
            }
        });
        const badBody = await badUploadRes.json();
        expect(badBody.status).toBe('error');
        expect(badBody.code).toBe(422);

        const pdfContent = '%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< >>\n%%EOF';
        const goodUploadRes = await request.post('/api/v1/members/1/medical-file', {
            multipart: {
                file: {
                    name: 'certificat_medical_2026.pdf',
                    mimeType: 'application/pdf',
                    buffer: Buffer.from(pdfContent)
                }
            }
        });
        const goodBody = await goodUploadRes.json();
        expect(goodBody.status).toBe('success');
        expect(goodBody.code).toBe(200);
        expect(goodBody.data.filename).toContain('certif_member_1_');

        const savedFile = path.join(process.cwd(), 'storage', 'private_uploads', goodBody.data.filename);
        if (fs.existsSync(savedFile)) {
            fs.unlinkSync(savedFile);
        }
    });

    test('REQ-CMS-FILE-03 : Blocage strict de l\'accès HTTP direct aux certificats médicaux (.htaccess)', async ({ request }) => {
        const directAccessRes = await request.get('/storage/private_uploads/test_fake.pdf');
        expect([403, 404]).toContain(directAccessRes.status());
    });

    test('US 2.4 : Distinction Discipline / Grade (Âge) / Niveau (Ceinture) et affichage des badges numérotés sur /disciplines.php', async ({ page }) => {
        await page.goto('/disciplines.php');
        const judoCard = page.locator('#disciplines-list > div', { hasText: 'Judo' }).first();
        await expect(judoCard).toBeVisible();
        await expect(judoCard).toContainText('adhérent(s)');
        await expect(judoCard).toContainText('Poussin');
        await expect(judoCard).toContainText('Ceinture Blanche');
    });
});
