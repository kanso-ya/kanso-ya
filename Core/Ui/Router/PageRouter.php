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
            $filtered = EventManager::getInstance()->applyFilters('ui_routes', $routes, [
                'uri' => $uri,
            ]);
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

        // Contrôle d'accès déclaratif sur la route (auth, perms, superadmin_only)
        $accessError = $this->checkRouteAccess($routeDef);
        if ($accessError !== null) {
            $this->renderAccessDenied($accessError['code'], $accessError['message']);
            return;
        }

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
     * @return array<string, array{
     *     page: string,
     *     query?: array<string, string>,
     *     auth?: bool,
     *     perms?: array<int, string>,
     *     superadmin_only?: bool
     * }>
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
     * Vérifie les contraintes d'accès déclarées sur une route UI (auth, perms, superadmin_only).
     *
     * @param array<string, mixed> $routeDef
     * @return array{code: int, message: string}|null
     */
    private function checkRouteAccess(array $routeDef): ?array
    {
        $requireSuperadmin = !empty($routeDef['superadmin_only']);
        $requireAuth = !empty($routeDef['auth']);

        $requiredPerms = [];
        if (isset($routeDef['perms'])) {
            if (is_array($routeDef['perms'])) {
                foreach ($routeDef['perms'] as $perm) {
                    $p = trim((string) $perm);
                    if ($p !== '') {
                        $requiredPerms[] = $p;
                    }
                }
            } elseif (is_string($routeDef['perms']) && trim($routeDef['perms']) !== '') {
                $requiredPerms[] = trim($routeDef['perms']);
            }
        }

        if (!$requireSuperadmin && !$requireAuth && $requiredPerms === []) {
            return null;
        }

        $currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 0;
        $currentRole = isset($_SESSION['role_name']) ? (string) $_SESSION['role_name'] : 'Visiteur';
        $isSuperadmin = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));
        $isLoggedIn = $isSuperadmin || ($currentRole !== 'Visiteur' && $currentRole !== '' && $currentUserId > 0);

        if ($requireSuperadmin && !$isSuperadmin) {
            return [
                'code'    => 403,
                'message' => 'Accès refusé (403) : cette page est réservée au Superadmin.',
            ];
        }

        if ($requireAuth && !$isLoggedIn) {
            return [
                'code'    => 401,
                'message' => 'Veuillez vous connecter avec vos identifiants pour accéder à cet espace.',
            ];
        }

        if ($requiredPerms !== []) {
            if (!$isLoggedIn) {
                return [
                    'code'    => 401,
                    'message' => 'Veuillez vous connecter avec vos identifiants pour accéder à cet espace.',
                ];
            }

            $userPerms = $this->resolvePermissions($currentRole, $isSuperadmin);
            $hasPerm = $isSuperadmin;
            if (!$hasPerm) {
                foreach ($requiredPerms as $perm) {
                    if (in_array($perm, $userPerms, true)) {
                        $hasPerm = true;
                        break;
                    }
                }
            }

            if (!$hasPerm) {
                $roleLabel = $currentRole !== '' ? $currentRole : 'Visiteur';
                return [
                    'code'    => 403,
                    'message' => "Accès restreint (403) : votre compte ({$roleLabel}) ne dispose pas des permissions nécessaires.",
                ];
            }
        }

        return null;
    }

    /**
     * Résout la liste des permissions associées au rôle courant.
     *
     * @return array<int, string>
     */
    private function resolvePermissions(string $currentRole, bool $isSuperadmin): array
    {
        if ($isSuperadmin) {
            return ['system.admin', 'settings.manage', 'logs.view'];
        }

        return match ($currentRole) {
            'Président' => [
                'members.view', 'members.edit', 'roles.manage',
                'finances.manage', 'articles.write', 'articles.publish',
                'pages.manage', 'settings.manage', 'settings.view', 'logs.view', 'stats.view',
            ],
            'Secrétaire' => ['members.view', 'members.edit', 'articles.write', 'articles.publish', 'stats.view', 'settings.manage'],
            'Trésorier'  => ['members.view', 'finances.manage', 'settings.manage'],
            'Rédacteur'  => ['articles.write'],
            'Entraîneur' => ['members.view', 'articles.write', 'stats.view'],
            'Adhérent'   => ['profile.view'],
            default      => [],
        };
    }

    /**
     * Résout un nom de vue relatif (ex: 'stats.php', 'modules/BoutiqueUi/Pages/boutique.php') ou absolu en chemin physique.
     */
    private function resolvePagePath(string $page): string
    {
        if ($page === '') {
            return '';
        }

        if (is_file($page)) {
            return $page;
        }

        $projectRelative = $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($page, '/\\');
        if (is_file($projectRelative)) {
            return $projectRelative;
        }

        return $this->pagesDir . DIRECTORY_SEPARATOR . ltrim($page, '/\\');
    }

    /**
     * Vérifie que le chemin cible est un fichier .php autorisé dans Core/Ui/Pages/ ou modules/*Ui/Pages/.
     */
    public function isAllowedPagePath(string $path): bool
    {
        if ($path === '' || !str_ends_with(strtolower($path), '.php')) {
            return false;
        }

        $realFile = realpath($path);
        if ($realFile === false || !is_file($realFile) || !str_ends_with(strtolower($realFile), '.php')) {
            return false;
        }

        $normalizedFile = str_replace('\\', '/', $realFile);

        $realCorePages = realpath($this->pagesDir);
        if ($realCorePages !== false) {
            $normalizedCorePages = rtrim(str_replace('\\', '/', $realCorePages), '/') . '/';
            if (str_starts_with($normalizedFile, $normalizedCorePages)) {
                return true;
            }
        }

        $realModulesDir = realpath($this->projectRoot . DIRECTORY_SEPARATOR . 'modules');
        if ($realModulesDir !== false) {
            $normalizedModulesDir = rtrim(str_replace('\\', '/', $realModulesDir), '/') . '/';
            if (str_starts_with($normalizedFile, $normalizedModulesDir)) {
                $relative = substr($normalizedFile, strlen($normalizedModulesDir));
                if (preg_match('#^[A-Za-z0-9_]+Ui/Pages/.+\.php$#', $relative) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Affiche la réponse d'accès refusé (401 ou 403) standardisée.
     */
    private function renderAccessDenied(int $statusCode, string $message): void
    {
        http_response_code($statusCode);

        if ($statusCode === 401) {
            $layoutFile = dirname($this->pagesDir) . DIRECTORY_SEPARATOR . 'Templates' . DIRECTORY_SEPARATOR . 'layout.php';
            if (is_file($layoutFile)) {
                $this->includeAccessDeniedLayout($layoutFile, $message);
                return;
            }
            echo '401 Unauthorized';
            return;
        }

        $error403 = $this->pagesDir . DIRECTORY_SEPARATOR . 'errors' . DIRECTORY_SEPARATOR . '403.php';
        if (is_file($error403)) {
            $this->includePage($error403, ['forbiddenMessage' => $message]);
            return;
        }
        echo '403 Forbidden';
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
     *
     * @param array<string, mixed> $vars
     */
    private function includePage(string $__kansoPageFile, array $vars = []): void
    {
        if ($vars !== []) {
            extract($vars, EXTR_SKIP);
        }
        require $__kansoPageFile;
    }

    /**
     * Rend le layout principal en mode 401 (authentification requise).
     */
    private function includeAccessDeniedLayout(string $__kansoLayoutFile, string $message): void
    {
        $pageTitle = 'Authentification Requise (401)';
        $activeNav = '';
        $requireAuth = true;
        $requiredPerms = [];
        $accessDeniedReason = $message;
        $pageContent = '';
        require $__kansoLayoutFile;
    }
}
