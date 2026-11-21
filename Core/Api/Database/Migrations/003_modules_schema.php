<?php

declare(strict_types=1);

/**
 * Migration 003 : Table des modules externes (SDK Lot 3).
 * Stocke l'état d'installation, d'activation et le statut de résilience (Safe Mode / Crashed).
 */

require_once __DIR__ . '/../Database.php';

use Core\Api\Database\Database;

function runModulesSchemaMigration(): void
{
    $db = Database::getInstance();

    $sql = <<<SQL
    CREATE TABLE IF NOT EXISTS modules (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        folder_name VARCHAR(100) NOT NULL UNIQUE,
        name VARCHAR(150) NOT NULL,
        version VARCHAR(30) NOT NULL,
        is_active INTEGER NOT NULL DEFAULT 0,
        status VARCHAR(30) NOT NULL DEFAULT 'inactive',
        last_error TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    );
SQL;

    $db->exec($sql);
    echo "[OK] Migration 003 : Table modules créée avec succès.\n";
}

runModulesSchemaMigration();
