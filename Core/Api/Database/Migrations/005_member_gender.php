<?php

declare(strict_types=1);

/**
 * Migration 005 : Champ optionnel gender sur members (Lot 4 — Sprint 21).
 * Conforme RGPD (nullable par défaut) et compatible SQLite 3 WAL / MySQL 8 / MariaDB 10.6+.
 */

require_once __DIR__ . '/../Database.php';

use Core\Api\Database\Database;

function runMemberGenderMigration(): void
{
    $db = Database::getInstance();

    if (!Database::tableHasColumn($db, 'members', 'gender')) {
        $db->exec('ALTER TABLE members ADD COLUMN gender VARCHAR(16) NULL DEFAULT NULL');
    }
}

runMemberGenderMigration();
