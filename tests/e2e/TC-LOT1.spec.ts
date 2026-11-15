/**
 * @file TC-LOT1.spec.ts
 * @description Suite complète, ajustée et enrichie des tests E2E & API du Lot 1 :
 * - Architecture UI 2026 (Logo à cheval Header/Sidebar, Titre centré, Tiroir mobile, Vitrine publique isolée)
 * - Console Superadmin (Branding, Palette de couleurs, SMTP & Traçabilité email_logs / sys_logs)
 * - Authentification, Sécurité & Page "Mon profil" à 2 onglets (Champs dynamiques & Double Authentification 2FA)
 * - Matrice RBAC en Tableau Unique Multi-Colonnes (Création de colonne, Renommage, Modification, Suppression)
 * - CRM Adhérents, Statistiques (/stats.php), Filtrage RGPD Enseignant & Rattachements Familiaux
 * - Disciplines, Grades (Catégories d'âge) & Niveaux (Ceintures/Gants) avec Badges numérotés d'effectifs.
 */

import { test, expect } from '@playwright/test';

test.describe('LOT 1 — Socle Technique, Branding/SMTP, Profil 2FA, RBAC Multi-Colonnes, CRM, Stats & Disciplines', () => {

    test.beforeEach(async ({ request }) => {
        await request.post('/api/v1/auth/switch-persona', {
            data: { persona: 'president' }
        });
    });

    // =========================================================================
    // MODULE 1 : ARCHITECTURE UI, LOGO À CHEVAL & NOM DU SITE CENTRÉ
    // =========================================================================

    test('UI-01 : Respect du Layout 2026 (Logo à cheval Header/Sidebar, Nom du site centré, Accueil public isolé)', async ({ page }) => {
        await page.setViewportSize({ width: 1280, height: 800 });
        await page.goto('/');

        const header = page.locator('#app-header');
        const sidebar = page.locator('#sidebar');
        const main = page.locator('#main-workspace');
        const overlappingLogo = page.locator('#overlapping-logo-badge');
        const centeredHeading = page.locator('#club-name-heading');

        await expect(header).toBeVisible();
        await expect(sidebar).toBeAttached();
        await expect(main).toBeVisible();
        await expect(overlappingLogo).toBeVisible();
        await expect(centeredHeading).toBeVisible();

        // L'accueil public (/) ne doit jamais exposer le tableau back-office des adhérents ni de lien Superadmin
        await expect(page.locator('#members-table')).toHaveCount(0);
        await expect(page.locator('#sidebar a[href*="sys-admin"]')).toHaveCount(0);
    });

    test('UI-02 : Le bouton hamburger mobile ouvre et ferme le tiroir latéral', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 812 });
        await page.goto('/');

        const sidebar = page.locator('#sidebar');
        const btn = page.locator('#mobile-menu-btn');

        await expect(sidebar).toHaveClass(/.*-translate-x-full.*/);
        await btn.click();
        await expect(sidebar).not.toHaveClass(/.*-translate-x-full.*/);
    });

    // =========================================================================
    // MODULE 2 : CONSOLE SUPERADMIN (BRANDING, COULEURS, SMTP & EMAIL_LOGS)
    // =========================================================================

    test('TC-SYS-01 (Point 1) : Le Superadmin configure le Branding/Couleurs et teste l\'envoi SMTP avec traçabilité dans email_logs et sys_logs', async ({ request }) => {
        // 1. Sauvegarde de la configuration visuelle et du nom du site par le Superadmin
        const settingsRes = await request.patch('/api/v1/settings', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                site_name: 'Kanso-ya - Clubs et Associations',
                theme_primary_color: '#059669',
                theme_sidebar_color: '#0f172a',
                theme_header_color: '#ffffff',
                smtp_host: 'smtp.kanso-ya.fr',
                smtp_port: '587'
            }
        });
        const settingsBody = await settingsRes.json();
        expect(settingsBody.status).toBe('success');
        expect(settingsBody.code).toBe(200);

        // 2. Déclenchement d'un email de test SMTP et vérification de la trace dans email_logs
        const testEmail = `audit.smtp.${Date.now()}@kanso-ya.fr`;
        const sendRes = await request.post('/api/v1/sys/test-email', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' },
            data: {
                recipient_email: testEmail,
                subject: 'Vérification Automate QA SMTP',
                template_type: 'SMTP_DIAGNOSTIC'
            }
        });
        expect((await sendRes.json()).status).toBe('success');

        const emailLogsRes = await request.get('/api/v1/sys/email-logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const emailLogsBody = await emailLogsRes.json();
        expect(emailLogsBody.status).toBe('success');
        expect(emailLogsBody.data.some((m: { recipient_email: string }) => m.recipient_email === testEmail)).toBe(true);

        // 3. Vérification de la trace réelle dans sys_logs
        const sysLogsRes = await request.get('/api/v1/sys/logs', {
            headers: { 'Authorization': 'Bearer stub-superadmin-token' }
        });
        const sysLogsBody = await sysLogsRes.json();
        expect(sysLogsBody.data.some((l: { action: string }) => l.action === 'SYS_SETTINGS_UPDATED')).toBe(true);
    });

    // =========================================================================
    // MODULE 3 : AUTHENTIFICATION, SÉCURITÉ & "MON PROFIL" (2 ONGLETS + 2FA)
    // =========================================================================

    test('TC-SEC-01 : Étanchéité du Shadow Login (Superadmin rejeté sur la route standard /login)', async ({ request }) => {
        const response = await request.post('/api/v1/auth/login', {
            data: {
                email: 'superadmin@kanso-ya.local',
                password: 'password123'
            }
        });

        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(401);
    });

    test('TC-AUTH-P02 : Connexion Superadmin réussie via URL furtive (/sys-admin-xyz)', async ({ page, request }) => {
        try {
            await page.goto('/sys-admin-xyz');
            await expect(page.locator('h1')).toContainText('Authentification Système');

            await page.fill('input[name="username"]', 'superadmin');
            await page.fill('input[name="password"]', 'password123');
            await page.click('#btn-sys-submit');

            const toast = page.locator('#toast-container');
            await expect(toast).toContainText('Accès technique autorisé');
        } finally {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        }
    });

    test('TC-AUTH-N02 : Protection contre l\'injection SQL dans le formulaire de connexion UI', async ({ page }) => {
        await page.goto('/login');
        await page.fill('input[name="email"]', "' OR '1'='1");
        await page.fill('input[name="password"]', 'password123');
        await page.click('button[type="submit"]');

        const toast = page.locator('#toast-container');
        await expect(toast).toContainText('Identifiants invalides');
    });

    test('TC-AUTH-N03 : Un utilisateur radié (is_active = 0) est bloqué à la connexion (403)', async ({ request }) => {
        const response = await request.post('/api/v1/auth/login', {
            data: {
                email: 'radie_user@mail.com',
                password: 'password123'
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(403);
    });

    test('TC-PROF-01 (Point 4) : Page "Mon profil" à 2 onglets, champs personnalisés dynamiques et activation/désactivation de la 2FA', async ({ page, request }) => {
        await page.goto('/dashboard.php');
        await expect(page.locator('h1')).toContainText('Mon profil');

        // Onglet 1 : Ajout d'un champ personnalisé dynamique et sauvegarde
        await page.click('#btn-add-custom-field');
        const lastCfLabel = page.locator('.cf-label').last();
        const lastCfValue = page.locator('.cf-value').last();
        await lastCfLabel.fill('Grade Fédéral');
        await lastCfValue.fill('Ceinture Noire 2e Dan');
        await page.click('#btn-save-profile');
        await expect(page.locator('#toast-container')).toContainText('Profil mis à jour');

        // Bascule sur l'Onglet 2 : Sécurité & 2FA
        await page.click('#tab-btn-profile-security');
        await expect(page.locator('#tab-panel-profile-security')).toBeVisible();

        // Tentative d'activation 2FA avec un code invalide -> rejet 422
        const bad2fa = await request.post('/api/v1/users/me/2fa', {
            data: { enabled: true, totp_code: 'abc' }
        });
        expect((await bad2fa.json()).code).toBe(422);

        // Activation 2FA avec un code TOTP à 6 chiffres valide -> 200 OK
        await page.fill('#totp-code-input', '123456');
        await page.click('#btn-enable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText('2FA Active');

        // Désactivation 2FA pour remise à l'état initial
        await page.click('#btn-disable-2fa');
        await expect(page.locator('#badge-2fa-status')).toContainText('Désactivée');
    });

    test('TC-AUTH-P03 & P04 : Vérification de l\'ancien mot de passe et Déconnexion propre', async ({ request }) => {
        try {
            const badRes = await request.patch('/api/v1/users/me/password', {
                data: { current_password: 'wrong_password', new_password: 'NewSecurePassword123!' }
            });
            const badBody = await badRes.json();
            expect(badBody.status).toBe('error');
            expect(badBody.code).toBe(422);

            const logoutRes = await request.post('/api/v1/auth/logout');
            const logoutBody = await logoutRes.json();
            expect(logoutBody.status).toBe('success');
            expect(logoutBody.code).toBe(200);
        } finally {
            await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        }
    });

    // =========================================================================
    // MODULE 4 : MATRICE RBAC MULTI-COLONNES (AJOUT, RENOMMAGE, SUPPRESSION)
    // =========================================================================

    test('REQ-SEC-RBAC-03 : Un Rédacteur sans droit members.view est bloqué (403) sur GET /api/v1/members', async ({ request }) => {
        const response = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-redacteur-token' }
        });

        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(403);
    });

    test('US 2.1 (CA3) : La Sidebar masque dynamiquement les liens non autorisés selon le rôle', async ({ page }) => {
        try {
            await page.goto('/');
            await page.evaluate(async () => {
                await fetch('/api/v1/auth/switch-persona', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ persona: 'redacteur' })
                });
            });

            await page.goto('/dashboard.php');
            await expect(page.locator('#sidebar a[href="/roles.php"]')).toHaveCount(0);
            await expect(page.locator('#sidebar a[href="/members.php"]')).toHaveCount(0);
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

    test('TC-RBAC-04 (Point 10) : Tableau Unique Multi-Colonnes — Ajout d\'un nouveau rôle (colonne), renommage, modification des droits et suppression', async ({ page, request }) => {
        await page.goto('/roles.php');
        await expect(page.locator('#rbac-matrix-table')).toBeVisible();

        // 1. Création d'une nouvelle colonne de rôle via l'UI
        const customRoleName = `Bénévole-${Date.now()}`;
        await page.fill('#new-role-name', customRoleName);
        await page.click('#btn-add-role-col');
        await expect(page.locator('#toast-container')).toContainText('ajoutée à la matrice');

        // 2. Récupération de l'ID du rôle créé via l'API
        const rolesRes = await request.get('/api/v1/roles');
        const rolesData = (await rolesRes.json()).data;
        const createdRole = rolesData.find((r: { name: string }) => r.name === customRoleName);
        expect(createdRole).toBeDefined();

        // 3. Renommage du rôle et mise à jour de ses permissions
        const renamedRole = `${customRoleName}-Modifié`;
        const patchRes = await request.patch(`/api/v1/roles/${createdRole.id}`, {
            data: {
                name: renamedRole,
                permissions: ['members.view', 'articles.write']
            }
        });
        const patchBody = await patchRes.json();
        expect(patchBody.status).toBe('success');
        expect(patchBody.data.name).toBe(renamedRole);

        // 4. Suppression du rôle non-système (et vérification que le rôle Président id=1 est protégé)
        const delSysRes = await request.delete('/api/v1/roles/1');
        expect((await delSysRes.json()).code).toBe(403);

        const delCustomRes = await request.delete(`/api/v1/roles/${createdRole.id}`);
        expect((await delCustomRes.json()).code).toBe(200);
    });

    // =========================================================================
    // MODULE 5 : STATISTIQUES (/stats.php), CRM ADHÉRENTS & RGPD ENSEIGNANT
    // =========================================================================

    test('TC-STAT-01 (Points 7 & 8) : Page Statistiques accessible au Président/Enseignant et anonymisation RGPD stricte pour l\'Enseignant', async ({ page, request }) => {
        // 1. Vérification de la page Statistiques (/stats.php)
        await page.goto('/stats.php');
        await expect(page.locator('h1')).toContainText('Statistiques');
        await expect(page.locator('body')).toContainText('Poussin');
        await expect(page.locator('body')).toContainText('Ceinture Blanche');

        // 2. Vérification API de la protection RGPD côté Enseignant (Entraîneur)
        const teacherMembersRes = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-entraineur-token' }
        });
        const teacherBody = await teacherMembersRes.json();
        expect(teacherBody.status).toBe('success');

        const sampleMember = teacherBody.data[0];
        expect(sampleMember.email).toContain('Protégé (RGPD Enseignant)');
        expect(sampleMember.address).toContain('Protégée (RGPD Enseignant)');
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

    test('TC-CRM-TUT-02 : Isolation RGPD des tuteurs séparés via API (info_shared = 0)', async ({ request }) => {
        const response = await request.get('/api/v1/members', {
            headers: { 'Authorization': 'Bearer stub-tutor-a-token' }
        });

        expect(response.status()).toBe(200);
        const body = await response.json();
        expect(body.status).toBe('success');

        const child = body.data.find((m: { id: number }) => Number(m.id) === 1);
        expect(child).toBeDefined();

        const tutorB = child.tutors.find((t: { id: number }) => Number(t.id) === 2);
        expect(tutorB.email).toContain('Données masquées');
    });

    test('US 2.3 : L\'accordéon TS déploie la fiche détaillée et les paramètres RGPD au clic sur une ligne adhérent', async ({ page }) => {
        await page.goto('/members.php');
        await page.waitForSelector('#members-table tbody tr.member-row');

        const memberRow = page.locator('.member-row', { hasText: 'Test' }).first();
        const accordionRow = memberRow.locator('xpath=following-sibling::tr[1][contains(@class, "tutor-accordion")]');

        await expect(accordionRow).toHaveClass(/.*hidden.*/);
        await memberRow.click();
        await expect(accordionRow).not.toHaveClass(/.*hidden.*/);
    });

    test('US 2.2 (CA4) : Création manuelle d\'un adhérent avec génération d\'OTP, trace app_logs et nettoyage', async ({ request }) => {
        const uniqueEmail = `membre.${Date.now()}@kanso-ya.fr`;
        const response = await request.post('/api/v1/members', {
            data: {
                first_name: 'Sophie',
                last_name: 'Marceau',
                birth_date: '1995-11-17',
                email: uniqueEmail
            }
        });

        const body = await response.json();
        expect(body.status).toBe('success');
        expect(body.code).toBe(201);
        expect(body.data.otp_dispatched).toBe(true);

        const createdId = body.data.id;

        const logsRes = await request.get('/api/v1/app-logs');
        const logsBody = await logsRes.json();
        expect(logsBody.status).toBe('success');
        expect(logsBody.data.some((l: { action: string }) => l.action === 'MEMBER_CREATED')).toBe(true);

        if (createdId) {
            await request.delete(`/api/v1/members/${createdId}`);
        }
    });

    test('REQ-CMS-FILE-02 : Rejet d\'un fichier exécutable ou corrompu à l\'upload médical', async ({ request }) => {
        const nodeBuffer = (globalThis as any).Buffer.from('<?php echo "hacked"; ?>');
        const response = await request.post('/api/v1/members/1/medical-file', {
            multipart: {
                file: {
                    name: 'exploit.php.pdf',
                    mimeType: 'application/pdf',
                    buffer: nodeBuffer
                }
            }
        });

        const body = await response.json();
        expect(body.status).toBe('error');
        expect(body.code).toBe(422);
    });

    // =========================================================================
    // MODULE 6 : DISCIPLINES, GRADES (ÂGE) & NIVEAUX (CEINTURES) + BADGES
    // =========================================================================

    test('US 2.4 (Point 11) : Distinction Discipline / Grade (Âge) / Niveau (Ceinture) et affichage des badges numérotés d\'effectifs', async ({ page }) => {
        await page.goto('/disciplines.php');

        // Vérifie que la carte Judo affiche ses badges d'effectifs, ses Grades (Poussin, Benjamin) et ses Niveaux (Ceinture)
        const judoCard = page.locator('#disciplines-list > div', { hasText: 'Judo' }).first();
        await expect(judoCard).toBeVisible();
        await expect(judoCard).toContainText('adhérent(s)');
        await expect(judoCard).toContainText('Poussin');
        await expect(judoCard).toContainText('Ceinture Blanche');

        // Création d'une nouvelle discipline avec un Grade d'âge ET un Niveau technique
        await page.click('#btn-open-modal');
        const modal = page.locator('#discipline-modal');
        await expect(modal).toBeVisible();

        const uniqueSport = `Boxe-${Date.now()}`;
        await page.fill('#discipline-name', uniqueSport);

        // Ajout d'un Grade (Catégorie d'âge)
        await page.click('#btn-add-level');
        await page.locator('.level-type').first().selectOption('grade');
        await page.locator('.level-name').first().fill('Minime Boxe');
        await page.locator('.level-age-min').first().fill('12');
        await page.locator('.level-age-max').first().fill('13');

        // Ajout d'un Niveau (Couleur de gant)
        await page.click('#btn-add-level');
        await page.locator('.level-type').nth(1).selectOption('niveau');
        await page.locator('.level-name').nth(1).fill('Gant Rouge');

        await page.click('#btn-submit');
        await expect(page.locator('#toast-container')).toContainText('Discipline créée');

        const card = page.locator('#disciplines-list > div', { hasText: uniqueSport });
        await expect(card).toBeVisible();
        await expect(card).toContainText('Minime Boxe');
        await expect(card).toContainText('Gant Rouge');
        await expect(card).toContainText('0 inscrit(s)');

        // Nettoyage via la modale de confirmation rouge
        await card.locator('.btn-del-disc').click();
        await page.locator('#confirm-modal-accept').click();
    });
});