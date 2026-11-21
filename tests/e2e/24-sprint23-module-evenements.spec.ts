import { test, expect } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const PROJECT_ROOT = process.cwd();
const PUBLIC_EVENTS_ASSETS = path.join(PROJECT_ROOT, 'public', 'assets', 'modules', 'evenements');

test.describe.serial('Sprint 23 (Lot 5) — Module Événements, Stages & Compétitions (EvenementsApi / EvenementsUi)', () => {
    const activateEventsModule = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/scan');
        const toggleRes = await request.patch('/api/v1/sys/modules/EvenementsApi/toggle', {
            data: { enabled: true },
        });
        expect(toggleRes.status()).toBe(200);
        const toggleJson = await toggleRes.json();
        expect(toggleJson.status).toBe('success');
        expect(Boolean(toggleJson.data.is_active)).toBe(true);
    };

    const uninstallEventsModule = async (request: import('@playwright/test').APIRequestContext): Promise<void> => {
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/EvenementsApi/uninstall');
    };

    test.afterAll(async ({ request }) => {
        await uninstallEventsModule(request);
    });

    test('S23-01 : Cycle de vie SDK v2 — Activation EvenementsApi, publication assets et widget accueil / (ui_slot_home_after_articles)', async ({ page, request }) => {
        await activateEventsModule(request);

        expect(fs.existsSync(path.join(PUBLIC_EVENTS_ASSETS, 'evenements.css'))).toBe(true);
        expect(fs.existsSync(path.join(PUBLIC_EVENTS_ASSETS, 'evenements.js'))).toBe(true);

        expect((await request.get('/assets/modules/evenements/evenements.css')).status()).toBe(200);
        expect((await request.get('/assets/modules/evenements/evenements.js')).status()).toBe(200);

        await page.goto('/');
        const widget = page.getByTestId('home-events-widget');
        await expect(widget).toBeVisible();
        await expect(widget).toContainText('Stage Technique Judo');
    });

    test('S23-02 : Persona 1 — Création stage jauge=1 (Président), inscription 1 clic (Parent), refus 409 Complet & modale participants CSV', async ({ page, request }) => {
        await activateEventsModule(request);

        // 1. Président crée un événement avec jauge = 1
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/evenements');
        await expect(page.getByTestId('events-create-form')).toBeVisible();

        const uniqueTitle = `Stage Jauge Unique ${Date.now()}`;
        await page.getByTestId('event-title-input').fill(uniqueTitle);
        await page.getByTestId('event-type-select').selectOption('stage');
        await page.getByTestId('event-date-input').fill('2026-12-28');
        await page.getByTestId('event-location-input').fill('Dojo Central');
        await page.getByTestId('event-capacity-input').fill('1');

        const [createRes] = await Promise.all([
            page.waitForResponse((r) => r.url().includes('/api/v1/modules/evenements/events') && r.request().method() === 'POST'),
            page.getByTestId('btn-create-event').click(),
        ]);
        const createJson = await createRes.json();
        expect(createJson.code).toBe(201);
        const eventId: number = createJson.data.id;

        await expect(page.getByTestId(`event-card-${eventId}`)).toBeVisible();
        await expect(page.getByTestId(`event-card-${eventId}`)).toContainText(uniqueTitle);

        // 2. Adhérent (parent de l'enfant #1) s'inscrit en 1 clic
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        await page.goto('/evenements');
        await expect(page.getByTestId(`btn-register-event-${eventId}`)).toBeVisible();

        await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/events/${eventId}/register`) && r.request().method() === 'POST'),
            page.getByTestId(`btn-register-event-${eventId}`).click(),
        ]);

        await expect(page.getByTestId(`event-card-${eventId}`)).toContainText('1 / 1 inscrits');
        await expect(page.getByTestId(`event-card-${eventId}`)).toContainText('Complet');
        await expect(page.getByTestId(`btn-unregister-event-${eventId}`)).toBeVisible();

        // 3. Tentative d'inscription supplémentaire -> refus 409 (Complet)
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        const fullRes = await (await request.post(`/api/v1/modules/evenements/events/${eventId}/register`, {
            data: { member_id: 19 },
        })).json();
        expect(fullRes.code).toBe(409);

        // 4. Président consulte les participants + CSV
        await page.request.post('/api/v1/auth/switch-persona', { data: { persona: 'president' } });
        await page.goto('/evenements');
        await Promise.all([
            page.waitForResponse((r) => r.url().includes(`/events/${eventId}/participants`)),
            page.getByTestId(`btn-event-participants-${eventId}`).click(),
        ]);
        await expect(page.getByTestId('event-participants-modal')).toBeVisible();
        await expect(page.getByTestId('btn-export-participants-csv')).toBeVisible();

        const partJson = await (await request.get(`/api/v1/modules/evenements/events/${eventId}/participants`)).json();
        expect(partJson.code).toBe(200);
        expect(partJson.data.participants.length).toBe(1);
        expect(partJson.data.csv_content).toContain('ID;Prenom;Nom;DateInscription');
    });

    test('S23-03 : Persona 2 — Contrôles RBAC/RGPD (401/403) et désinstallation propre (404 + purge assets + disparition widget /)', async ({ page, request }) => {
        await activateEventsModule(request);

        // 1. Adhérent bloqué en 403 sur création, participants et inscription d'un tiers non rattaché (#19)
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'adherent' } });
        const createForbidden = await (await request.post('/api/v1/modules/evenements/events', {
            data: { title: 'Interdit', event_date: '2026-12-30' },
        })).json();
        expect(createForbidden.code).toBe(403);

        const partForbidden = await (await request.get('/api/v1/modules/evenements/events/1/participants')).json();
        expect(partForbidden.code).toBe(403);

        const thirdPartyForbidden = await (await request.post('/api/v1/modules/evenements/events/1/register', {
            data: { member_id: 19 },
        })).json();
        expect(thirdPartyForbidden.code).toBe(403);

        // 2. Visiteur non authentifié -> 401 sur inscription
        await request.post('/api/v1/auth/logout');
        const anonReg = await (await request.post('/api/v1/modules/evenements/events/1/register', {
            data: { member_id: 1 },
        })).json();
        expect(anonReg.code).toBe(401);

        // 3. Désinstallation propre par le Superadmin
        await uninstallEventsModule(request);

        expect(fs.existsSync(PUBLIC_EVENTS_ASSETS)).toBe(false);
        expect((await request.get('/evenements')).status()).toBe(404);
        const apiRes = await (await request.get('/api/v1/modules/evenements/events')).json();
        expect(apiRes.code).toBe(404);

        await page.goto('/');
        await expect(page.getByTestId('home-events-widget')).toHaveCount(0);
    });
});
