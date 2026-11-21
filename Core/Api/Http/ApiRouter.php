<?php

declare(strict_types=1);

namespace Core\Api\Http;

use Core\Api\Sdk\EventManager;

/**
 * Routeur HTTP de l'API REST Kanso-ya.
 * Enregistre et dispatche les routes déclarées par CoreApiRoutes ainsi que
 * les routes modulaires injectées via le filtre EventManager 'api_routes' (Sprint 17).
 */
final class ApiRouter
{
    /**
     * @var array<int, array{
     *     methods: array<int, string>,
     *     pattern: string,
     *     handler: callable,
     *     perms: array<int, string>
     * }>
     */
    private array $routes = [];

    /**
     * Enregistre une route API.
     *
     * @param string|array<int, string> $methods Méthode HTTP unique ou liste de méthodes.
     * @param string                    $pattern Chemin exact (ex: '/api/v1/members') ou regex délimitée par '#'.
     * @param callable                  $handler Callback recevant ($context, $matches) et retournant une réponse JSend.
     * @param array<int, string>        $perms   Permissions optionnelles requises (au moins une, ou superadmin).
     */
    public function addRoute(string|array $methods, string $pattern, callable $handler, array $perms = []): void
    {
        $methodList = is_array($methods) ? $methods : [$methods];
        $normalizedMethods = array_map(static fn(string $m): string => strtoupper($m), $methodList);

        $this->routes[] = [
            'methods' => $normalizedMethods,
            'pattern' => $pattern,
            'handler' => $handler,
            'perms'   => $perms,
        ];
    }

    /**
     * Dispatche une requête HTTP vers la première route correspondante (Core puis modules via 'api_routes').
     *
     * @param string               $method  Méthode HTTP en majuscules.
     * @param string               $uri     Chemin URI sans query string.
     * @param array<string, mixed> $context Contexte de requête (auth, permissions, payload, etc.).
     * @return array<string, mixed> Réponse JSend prête à être sérialisée.
     */
    public function dispatch(string $method, string $uri, array $context): array
    {
        $allRoutes = $this->routes;

        // Point d'extension pour les routes API déclarées par les modules (Sprint 17)
        $moduleRoutes = EventManager::getInstance()->applyFilters('api_routes', [], [
            'method' => $method,
            'uri'    => $uri,
        ]);

        if (is_array($moduleRoutes)) {
            foreach ($moduleRoutes as $mr) {
                if (!is_array($mr) || !isset($mr['method'], $mr['pattern'], $mr['handler']) || !is_callable($mr['handler'])) {
                    continue;
                }
                $mList = is_array($mr['method']) ? $mr['method'] : [(string) $mr['method']];
                $perms = isset($mr['perms']) && is_array($mr['perms'])
                    ? array_values(array_map('strval', $mr['perms']))
                    : [];

                $allRoutes[] = [
                    'methods' => array_map(static fn(mixed $m): string => strtoupper((string) $m), $mList),
                    'pattern' => (string) $mr['pattern'],
                    'handler' => $mr['handler'],
                    'perms'   => $perms,
                ];
            }
        }

        foreach ($allRoutes as $route) {
            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $matches = [];
            $pattern = $route['pattern'];
            if (str_starts_with($pattern, '#')) {
                if (preg_match($pattern, $uri, $matches) !== 1) {
                    continue;
                }
            } else {
                if ($uri !== $pattern) {
                    continue;
                }
                $matches = [$uri];
            }

            // Vérification générique des permissions pour les routes qui déclarent 'perms'
            if (!empty($route['perms'])) {
                $isSuperadmin = (bool) ($context['is_superadmin'] ?? false);
                /** @var array<int, string> $userPerms */
                $userPerms = is_array($context['permissions'] ?? null) ? $context['permissions'] : [];
                $hasPerm = $isSuperadmin;
                if (!$hasPerm) {
                    foreach ($route['perms'] as $requiredPerm) {
                        if (in_array($requiredPerm, $userPerms, true)) {
                            $hasPerm = true;
                            break;
                        }
                    }
                }
                if (!$hasPerm) {
                    ApiKernel::logUnauthorizedAttempt($uri);
                    return [
                        'status'  => 'error',
                        'code'    => 403,
                        'message' => 'Accès refusé.',
                        'data'    => null,
                    ];
                }
            }

            /** @var array<string, mixed> $result */
            $result = ($route['handler'])($context, $matches);
            return $result;
        }

        return [
            'status'  => 'error',
            'code'    => 404,
            'message' => 'Endpoint API introuvable : ' . $method . ' ' . $uri,
            'data'    => null,
        ];
    }
}
