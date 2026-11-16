<?php

declare(strict_types=1);

namespace Core\Sdk;

/**
 * Interface standard imposée à tout module Kanso-ya.
 * Garantit un cycle de vie prévisible : install -> boot -> uninstall.
 */
interface ModuleInterface
{
    /**
     * Exécutée une seule fois lors de la première activation du module.
     * Crée les tables préfixées (ex: mod_boutique_products) et initialise la configuration.
     */
    public function install(): void;

    /**
     * Exécutée lors de la suppression définitive du module.
     * Doit supprimer les tables spécifiques créées (DROP TABLE) pour ne laisser aucun orphelin.
     */
    public function uninstall(): void;

    /**
     * Exécutée à chaque requête si le module est actif.
     * Enregistre les abonnements aux Hooks du Core (Actions et Filtres).
     *
     * @param EventManager $eventManager Instance du répartiteur d'événements.
     */
    public function boot(EventManager $eventManager): void;
}

// Alias pour compatibilité descendante avec Core\Api\Services\ModuleInterface
if (!interface_exists('Core\\Api\\Services\\ModuleInterface', false)) {
    class_alias(ModuleInterface::class, 'Core\\Api\\Services\\ModuleInterface');
}
