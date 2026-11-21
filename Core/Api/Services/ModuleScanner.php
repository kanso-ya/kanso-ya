<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Service de découverte et de validation des modules externes (/modules/ - US 6.1).
 * Parcourt les répertoires, valide la conformité du manifest.json et synchronise la table modules.
 */
final class ModuleScanner
{
    public const CURRENT_CORE_VERSION = '1.5.0';

    private PDO $db;
    private string $modulesDir;

    public function __construct(?PDO $db = null, ?string $modulesDir = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->modulesDir = $modulesDir ?? dirname(__DIR__, 3) . '/modules';
    }

    /**
     * Scanne tous les dossiers dans /modules/ et met à jour la table modules.
     *
     * @return array<int, array<string, mixed>> Liste des modules avec leur état et compatibilité.
     */
    public function scan(): array
    {
        if (!is_dir($this->modulesDir)) {
            mkdir($this->modulesDir, 0755, true);
        }

        $entries = scandir($this->modulesDir) ?: [];
        $scanned = [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || !is_dir($this->modulesDir . '/' . $entry)) {
                continue;
            }

            $folderPath = $this->modulesDir . '/' . $entry;
            $manifestPath = $folderPath . '/manifest.json';

            // Si manifest.json est absent, ignorer avec avertissement dans sys_logs (CA2)
            if (!file_exists($manifestPath)) {
                $this->logWarning("Manifeste absent dans le dossier : {$entry}");
                continue;
            }

            $raw = file_get_contents($manifestPath);
            $manifest = is_string($raw) ? json_decode($raw, true) : null;

            // Validation de la syntaxe JSON et des champs obligatoires
            if (!is_array($manifest) || empty($manifest['id']) || empty($manifest['name']) || empty($manifest['version'])) {
                $this->logWarning("Manifeste invalide ou incomplet dans : {$entry}");
                continue;
            }

            // Vérification de compatibilité (CA3)
            $compatibility = $this->checkCompatibility($manifest);

            // Synchronisation en base de données
            $moduleRecord = $this->syncWithDatabase($entry, $manifest, $compatibility['is_compatible']);

            $scanned[] = [
                'id'                   => (int) ($moduleRecord['id'] ?? 0),
                'folder_name'          => $entry,
                'manifest_id'          => (string) $manifest['id'],
                'name'                 => (string) $manifest['name'],
                'version'              => (string) $manifest['version'],
                'author'               => (string) ($manifest['author'] ?? 'Inconnu'),
                'description'          => (string) ($manifest['description'] ?? ''),
                'permissions_required' => (array) ($manifest['permissions_required'] ?? []),
                'is_compatible'        => $compatibility['is_compatible'],
                'compatibility_error'  => $compatibility['error'],
                'is_active'            => (bool) ($moduleRecord['is_active'] ?? 0),
                'status'               => (string) ($moduleRecord['status'] ?? ($compatibility['is_compatible'] ? 'inactive' : 'incompatible')),
                'last_error'           => $moduleRecord['last_error'] ?? null,
                'updated_at'           => (string) ($moduleRecord['updated_at'] ?? date('Y-m-d H:i:s'))
            ];
        }

