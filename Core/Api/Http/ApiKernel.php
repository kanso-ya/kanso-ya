<?php

declare(strict_types=1);

namespace Core\Api\Http;

use Core\Api\Database\Database;
use Core\Api\Http\Routes\CoreApiRoutes;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Core\Api\Services\ModuleManager;
use Throwable;

/**
 * Noyau HTTP de l'API REST Kanso-ya.
 * Gère le bootstrap (buffer, session, shutdown handler), le formatage JSend HTTP 200,
 * le confinement strict des hooks QA, la résolution du contexte d'authentification
 * et la délégation au routeur ApiRouter.
 */
final class ApiKernel
{
    /**
     * Détermine si les hooks QA (switch-persona et stub tokens) sont activés.
     * Retourne true uniquement si APP_ENV === 'testing' et QA_HOOKS_ENABLED === '1'.
     */
    public static function isQaHooksEnabled(): bool
    {
        $envFile = dirname(__DIR__, 3) . '/.env';
        $parsed = null;

        $readEnv = static function (string $key) use ($envFile, &$parsed): ?string {
            $val = getenv($key);
            if ($val !== false && $val !== '') {
                return (string) $val;
            }
            if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
                return (string) $_ENV[$key];
            }
            if ($parsed === null) {
                $parsed = is_file($envFile) ? (@parse_ini_file($envFile) ?: []) : [];
            }
            return isset($parsed[$key]) ? (string) $parsed[$key] : null;
        };

        $appEnv = $readEnv('APP_ENV');
        $qaHooks = $readEnv('QA_HOOKS_ENABLED');

