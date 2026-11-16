<?php

declare(strict_types=1);

namespace Modules\IncompatibleModule;

use Core\Sdk\EventManager;
use Core\Sdk\ModuleInterface;

final class Module implements ModuleInterface
{
    public function install(): void
    {
    }

    public function uninstall(): void
    {
    }

    public function boot(EventManager $events): void
    {
    }
}
