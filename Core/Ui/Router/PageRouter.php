<?php

declare(strict_types=1);

namespace Core\Ui\Router;

use Core\Api\Sdk\EventManager;
use Core\Api\Services\ModuleManager;
use Throwable;

/**
 * Routeur frontal unique pour les vues UI du CMS Kanso-ya (Lot R2 / US 16.4).
 * Résout l'URI HTTP vers les vues internes dans Core/Ui/Pages/ (ou modules/*Ui/Pages/)
 * et retourne une erreur 404 standardisée pour toute route inconnue.
 */
final class PageRouter
{
    private string $pagesDir;
    private string $projectRoot;

    public function __construct(?string $projectRoot = null)
    {
        $this->projectRoot = $projectRoot !== null
            ? rtrim($projectRoot, '/\\')
            : dirname(__DIR__, 3);
        $this->pagesDir = $this->projectRoot . DIRECTORY_SEPARATOR . 'Core'
            . DIRECTORY_SEPARATOR . 'Ui'
            . DIRECTORY_SEPARATOR . 'Pages';
    }

    /**
     * Exécute le cycle de vie d'une requête UI.
     */
    public function dispatch(?string $requestUri = null): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        try {
            ModuleManager::boot();
        } catch (Throwable) {
        }

        $uri = $this->normalizeUri($requestUri ?? (string) ($_SERVER['REQUEST_URI'] ?? '/'));
        $routes = $this->buildRoutes();

        // Point d'extension pour les routes UI déclarées par les modules (Sprint 17+)
        try {
            $filtered = EventManager::getInstance()->applyFilters('ui_routes', $routes);
            if (is_array($filtered)) {
                $routes = $filtered;
            }
        } catch (Throwable) {
        }

        if (!isset($routes[$uri]) || !is_array($routes[$uri])) {
            $this->renderNotFound();
            return;
        }

        $routeDef = $routes[$uri];

        // Injection éventuelle de paramètres de requête (ex: slug pour /rgpd, /cgv, /mentions-legales)
        if (isset($routeDef['query']) && is_array($routeDef['query'])) {
            foreach ($routeDef['query'] as $key => $value) {
                $_GET[(string) $key] = (string) $value;
            }
        }

        $defaultPagePath = $this->resolvePagePath((string) ($routeDef['page'] ?? ''));
        $pagePath = $defaultPagePath;

        // Point d'extension ui_page_override (avec validation stricte par realpath)
        try {
            $override = EventManager::getInstance()->applyFilters('ui_page_override', $defaultPagePath);
            if (is_string($override) && $override !== '' && $this->isAllowedPagePath($override)) {
                $pagePath = $override;
            }
        } catch (Throwable) {
        }

        if (!$this->isAllowedPagePath($pagePath)) {
            $this->renderNotFound();
            return;
        }

