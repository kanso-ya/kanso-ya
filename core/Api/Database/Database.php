<?php

declare(strict_types=1);

namespace Core\Api\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Singleton gérant la connexion PDO hybride (SQLite/MySQL).
 * Optimisé avec busy_timeout=15000 et synchronous=NORMAL pour éliminer tout verrouillage SQLite sous Playwright.
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
     * Récupère l'instance PDO unique.
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

            try {
                if (($config['DB_CONNECTION'] ?? 'sqlite') === 'sqlite') {
                    $dbPath = __DIR__ . '/../../../' . ltrim((string) $config['DB_DATABASE'], '/');
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
                        $config['DB_HOST'] ?? '127.0.0.1',
                        $config['DB_PORT'] ?? '3306',
                        $config['DB_DATABASE'] ?? 'kanso_ya'
                    );
                    self::$instance = new PDO(
                        $dsn,
                        (string) ($config['DB_USERNAME'] ?? 'root'),
                        (string) ($config['DB_PASSWORD'] ?? '')
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
}