        return $scanned;
    }

    /**
     * Valide l'adéquation de version du Core et de PHP avec le manifeste.
     *
     * @param array<string, mixed> $manifest Données du manifeste.
     * @return array{is_compatible: bool, error: ?string}
     */
    public function checkCompatibility(array $manifest): array
    {
        $comp = (array) ($manifest['compatibility'] ?? []);
        $coreMin = (string) ($comp['core_min_version'] ?? '1.0.0');
        $coreMax = (string) ($comp['core_max_version'] ?? '9.9.9');
        $phpMin  = (string) ($comp['php_min_version'] ?? '8.1.0');

        if (version_compare(self::CURRENT_CORE_VERSION, $coreMin, '<')) {
            return [
                'is_compatible' => false,
                'error'         => sprintf('Incompatible : Core version %s requise (actuelle : %s)', $coreMin, self::CURRENT_CORE_VERSION)
            ];
        }

        if (version_compare(self::CURRENT_CORE_VERSION, $coreMax, '>')) {
            return [
                'is_compatible' => false,
                'error'         => sprintf('Incompatible : Core version max %s dépassée (actuelle : %s)', $coreMax, self::CURRENT_CORE_VERSION)
            ];
        }

        if (version_compare(PHP_VERSION, $phpMin, '<')) {
            return [
                'is_compatible' => false,
                'error'         => sprintf('Incompatible : PHP version %s requise (actuelle : %s)', $phpMin, PHP_VERSION)
            ];
        }

        return [
            'is_compatible' => true,
            'error'         => null
        ];
    }

    /**
     * Synchronise le module détecté avec la table `modules`.
     *
     * @param string               $folderName Nom du sous-dossier.
     * @param array<string, mixed> $manifest   Contenu validé du manifeste.
     * @param bool                 $isCompatible Indique si le module est compatible.
     * @return array<string, mixed> Enregistrement de la base de données.
     */
    private function syncWithDatabase(string $folderName, array $manifest, bool $isCompatible): array
    {
        $row = null;
        for ($r = 0; $r < 5; $r++) {
            try {
                $stmt = $this->db->prepare("SELECT * FROM modules WHERE folder_name = :folder");
                $stmt->execute(['folder' => $folderName]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                break;
            } catch (Throwable) {
                usleep(50000);
            }
        }

        if ($row) {
            // Mise à jour des métadonnées uniquement si changement avéré
            $newStatus = (string) $row['status'];
            if (!$isCompatible) {
                $newStatus = 'incompatible';
            } elseif ($newStatus === 'incompatible') {
                $newStatus = 'inactive';
            }

            $hasChanged = ($row['name'] !== $manifest['name']
                || $row['version'] !== $manifest['version']
                || $row['status'] !== $newStatus);

            if ($hasChanged) {
                for ($retry = 0; $retry < 5; $retry++) {
                    try {
                        $stmtUpd = $this->db->prepare("
                            UPDATE modules
                            SET name = :name, version = :ver, status = :st, updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $stmtUpd->execute([
                            'name' => (string) $manifest['name'],
                            'ver'  => (string) $manifest['version'],
                            'st'   => $newStatus,
                            'id'   => $row['id']
                        ]);
                        break;
                    } catch (Throwable) {
                        usleep(50000);
                    }
                }
            }

            $row['name'] = $manifest['name'];
            $row['version'] = $manifest['version'];
            $row['status'] = $newStatus;
            return $row;
        }

        // Insertion du nouveau module découvert
        $initialStatus = $isCompatible ? 'inactive' : 'incompatible';
        $newId = 0;
        for ($retry = 0; $retry < 5; $retry++) {
            try {
                $stmtIns = $this->db->prepare("
                    INSERT INTO modules (folder_name, name, version, is_active, status)
                    VALUES (:folder, :name, :ver, 0, :st)
                ");
                $stmtIns->execute([
                    'folder' => $folderName,
                    'name'   => (string) $manifest['name'],
                    'ver'    => (string) $manifest['version'],
                    'st'     => $initialStatus
                ]);
                $newId = (int) $this->db->lastInsertId();
                break;
            } catch (Throwable) {
                usleep(50000);
            }
        }

        return [
            'id'          => $newId,
            'folder_name' => $folderName,
            'name'        => $manifest['name'],
            'version'     => $manifest['version'],
            'is_active'   => 0,
            'status'      => $initialStatus,
            'last_error'  => null,
            'updated_at'  => date('Y-m-d H:i:s')
        ];
    }

    /**
     * Trace un avertissement dans sys_logs si manifest.json est absent ou invalide.
     */
    private function logWarning(string $details): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (NULL, 'MODULE_SCANNER_WARNING', :det, :ip)
            ");
            $stmt->execute([
                'det' => $details,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);
        } catch (Throwable) {
        }
    }
}
