import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

const PROJECT_ROOT = process.cwd();

function runPhp(code: string): string {
    return execFileSync('php', ['-r', code], {
        cwd: PROJECT_ROOT,
        encoding: 'utf8',
    }).trim();
}

test.describe('Sprint 18 (Lot R4) — Moteur de Surcharge UI (EventManager) & Safe Mode Étendu', () => {
    test.describe.configure({ mode: 'serial' });

    test('S18-01 : Filtres ui_text, ui_slot_* et ui_assets (surcharge & filtrage sécurisé des URLs)', async ({ page }) => {
        // 1. Vérification HTTP sur la vitrine / (textes par défaut exposés via UiHelpers)
        await page.goto('/');
        await expect(page.locator('[data-testid="home-hero-title"]')).toContainText('Bienvenue au Dojo Kanso-ya');

        // 2. Vérification runtime PHP : ui_text, ui_slot_home_hero, ui_slot_footer_links & ui_assets
        const raw = runPhp(`
            require 'vendor/autoload.php';
            use Core\\Api\\Sdk\\EventManager;
            use Core\\Api\\Sdk\\UiHelpers;

            $em = EventManager::getInstance();
            $em->reset();

            $em->addFilter('ui_text', function (string $val, array $ctx): string {
                if (($ctx['key'] ?? '') === 'home.hero.title') {
                    return 'Dojo Personnalisé <Module>';
                }
                return $val;
            });

            $em->addFilter('ui_slot_home_hero', function (string $html): string {
                return $html . '<div data-testid="slot-custom-banner">Annonce Module</div>';
            });

            $em->addFilter('ui_assets', function (array $assets, array $ctx): array {
                if (($ctx['page'] ?? '') === 'home') {
                    $assets['styles'][] = '/assets/modules/boutique/boutique.css';
                    $assets['scripts'][] = '/assets/modules/boutique/app.js';
                    // Tentatives interdites (doivent être filtrées)
                    $assets['scripts'][] = 'https://evil.example/xss.js';
                    $assets['styles'][] = '/assets/../.env';
                }
                return $assets;
            });

            echo json_encode([
                'text_escaped' => UiHelpers::text('home.hero.title', 'Default', ['page' => 'home']),
                'text_untouched' => UiHelpers::text('other.key', 'Orig', ['page' => 'home']),
                'slot_html' => UiHelpers::slot('home_hero', ['page' => 'home']),
                'assets' => UiHelpers::assets('home', ['/assets/ts/home.js'], []),
            ], JSON_UNESCAPED_UNICODE);
        `);

        const data = JSON.parse(raw);
        expect(data.text_escaped).toBe('Dojo Personnalisé &lt;Module&gt;');
        expect(data.text_untouched).toBe('Orig');
        expect(data.slot_html).toContain('data-testid="slot-custom-banner"');
        expect(data.assets.styles).toEqual(['/assets/modules/boutique/boutique.css']);
        expect(data.assets.scripts).toEqual(['/assets/ts/home.js', '/assets/modules/boutique/app.js']);
    });

    test('S18-02 : Filtre ui_tabs (+ alias rétrocompatible stats_tabs) avec tri par order', async () => {
        const raw = runPhp(`
            require 'vendor/autoload.php';
            use Core\\Api\\Sdk\\EventManager;
            use Core\\Api\\Sdk\\UiHelpers;

            $em = EventManager::getInstance();
            $em->reset();

            $em->addFilter('ui_tabs', function (array $tabs, array $ctx): array {
                if (($ctx['page'] ?? '') === 'stats') {
                    $tabs[] = ['id' => 'custom_first', 'label' => '⭐ Prioritaire', 'order' => 1];
                }
                if (($ctx['page'] ?? '') === 'sys-smtp') {
                    $tabs[] = ['id' => 'dkim', 'label' => '🔑 DKIM', 'content' => '<p>DKIM OK</p>'];
                }
                return $tabs;
            });

            // Alias rétrocompatible stats_tabs
            $em->addFilter('stats_tabs', function (array $tabs): array {
                $tabs[] = ['id' => 'legacy_boutique', 'label' => '🛍️ Ventes Boutique', 'order' => 99];
                return $tabs;
            });

            $statsTabs = UiHelpers::tabs('stats', [
                ['id' => 'kpis', 'label' => 'Vue Générale', 'active' => true],
            ], ['role' => 'Président']);

            $smtpTabs = UiHelpers::tabs('sys-smtp', [
                ['id' => 'config', 'label' => 'Config', 'active' => true],
            ], ['role' => 'Superadmin']);

            echo json_encode([
                'stats_ids' => array_column($statsTabs, 'id'),
                'smtp_ids'  => array_column($smtpTabs, 'id'),
            ]);
        `);

        const data = JSON.parse(raw);
        expect(data.stats_ids).toEqual(['custom_first', 'kpis', 'legacy_boutique']);
        expect(data.smtp_ids).toEqual(['config', 'dkim']);
    });

    test('S18-03 : ui_page_override autorise modules/*Ui/Pages/ et rejette tout path traversal avec trace sys_logs', async ({ request }) => {
        const raw = runPhp(`
            define('KANSO_ENTRY', true);
            require 'vendor/autoload.php';
            use Core\\Api\\Sdk\\EventManager;
            use Core\\Ui\\Router\\PageRouter;

            $router = new PageRouter();
            $em = EventManager::getInstance();
            $em->reset();

            // 1. Chemin légitime dans modules/BoutiqueUi/Pages/
            $legit = $router->isAllowedPagePath(__DIR__ . '/modules/BoutiqueUi/Pages/boutique.php');
            // 2. Tentative de path traversal vers .env et vers Core/Api
            $traversalEnv = $router->isAllowedPagePath(__DIR__ . '/Core/Ui/Pages/../../../.env');
            $traversalApi = $router->isAllowedPagePath(__DIR__ . '/Core/Api/Database/Database.php');

            // 3. Dispatch avec un filtre malveillant ui_page_override -> doit replier sur home.php et tracer UI_PAGE_OVERRIDE_BLOCKED
            $em->addFilter('ui_page_override', function (string $path, array $ctx): string {
                if (($ctx['page_id'] ?? '') === 'home') {
                    return __DIR__ . '/../../../.env';
                }
                return $path;
            });

            ob_start();
            $router->dispatch('/');
            $html = ob_get_clean();

            echo json_encode([
                'legit' => $legit,
                'traversal_env' => $traversalEnv,
                'traversal_api' => $traversalApi,
                'fallback_home_rendered' => str_contains($html, 'Bienvenue au Dojo Kanso-ya'),
                'no_env_leak' => !str_contains($html, 'DB_CONNECTION'),
            ]);
        `);

        const data = JSON.parse(raw);
        expect(data.legit).toBe(true);
        expect(data.traversal_env).toBe(false);
        expect(data.traversal_api).toBe(false);
        expect(data.fallback_home_rendered).toBe(true);
        expect(data.no_env_leak).toBe(true);

        // Vérifier que l'incident UI_PAGE_OVERRIDE_BLOCKED est bien tracé dans sys_logs
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        const logsRes = await request.get('/api/v1/sys/logs');
        const logsJson = await logsRes.json();
        expect(logsJson.status).toBe('success');
        const blockedLog = logsJson.data.find((l: any) => l.action === 'UI_PAGE_OVERRIDE_BLOCKED');
        expect(blockedLog).toBeDefined();
    });

    test('S18-04 : Safe Mode étendu aux filtres UI — exception isolée, valeur intacte et quarantaine automatique du module fautif', async ({ request }) => {
        // S'assurer que la table modules contient BoutiqueApi et le mettre actif pour le test de quarantaine
        await request.post('/api/v1/auth/switch-persona', { data: { persona: 'superadmin' } });
        await request.post('/api/v1/sys/modules/scan');

        const raw = runPhp(`
            require 'vendor/autoload.php';
            use Core\\Api\\Database\\Database;
            use Core\\Api\\Sdk\\EventManager;
            use Core\\Api\\Sdk\\UiHelpers;
            use Core\\Api\\Services\\ModuleManager;

            $db = Database::getInstance();
            $db->prepare("UPDATE modules SET is_active = 1, status = 'active', last_error = NULL WHERE folder_name = 'BoutiqueApi'")->execute();

            $em = EventManager::getInstance();
            $em->reset();

            $mm = new ModuleManager($db);
            $em->setQuarantineHandler(function (string $folder, Throwable $e, string $ctx) use ($mm): void {
                $mm->quarantineModule($folder, $e, $ctx);
            });

            // Simule l'enregistrement d'un filtre défaillant par BoutiqueApi
            $em->setCurrentModule('BoutiqueApi');
            $em->addFilter('ui_text', function (string $val): string {
                throw new RuntimeException('Erreur critique volontaire dans filtre ui_text');
            });
            $em->setCurrentModule(null);

            // L'appel ne doit PAS planter et doit retourner la valeur initiale intacte
            $safeResult = UiHelpers::text('home.hero.title', 'Titre Intact Core');

            $row = $db->query("SELECT is_active, status, last_error FROM modules WHERE folder_name = 'BoutiqueApi'")->fetch(PDO::FETCH_ASSOC);

            // Nettoyage : remise en état inactif pour ne pas polluer les tests suivants
            $db->prepare("UPDATE modules SET is_active = 0, status = 'inactive', last_error = NULL WHERE folder_name = 'BoutiqueApi'")->execute();

            echo json_encode([
                'safe_result' => $safeResult,
                'is_active'   => (int) ($row['is_active'] ?? -1),
                'status'      => (string) ($row['status'] ?? ''),
                'last_error'  => (string) ($row['last_error'] ?? ''),
            ], JSON_UNESCAPED_UNICODE);
        `);

        const data = JSON.parse(raw);
        expect(data.safe_result).toBe('Titre Intact Core');
        expect(data.is_active).toBe(0);
        expect(data.status).toBe('crashed');
        expect(data.last_error).toContain('filter:ui_text');
        expect(data.last_error).toContain('Erreur critique volontaire dans filtre ui_text');

        // Vérification trace MODULE_SAFE_MODE_TRIGGERED dans sys_logs
        const logsRes = await request.get('/api/v1/sys/logs');
        const logsJson = await logsRes.json();
        const safeLog = logsJson.data.find((l: any) => l.action === 'MODULE_SAFE_MODE_TRIGGERED' && String(l.details).includes('filter:ui_text'));
        expect(safeLog).toBeDefined();
    });
});
