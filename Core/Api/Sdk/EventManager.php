<?php

declare(strict_types=1);

namespace Core\Api\Sdk;

use Throwable;

/**
 * Singleton EventManager du Core Kanso-ya (Moteur de Hooks Actions & Filtres).
 * Permet aux modules tiers et aux composants internes d'interagir sans couplage fort.
 */
final class EventManager
{
    private static ?EventManager $instance = null;

    /** @var array<string, array<int, array{callback: callable, priority: int, module: ?string}>> */
    private array $actions = [];

    /** @var array<string, array<int, array{callback: callable, priority: int, module: ?string}>> */
    private array $filters = [];

    /** @var ?string Nom du dossier du module en cours d'initialisation (pour attribution Safe Mode). */
    private ?string $currentModule = null;

    /** @var ?callable Gestionnaire de quarantaine Safe Mode : fn(string $moduleFolder, Throwable $e, string $context): void */
    private $quarantineHandler = null;

    private function __construct() {}

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Définit le module actuellement en cours de boot pour étiqueter ses hooks.
     */
    public function setCurrentModule(?string $folderName): void
    {
        $this->currentModule = $folderName !== null && trim($folderName) !== '' ? trim($folderName) : null;
    }

    /**
     * Retourne le module actuellement en cours de boot (ou null pour le Core).
     */
    public function getCurrentModule(): ?string
    {
        return $this->currentModule;
    }

    /**
     * Enregistre le gestionnaire de quarantaine Safe Mode appelé si un hook de module lève une exception.
     *
     * @param ?callable $handler fn(string $moduleFolder, Throwable $e, string $context): void
     */
    public function setQuarantineHandler(?callable $handler): void
    {
        $this->quarantineHandler = $handler;
    }

    /**
     * Enregistre un écouteur sur une Action (alias de addAction).
     *
     * @param string   $actionName Nom de l'action système.
     * @param callable $callback   Fonction de traitement.
     * @param int      $priority   Priorité d'exécution (les valeurs plus faibles s'exécutent en premier).
     */
    public function listen(string $actionName, callable $callback, int $priority = 10): void
    {
        $this->actions[$actionName][] = [
            'callback' => $callback,
            'priority' => $priority,
            'module'   => $this->currentModule,
        ];
    }

    /**
     * Alias explicite pour écouter une Action.
     */
    public function addAction(string $actionName, callable $callback, int $priority = 10): void
    {
        $this->listen($actionName, $callback, $priority);
    }

    /**
     * Émet une Action et exécute séquentiellement tous les écouteurs abonnés.
     * Chaque écouteur est encapsulé dans un try/catch pour isoler les erreurs et déclencher le Safe Mode si besoin.
     *
     * @param string               $actionName Nom de l'action émise.
     * @param array<string, mixed> $payload    Données contextuelles de l'événement.
     */
    public function dispatch(string $actionName, array $payload = []): void
    {
        if (!isset($this->actions[$actionName]) || empty($this->actions[$actionName])) {
            return;
        }

        $listeners = $this->actions[$actionName];
        usort($listeners, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($listeners as $listener) {
            try {
                ($listener['callback'])($payload);
            } catch (Throwable $e) {
                error_log(sprintf('[EventManager] Erreur dans l\'action "%s": %s', $actionName, $e->getMessage()));
                $this->notifyQuarantine($listener['module'], $e, 'action:' . $actionName);
            }
        }
    }

    /**
     * Enregistre un Filtre de modification de donnée.
     *
     * @param string   $filterName Nom du filtre.
     * @param callable $callback   Fonction recevant la valeur et retournant la valeur modifiée.
     * @param int      $priority   Priorité d'exécution.
     */
    public function addFilter(string $filterName, callable $callback, int $priority = 10): void
    {
        $this->filters[$filterName][] = [
            'callback' => $callback,
            'priority' => $priority,
            'module'   => $this->currentModule,
        ];
    }

    /**
     * Applique la chaîne des filtres enregistrés sur une valeur.
     * En cas d'exception d'un module, la valeur précédente est préservée et le module est mis en quarantaine (Safe Mode).
     *
     * @param string               $filterName Nom du filtre.
     * @param mixed                $value      Donnée initiale transmise par le Core.
     * @param array<string, mixed> $context    Contexte optionnel additionnel.
     * @return mixed Donnée filtrée et potentiellement modifiée par les modules.
     */
    public function applyFilters(string $filterName, mixed $value, array $context = []): mixed
    {
        if (!isset($this->filters[$filterName]) || empty($this->filters[$filterName])) {
            return $value;
        }

        $filters = $this->filters[$filterName];
        usort($filters, static fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        foreach ($filters as $filter) {
            $previousValue = $value;
            try {
                $value = ($filter['callback'])($value, $context);
            } catch (Throwable $e) {
                $value = $previousValue;
                error_log(sprintf('[EventManager] Erreur dans le filtre "%s": %s', $filterName, $e->getMessage()));
                $this->notifyQuarantine($filter['module'], $e, 'filter:' . $filterName);
            }
        }

        return $value;
    }

    /**
     * Déclenche le gestionnaire de quarantaine Safe Mode si un module est identifié.
     */
    private function notifyQuarantine(?string $moduleFolder, Throwable $e, string $context): void
    {
        if ($moduleFolder === null || $this->quarantineHandler === null) {
            return;
        }

        try {
            ($this->quarantineHandler)($moduleFolder, $e, $context);
        } catch (Throwable) {
            // Le Safe Mode ne doit jamais interrompre le rendu HTTP
        }
    }

    /**
     * Indique si une action dispose d'écouteurs.
     */
    public function hasAction(string $actionName): bool
    {
        return !empty($this->actions[$actionName]);
    }

    /**
     * Indique si un filtre dispose d'intercepteurs.
     */
    public function hasFilter(string $filterName): bool
    {
        return !empty($this->filters[$filterName]);
    }

    /**
     * Réinitialise tous les hooks (utile lors des tests ou rechargements).
     */
    public function reset(): void
    {
        $this->actions = [];
        $this->filters = [];
        $this->currentModule = null;
    }
}

// Alias pour compatibilité descendante avec Core\Sdk\EventManager (modules v1) et Core\Api\Services\EventManager
if (!class_exists('Core\\Sdk\\EventManager', false)) {
    class_alias(EventManager::class, 'Core\\Sdk\\EventManager');
}
if (!class_exists('Core\\Api\\Services\\EventManager', false)) {
    class_alias(EventManager::class, 'Core\\Api\\Services\\EventManager');
}
