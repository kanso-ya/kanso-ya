<?php

declare(strict_types=1);

/**
 * Migration 004 : Fiabilité SMTP & Relance des E-mails (Lot 4 — Sprint 20).
 * Ajoute de manière idempotente les colonnes body_html, retry_count et last_retry_at
 * sur email_logs ainsi que les paramètres par défaut smtp_transport et smtp_timeout.
 * Compatible SQLite 3 WAL et MySQL 8 / MariaDB 10.6+.
 */

require_once __DIR__ . '/../Database.php';

use Core\Api\Database\Database;

function runSmtpReliabilityMigration(): void
{
    $db = Database::getInstance();

    if (!Database::tableHasColumn($db, 'email_logs', 'body_html')) {
        $db->exec('ALTER TABLE email_logs ADD COLUMN body_html TEXT NULL');
    }
    if (!Database::tableHasColumn($db, 'email_logs', 'retry_count')) {
        $db->exec('ALTER TABLE email_logs ADD COLUMN retry_count INTEGER NOT NULL DEFAULT 0');
    }
    if (!Database::tableHasColumn($db, 'email_logs', 'last_retry_at')) {
        $db->exec('ALTER TABLE email_logs ADD COLUMN last_retry_at DATETIME NULL');
    }

    $defaults = [
        'smtp_transport' => 'log',
        'smtp_timeout'   => '10',
    ];
    $insertIgnore = Database::insertIgnorePrefix($db);
    $stmt = $db->prepare("{$insertIgnore} sys_settings (setting_key, setting_value) VALUES (:k, :v)");
    foreach ($defaults as $k => $v) {
        $stmt->execute(['k' => $k, 'v' => $v]);
    }
}

runSmtpReliabilityMigration();
