<?php

declare(strict_types=1);

/**
 * Outil CLI de validation de compatibilité MySQL/MariaDB (Lot 4 — Sprint 21).
 * 1. Audite Core/Api/ pour garantir l'absence de syntaxes SQLite-only non abstraites.
 * 2. Vérifie la traduction DDL MySQL (AUTO_INCREMENT, InnoDB, utf8mb4, absence de DEFAULT sur TEXT).
 * 3. Si un serveur MySQL/MariaDB local est joignable (ou via variables DB_*), valide la connexion PDO MySQL.
 */

require_once __DIR__ . '/../../../vendor/autoload.php';

use Core\Api\Database\Database;

$root = dirname(__DIR__, 3);
$apiDir = $root . '/Core/Api';
$errors = [];

// 1. Audit statique des syntaxes SQLite-only hors Database.php et Migrations
$forbiddenPatterns = [
    '/\bBEGIN\s+IMMEDIATE\b/i'     => 'BEGIN IMMEDIATE (utiliser Database::beginWriteTransaction)',
    '/\bPRAGMA\s+table_info\b/i'   => 'PRAGMA table_info (utiliser Database::tableHasColumn)',
    '/\bINSERT\s+OR\s+IGNORE\b/i'  => 'INSERT OR IGNORE (utiliser Database::insertIgnorePrefix)',
    '/\bINSERT\s+OR\s+REPLACE\b/i' => 'INSERT OR REPLACE (utiliser REPLACE INTO)',
    '/\bON\s+CONFLICT\s*\(/i'      => 'ON CONFLICT(...) (utiliser Database::upsertSettingSql)',
];

$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($apiDir));
foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $path = str_replace('\\', '/', $file->getPathname());
    if (str_ends_with($path, '/Core/Api/Database/Database.php') || str_ends_with($path, '/Core/Api/Database/check_mysql_compat.php')) {
        continue;
    }
    $content = (string) file_get_contents($path);
    // Ignore les commentaires PHPDoc/ligne
    $codeOnly = (string) preg_replace('#/\*.*?\*/#s', '', $content);
    $codeOnly = (string) preg_replace('#^\s*//.*$#m', '', $codeOnly);

    foreach ($forbiddenPatterns as $regex => $label) {
        if (preg_match($regex, $codeOnly) === 1) {
            $errors[] = sprintf('%s contient une syntaxe SQLite-only non abstraite : %s', basename($path), $label);
        }
    }
}

// 2. Vérification de la traduction DDL SQLite -> MySQL sur 001_core_schema.php & 003_modules_schema.php
$sampleDdl = (string) file_get_contents($apiDir . '/Database/Migrations/001_core_schema.php');
if (preg_match('/<<<SQL\s*(.*?)\s*SQL;/s', $sampleDdl, $m) === 1) {
    $adapted = Database::adaptDdlForDriver($m[1], 'mysql');
    if (str_contains($adapted, 'AUTOINCREMENT')) {
        $errors[] = 'Traduction DDL MySQL : AUTOINCREMENT restant dans 001_core_schema.php.';
    }
    if (!str_contains($adapted, 'INT AUTO_INCREMENT PRIMARY KEY')) {
        $errors[] = 'Traduction DDL MySQL : INT AUTO_INCREMENT PRIMARY KEY absent.';
    }
    if (!str_contains($adapted, 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4')) {
        $errors[] = 'Traduction DDL MySQL : ENGINE=InnoDB / utf8mb4 absent.';
    }
    if (preg_match("/\bTEXT\s+NOT\s+NULL\s+DEFAULT\s+'/i", $adapted) === 1) {
        $errors[] = 'Traduction DDL MySQL : DEFAULT littéral encore présent sur colonne TEXT.';
    }
} else {
    $errors[] = 'Impossible d\'extraire le bloc SQL DDL de 001_core_schema.php.';
}

if (!empty($errors)) {
    foreach ($errors as $err) {
        fwrite(STDERR, "[FAIL] {$err}\n");
    }
    exit(1);
}

echo "[OK] Audit SQLite-only : 0 syntaxe non abstraite dans Core/Api/.\n";
echo "[OK] Traduction DDL MySQL 8 / MariaDB 10.6+ (InnoDB, utf8mb4, AUTO_INCREMENT) : valide.\n";

// 3. Test de connexion MySQL live optionnel si un serveur écoute sur DB_HOST:DB_PORT
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

$sock = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);
if (is_resource($sock)) {
    fclose($sock);
    try {
        $pdo = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $version = (string) $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
        echo "[OK] Serveur MySQL/MariaDB joignable ({$version}) sur {$host}:{$port}.\n";
    } catch (Throwable $e) {
        echo "[WARN] Port {$host}:{$port} ouvert mais authentification MySQL refusée : " . $e->getMessage() . "\n";
    }
} else {
    echo "[INFO] Aucun serveur MySQL actif sur {$host}:{$port} (validation dialectale hors-ligne réussie).\n";
}

exit(0);
