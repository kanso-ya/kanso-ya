<?php

declare(strict_types=1);

namespace Modules\IncompatibleModuleApi;

use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;

/**
 * Module démonstrateur incompatible (Core >= 3.0.0 requis) pour valider le rejet par ModuleScanner.
 */
final class Module implements ModuleInterface
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function boot(EventManager $eventManager): void
    {
    }
}
