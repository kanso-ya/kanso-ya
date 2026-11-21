<?php

declare(strict_types=1);

namespace Core\Api\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton gérant la connexion PDO hybride (SQLite WAL / MySQL-MariaDB).
 * Fournit les helpers dialectaux SQL pour garantir la compatibilité stricte multi-SGBD.
 */
final class Database
{
    /** @var PDO|null Instance unique de connexion PDO */
    private static ?PDO $instance = null;

    /**
     * Constructeur privé pour empêcher l'instanciation directe.
     */
    private function __construct() {}

    /**
     * Réinitialise le Singleton PDO (utile pour basculer de connexion en tests/CLI).
     *
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    /**
     * Récupère l'instance PDO unique.
     * Les variables d'environnement OS (DB_*) surchargent les valeurs de .env.
     *
     * @return PDO L'instance de connexion à la base de données.
     * @throws RuntimeException Si la connexion échoue.
     */
    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $envPath = __DIR__ . '/../../../.env';

            if (!file_exists($envPath)) {
                throw new RuntimeException('Fichier .env introuvable.');
            }

            $config = parse_ini_file($envPath);
            if ($config === false) {
                throw new RuntimeException('Impossible de parser le fichier .env.');
            }

            $readOpt = static function (string $key, string $default) use ($config): string {
                $envVal = getenv($key);
                if ($envVal !== false && $envVal !== '') {
                    return (string) $envVal;
                }
                return isset($config[$key]) ? (string) $config[$key] : $default;
            };

            $connection = strtolower($readOpt('DB_CONNECTION', 'sqlite'));