        $this->includePage($pagePath);
    }

    /**
     * Normalise l'URI (sans query string, slashes finaux retirés, racine = '/').
     */
    public function normalizeUri(string $rawUri): string
    {
        $path = parse_url($rawUri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '/';
        }

        $normalized = '/' . ltrim($path, '/');
        if ($normalized !== '/') {
            $normalized = rtrim($normalized, '/');
        }

        return $normalized !== '' ? $normalized : '/';
    }

    /**
     * Lit la variable SUPERADMIN_URL depuis l'environnement ou .env (défaut : /sys-admin-xyz).
     */
    private function getSuperadminUrl(): string
    {
        $raw = getenv('SUPERADMIN_URL');
        if ($raw === false || trim($raw) === '') {
            if (isset($_ENV['SUPERADMIN_URL']) && trim((string) $_ENV['SUPERADMIN_URL']) !== '') {
                $raw = (string) $_ENV['SUPERADMIN_URL'];
            } else {
                $envFile = $this->projectRoot . DIRECTORY_SEPARATOR . '.env';
                if (is_file($envFile)) {
                    $parsed = @parse_ini_file($envFile);
                    if (is_array($parsed) && isset($parsed['SUPERADMIN_URL']) && trim((string) $parsed['SUPERADMIN_URL']) !== '') {
                        $raw = (string) $parsed['SUPERADMIN_URL'];
                    }
                }
            }
        }

        $url = is_string($raw) && trim($raw) !== '' ? trim($raw) : '/sys-admin-xyz';
        $url = '/' . ltrim($url, '/');
        $url = rtrim($url, '/');

        return $url !== '' ? $url : '/sys-admin-xyz';
    }

    /**
     * Construit la table des routes propres et des alias .php legacy (compatibilité E2E jusqu'au Sprint 19).
     *
     * @return array<string, array{page: string, query?: array<string, string>}>
     */
    private function buildRoutes(): array
    {
        $superadminUrl = $this->getSuperadminUrl();

        $routes = [
            // Routes propres
            '/'                    => ['page' => 'home.php'],
            '/login'               => ['page' => 'login.php'],
            '/register'            => ['page' => 'register.php'],
            '/activate-otp'        => ['page' => 'activate-otp.php'],
            '/questionnaire-sante' => ['page' => 'health-check.php'],
            '/horaires'            => ['page' => 'horaires.php'],
            '/rgpd'                => ['page' => 'legal.php', 'query' => ['slug' => 'rgpd']],
            '/cgv'                 => ['page' => 'legal.php', 'query' => ['slug' => 'cgv']],
            '/mentions-legales'    => ['page' => 'legal.php', 'query' => ['slug' => 'mentions-legales']],
            '/dashboard'           => ['page' => 'dashboard.php'],
            '/settings'            => ['page' => 'settings.php'],
            '/stats'               => ['page' => 'stats.php'],
            '/members'             => ['page' => 'members.php'],
            '/registrations'       => ['page' => 'registrations.php'],
            '/cms'                 => ['page' => 'cms.php'],
            '/disciplines'         => ['page' => 'disciplines.php'],
            '/roles'               => ['page' => 'roles.php'],
            '/boutique'            => ['page' => 'boutique.php'],
            $superadminUrl         => ['page' => 'sys-admin-login.php'],
            '/sys-console'         => ['page' => 'sys-console.php'],
            '/sys-branding'        => ['page' => 'sys-branding.php'],
            '/sys-smtp'            => ['page' => 'sys-smtp.php'],
            '/sys-modules'         => ['page' => 'sys-modules.php'],
            '/sys-logs'            => ['page' => 'sys-logs.php'],

            // Alias .php legacy (conservés temporairement jusqu'au Sprint 19 pour compatibilité E2E)
            '/index.php'           => ['page' => 'home.php'],
            '/login.php'           => ['page' => 'login.php'],
            '/register.php'        => ['page' => 'register.php'],
            '/activate-otp.php'    => ['page' => 'activate-otp.php'],
            '/health-check.php'    => ['page' => 'health-check.php'],
            '/horaires.php'        => ['page' => 'horaires.php'],
            '/legal.php'           => ['page' => 'legal.php'],
            '/dashboard.php'       => ['page' => 'dashboard.php'],
            '/settings.php'        => ['page' => 'settings.php'],
            '/stats.php'           => ['page' => 'stats.php'],
            '/members.php'         => ['page' => 'members.php'],
            '/registrations.php'   => ['page' => 'registrations.php'],
            '/cms.php'             => ['page' => 'cms.php'],
            '/disciplines.php'     => ['page' => 'disciplines.php'],
            '/roles.php'           => ['page' => 'roles.php'],
            '/boutique.php'        => ['page' => 'boutique.php'],
            '/sys-admin-login.php' => ['page' => 'sys-admin-login.php'],
            '/sys-console.php'     => ['page' => 'sys-console.php'],
            '/sys-branding.php'    => ['page' => 'sys-branding.php'],
            '/sys-smtp.php'        => ['page' => 'sys-smtp.php'],
            '/sys-modules.php'     => ['page' => 'sys-modules.php'],
            '/sys-logs.php'        => ['page' => 'sys-logs.php'],
        ];

        return $routes;
    }

    /**
     * Résout un nom de vue relatif (ex: 'stats.php') ou absolu en chemin physique.
     */
    private function resolvePagePath(string $page): string
    {
        if ($page === '') {
            return '';
        }

        if (is_file($page)) {
            return $page;
        }

        return $this->pagesDir . DIRECTORY_SEPARATOR . ltrim($page, '/\\');
    }

    /**
     * Vérifie que le chemin cible est un fichier .php autorisé dans Core/Ui/Pages/ ou modules/*Ui/Pages/.
     */
    private function isAllowedPagePath(string $path): bool
    {
        if ($path === '' || !str_ends_with(strtolower($path), '.php')) {
            return false;
        }

        $realFile = realpath($path);
        if ($realFile === false || !is_file($realFile)) {
            return false;
        }

        $realCorePages = realpath($this->pagesDir);
        if ($realCorePages !== false && str_starts_with($realFile, $realCorePages . DIRECTORY_SEPARATOR)) {
            return true;
        }

        $realModulesDir = realpath($this->projectRoot . DIRECTORY_SEPARATOR . 'modules');
        if ($realModulesDir !== false && str_starts_with($realFile, $realModulesDir . DIRECTORY_SEPARATOR)) {
            $relative = str_replace('\\', '/', substr($realFile, strlen($realModulesDir) + 1));
            if (preg_match('#^[A-Za-z0-9_]+Ui/Pages/.+\.php$#', $relative) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Affiche la vue 404 standardisée avec le code HTTP 404.
     */
    private function renderNotFound(): void
    {
        http_response_code(404);
        $error404 = $this->pagesDir . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . '404.php';
        if (is_file($error404)) {
            $this->includePage($error404);
            return;
        }
        echo '404 Not Found';
    }

    /**
     * Inclut la vue dans une portée locale isolée.
     */
    private function includePage(string $__kansoPageFile): void
    {
        require $__kansoPageFile;
    }
}
