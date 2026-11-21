<?php

declare(strict_types=1);

namespace Modules\CrashTestApi;

use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use RuntimeException;

/**
 * Module défaillant conçu pour valider le Safe Mode automatique (US 6.3 / US 17.3).
 * Lève une exception dès son boot() pour vérifier la capture d'erreur par ModuleManager.
 */
final class Module implements ModuleInterface
{
    public function install(): void
    {
        // L'installation réussit pour permettre l'activation
    }

    public function uninstall(): void
    {
        // Nettoyage vide
    }

    public function boot(EventManager $eventManager): void
    {
        throw new RuntimeException('Crash volontaire déclenché dans boot() pour validation du Safe Mode automatique.');
    }
}