            try {
                if ($connection === 'sqlite') {
                    $dbPath = __DIR__ . '/../../../' . ltrim($readOpt('DB_DATABASE', 'storage/db/kanso-ya.sqlite'), '/');
                    self::$instance = new PDO('sqlite:' . $dbPath, null, null, [
                        PDO::ATTR_TIMEOUT => 15
                    ]);
                    self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::$instance->exec('PRAGMA busy_timeout = 15000;');
                    self::$instance->exec('PRAGMA journal_mode = WAL;');
                    self::$instance->exec('PRAGMA synchronous = NORMAL;');
                } else {
                    $dsn = sprintf(
                        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                        $readOpt('DB_HOST', '127.0.0.1'),
                        $readOpt('DB_PORT', '3306'),
                        $readOpt('DB_DATABASE', 'kanso_ya')
                    );
                    self::$instance = new PDO(
                        $dsn,
                        $readOpt('DB_USERNAME', 'root'),
                        $readOpt('DB_PASSWORD', '')
                    );
                    self::$instance->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    self::$instance->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                    self::$instance->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
                }
            } catch (PDOException $e) {
                throw new RuntimeException('Erreur de connexion à la base de données : ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    /**
     * Retourne le nom du driver PDO actif ('sqlite', 'mysql', ...).
     *
     * @param PDO|null $db Connexion optionnelle.
     * @return string
     */
    public static function getDriver(?PDO $db = null): string
    {
        $pdo = $db ?? self::getInstance();
        return strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Indique si le moteur actif est SQLite.
     *
     * @param PDO|null $db Connexion optionnelle.
     * @return bool
     */
    public static function isSqlite(?PDO $db = null): bool
    {
        return self::getDriver($db) === 'sqlite';
    }

    /**
     * Indique si le moteur actif est MySQL ou MariaDB.
     *
     * @param PDO|null $db Connexion optionnelle.
     * @return bool
     */
    public static function isMysql(?PDO $db = null): bool
    {
        return self::getDriver($db) === 'mysql';
    }

    /**
     * Démarre une transaction d'écriture compatible SQLite (BEGIN IMMEDIATE) et MySQL (START TRANSACTION).
     *
     * @param PDO $db Connexion PDO active.
     * @return void
     */
    public static function beginWriteTransaction(PDO $db): void
    {
        if (self::isSqlite($db)) {
            $db->exec('BEGIN IMMEDIATE');
            return;
        }
        $db->exec('START TRANSACTION');
    }

    /**
     * Vérifie si une colonne existe sur une table (compatible SQLite & MySQL/MariaDB).
     *
     * @param PDO    $db     Connexion PDO active.
     * @param string $table  Nom de la table (identifiant alphanumérique/underscore).
     * @param string $column Nom de la colonne recherchée.
     * @return bool
     */
    public static function tableHasColumn(PDO $db, string $table, string $column): bool
    {
        $safeTable = preg_replace('/[^a-zA-Z0-9_]/', '', $table) ?: '';
        if ($safeTable === '') {
            return false;
        }

        if (self::isSqlite($db)) {
            $rows = $db->query("PRAGMA table_info({$safeTable})")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                if (isset($row['name']) && (string) $row['name'] === $column) {
                    return true;
                }
            }
            return false;
        }

        $stmt = $db->prepare("SHOW COLUMNS FROM `{$safeTable}` LIKE :col");
        $stmt->execute(['col' => $column]);
        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    /**
     * Retourne le préfixe SQL d'insertion ignorant les doublons selon le moteur.
     *
     * @param PDO|null $db Connexion optionnelle.
     * @return string 'INSERT OR IGNORE INTO' (SQLite) ou 'INSERT IGNORE INTO' (MySQL).
     */
    public static function insertIgnorePrefix(?PDO $db = null): string
    {
        return self::isSqlite($db) ? 'INSERT OR IGNORE INTO' : 'INSERT IGNORE INTO';
    }

    /**
     * Retourne la requête d'upsert pour sys_settings compatible SQLite & MySQL/MariaDB.
     *
     * @param PDO|null $db Connexion optionnelle.
     * @return string Requête SQL préparée avec paramètres :key et :val.
     */
    public static function upsertSettingSql(?PDO $db = null): string
    {
        if (self::isSqlite($db)) {
            return "
                INSERT INTO sys_settings (setting_key, setting_value, updated_at)
                VALUES (:key, :val, CURRENT_TIMESTAMP)
                ON CONFLICT(setting_key) DO UPDATE SET
                    setting_value = excluded.setting_value,
                    updated_at = CURRENT_TIMESTAMP
            ";
        }

        return "
            INSERT INTO sys_settings (setting_key, setting_value, updated_at)
            VALUES (:key, :val, CURRENT_TIMESTAMP)
            ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP
        ";
    }

    /**
     * Traduit un script DDL SQLite en DDL compatible MySQL 8 / MariaDB 10.6+ lorsque nécessaire.
     *
     * @param string $sqliteDdl Script SQL DDL écrit en syntaxe SQLite.
     * @param string $driver    Driver cible ('sqlite' ou 'mysql').
     * @return string Script SQL adapté au driver cible.
     */
    public static function adaptDdlForDriver(string $sqliteDdl, string $driver): string
    {
        if (strtolower($driver) !== 'mysql') {
            return $sqliteDdl;
        }

        $sql = str_replace('INTEGER PRIMARY KEY AUTOINCREMENT', 'INT AUTO_INCREMENT PRIMARY KEY', $sqliteDdl);
        // MySQL 8 strict interdit une valeur littérale DEFAULT sur une colonne TEXT
        $sql = preg_replace("/\bTEXT\s+NOT\s+NULL\s+DEFAULT\s+'[^']*'/i", 'TEXT NULL', $sql) ?? $sql;
        // Ajoute ENGINE=InnoDB et utf8mb4 sur chaque CREATE TABLE
        $sql = preg_replace('/\)\s*;/', ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;', $sql) ?? $sql;

        return $sql;
    }

    /**
     * Adapte puis exécute séquentiellement les instructions DDL (compatible SQLite & MySQL).
     *
     * @param PDO    $db        Connexion PDO active.
     * @param string $sqliteDdl Script DDL SQLite contenant une ou plusieurs instructions.
     * @return void
     */
    public static function execDdlStatements(PDO $db, string $sqliteDdl): void
    {
        $driver = self::getDriver($db);
        $adapted = self::adaptDdlForDriver($sqliteDdl, $driver);

        if ($driver === 'sqlite') {
            $db->exec($adapted);
            return;
        }

        $statements = preg_split('/;\s*(?:\r?\n|$)/', $adapted) ?: [];
        foreach ($statements as $stmt) {
            $clean = trim((string) preg_replace('/^\s*--.*$/m', '', $stmt));
            if ($clean !== '') {
                $db->exec($clean);
            }
        }
    }

    /**
     * Exécute une opération dans une transaction d'écriture avec retry sur verrous SQLite WAL
     * et compatibilité native MySQL/MariaDB.
     *
     * @template T
     * @param callable(): T $operation
     * @return T
     */
    public static function transaction(callable $operation): mixed
    {
        $db = self::getInstance();
        $maxAttempts = self::isSqlite($db) ? 20 : 3;
        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                if ($db->inTransaction()) {
                    $db->exec('ROLLBACK');
                }
                self::beginWriteTransaction($db);
                $result = $operation();
                $db->exec('COMMIT');
                return $result;
            } catch (\Throwable $e) {
                try {
                    if ($db->inTransaction()) {
                        $db->exec('ROLLBACK');
                    }
                } catch (\Throwable) {
                }

                $msg = strtolower($e->getMessage());
                if (($attempt < $maxAttempts) && (str_contains($msg, 'locked') || str_contains($msg, 'busy') || str_contains($msg, 'deadlock'))) {
                    usleep(random_int(10000, 50000));
                    continue;
                }
                throw $e;
            }
        }

        throw new RuntimeException('Transaction impossible après verrous répétés.');
    }
}
