<?php

declare(strict_types=1);

/**
 * Point d'entrée unique de l'API REST du CMS Kanso-ya.
 * Garantit un retour JSON JSend HTTP 200 en toutes circonstances.
 */

if (!defined('KANSO_API')) {
    define('KANSO_API', true);
}

require_once __DIR__ . '/../vendor/autoload.php';

(new \Core\Api\Http\ApiKernel())->handle();