        return $appEnv === 'testing' && (string) $qaHooks === '1';
    }

    /**
     * Envoie une réponse JSON normalisée JSend (HTTP 200 constant).
     *
     * @param array<string, mixed> $response Tableau contenant code, message, data.
     * @return never
     */
    public static function sendJson(array $response): never
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        http_response_code(200);
        header('Content-Type: application/json; charset=utf-8');

        $code = isset($response['code']) ? (int) $response['code'] : 200;
        $status = $response['status'] ?? ($code >= 200 && $code < 400 ? 'success' : 'error');

        $normalized = [
            'status'  => $status,
            'success' => $status === 'success',
            'code'    => $code,
            'message' => (string) ($response['message'] ?? ''),
            'data'    => $response['data'] ?? null,
        ];

        echo json_encode($normalized, JSON_UNESCAPED_UNICODE);
        exit;
    }

    /**
     * Journalise une tentative d'accès non autorisé (403) dans sys_logs.
     */
    public static function logUnauthorizedAttempt(string $targetUri): void
    {
        try {
            $db = Database::getInstance();
            $stmt = $db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (NULL, 'UNAUTHORIZED_ACCESS_ATTEMPT', :det, :ip)
            ");
            $stmt->execute([
                'det' => 'Route refusée (403) : ' . $targetUri,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {
        }
    }

    /**
     * Point d'exécution principal de la requête API.
     *
     * @return never
     */
    public function handle(): never
    {
        ob_start();
        ini_set('display_errors', '0');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        header('Content-Type: application/json; charset=utf-8');

        // Intercepteur d'erreur fatale garantissant qu'aucune réponse vide ne sorte jamais
        register_shutdown_function(static function (): void {
            $err = error_get_last();
            if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                while (ob_get_level() > 0) {
                    ob_end_clean();
                }
                http_response_code(200);
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'status'  => 'error',
                    'success' => false,
                    'code'    => 500,
                    'message' => 'Fatal Error: ' . $err['message'] . ' in ' . basename($err['file']) . ':' . $err['line'],
                    'data'    => null,
                ], JSON_UNESCAPED_UNICODE);
            }
        });

        try {
            // Pré-chargement SDK pour enregistrer les class_alias rétrocompatibles v1 avant le boot des modules
            class_exists(EventManager::class);
            interface_exists(ModuleInterface::class);

            // Initialisation dynamique des modules actifs avec Safe Mode
            ModuleManager::boot();

            $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
            $uri = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/');

            $rawInput = file_get_contents('php://input');
            $decoded = is_string($rawInput) && $rawInput !== '' ? json_decode($rawInput, true) : [];
            /** @var array<string, mixed> $payload */
            $payload = is_array($decoded) ? $decoded : [];

            // Hook QA strictement confiné à APP_ENV=testing & QA_HOOKS_ENABLED=1
            if ($method === 'POST' && $uri === '/api/v1/auth/switch-persona') {
                $this->handleSwitchPersona($payload);
            }

            $authContext = $this->resolveAuthContext($uri);

            $context = array_merge($authContext, [
                'method'  => $method,
                'uri'     => $uri,
                'payload' => $payload,
                'query'   => $_GET,
                'post'    => $_POST,
                'files'   => $_FILES,
            ]);

            $router = new ApiRouter();
            CoreApiRoutes::register($router);

            self::sendJson($router->dispatch($method, $uri, $context));
        } catch (Throwable $e) {
            self::sendJson([
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur interne du serveur : ' . $e->getMessage(),
                'data'    => null,
            ]);
        }
    }

    /**
     * Traite le hook QA switch-persona (uniquement si APP_ENV=testing et QA_HOOKS_ENABLED=1).
     *
     * @param array<string, mixed> $payload
     * @return never
     */
    private function handleSwitchPersona(array $payload): never
    {
        if (!self::isQaHooksEnabled()) {
            self::sendJson([
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Route non trouvée',
                'data'    => null,
            ]);
        }

        $persona = (string) ($payload['persona'] ?? 'president');
        $_SESSION['is_superadmin'] = false;
        unset($_SESSION['sys_admin_id']);

        if ($persona === 'visitor') {
            $_SESSION['user_id'] = 0;
            $_SESSION['role_name'] = 'Visiteur';
        } elseif ($persona === 'tutorA' || $persona === 'adherent') {
            $_SESSION['user_id'] = 10;
            $_SESSION['role_name'] = 'Adhérent';
        } elseif ($persona === 'tutorB') {
            $_SESSION['user_id'] = 2;
            $_SESSION['role_name'] = 'Adhérent';
        } elseif ($persona === 'redacteur') {
            $_SESSION['user_id'] = 3;
            $_SESSION['role_name'] = 'Rédacteur';
        } elseif ($persona === 'entraineur') {
            $_SESSION['user_id'] = 2;
            $_SESSION['role_name'] = 'Entraîneur';
        } elseif ($persona === 'secretaire') {
            $_SESSION['user_id'] = 5;
            $_SESSION['role_name'] = 'Secrétaire';
        } elseif ($persona === 'tresorier') {
            $_SESSION['user_id'] = 4;
            $_SESSION['role_name'] = 'Trésorier';
        } elseif ($persona === 'superadmin') {
            $_SESSION['user_id'] = 1;
            $_SESSION['role_name'] = 'Superadmin';
            $_SESSION['is_superadmin'] = true;
        } else {
            $_SESSION['user_id'] = 1;
            $_SESSION['role_name'] = 'Président';
        }

        session_write_close();

        self::sendJson([
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Compte actif : ' . $_SESSION['role_name'],
            'data'    => ['role' => $_SESSION['role_name']],
        ]);
    }

    /**
     * Charge le contexte d'authentification, la session et les permissions RBAC.
     *
     * @return array{
     *     user_id: int,
     *     role: string,
     *     is_superadmin: bool,
     *     is_authenticated: bool,
     *     permissions: array<int, string>
     * }
     */
    private function resolveAuthContext(string $uri): array
    {
        $authHeader = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        $currentUserId = (int) ($_SESSION['user_id'] ?? 0);
        $currentRole = (string) ($_SESSION['role_name'] ?? 'Visiteur');
        $isSuperadmin = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));
        $isAuthenticated = ($currentRole !== 'Visiteur' && $currentRole !== '' && $currentUserId > 0);

        if (!str_contains($uri, 'login') && !str_contains($uri, 'logout')) {
            session_write_close();
        }

        if (self::isQaHooksEnabled()) {
            if (str_contains($authHeader, 'stub-tutor-a-token')) {
                $currentUserId = 10;
                $currentRole = 'Adhérent';
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-tutor-b-token')) {
                $currentUserId = 2;
                $currentRole = 'Adhérent';
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-redacteur-token')) {
                $currentUserId = 3;
                $currentRole = 'Rédacteur';
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-entraineur-token')) {
                $currentUserId = 2;
                $currentRole = 'Entraîneur';
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-tresorier-token')) {
                $currentUserId = 4;
                $currentRole = 'Trésorier';
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-superadmin-token')) {
                $currentUserId = 1;
                $currentRole = 'Superadmin';
                $isSuperadmin = true;
                $isAuthenticated = true;
            } elseif (str_contains($authHeader, 'stub-visitor-token')) {
                $currentUserId = 0;
                $currentRole = '';
                $isAuthenticated = false;
            }
        }

        $permissions = $this->resolvePermissions($currentRole, $isSuperadmin);

        return [
            'user_id'          => $currentUserId,
            'role'             => $currentRole,
            'is_superadmin'    => $isSuperadmin,
            'is_authenticated' => $isAuthenticated,
            'permissions'      => $permissions,
        ];
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
}
