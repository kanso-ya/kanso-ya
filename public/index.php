<?php

declare(strict_types=1);

/**
 * Front-Controller UI unique du CMS Kanso-ya (Lot R2 / US 16.4).
 * Toutes les requêtes de pages transitent par Core\Ui\Router\PageRouter.
 */

if (!defined('KANSO_ENTRY')) {
    define('KANSO_ENTRY', true);
}

require_once __DIR__ . '/../vendor/autoload.php';

(new \Core\Ui\Router\PageRouter())->dispatch();
