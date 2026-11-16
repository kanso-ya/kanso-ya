<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use Core\Sdk\EventManager;
use Core\Sdk\ModuleInterface;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Gestionnaire du cycle de vie et chargeur dynamique des modules (US 6.3).
 * Intègre le mécanisme de Safe Mode automatique garantissant la résilience en cas de panne d'un module.
 */
final class ModuleManager
{
    private PDO $db;
    private string $modulesDir;
    private ModuleScanner $scanner;
    private EventManager $eventManager;

    public function __construct(?PDO $db = null, ?string $modulesDir = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->modulesDir = $modulesDir ?? dirname(__DIR__, 3) . '/modules';
        $this->scanner = new ModuleScanner($this->db, $this->modulesDir);
        $this->eventManager = EventManager::getInstance();
    }

    /**
     * Helper statique pour initialiser les modules actifs au démarrage.
     */
    public static function boot(): void
    {
        (new self())->bootActiveModules();
    }

    /**
     * Scanne les modules et retourne la liste consolidée.
     *
     * @return array<int, array<string, mixed>>
     */
    public function scanAndList(): array
    {
        return $this->scanner->scan();
    }

    /**
     * Active ou désactive un module avec exécution de son cycle de vie (install/boot).
     *
     * @param int|string $idOrFolder Identifiant ou nom du dossier du module.
     * @param bool       $enable     True pour activer, false pour désactiver.
     * @param int|null   $actorId    Auteur de l'action.
     * @param bool       $isSuperadmin
     * @return array<string, mixed> Réponse JSend normalisée.
     */
    public function toggle(int|string $idOrFolder, bool $enable, ?int $actorId = null, bool $isSuperadmin = false): array
    {
        $module = $this->findModule($idOrFolder);
        if (!$module) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Module introuvable.',
                'data'    => null
            ];
        }

        $folder = (string) $module['folder_name'];
        $moduleId = (int) $module['id'];

        if ($enable) {
            // 1. Vérification de compatibilité avant activation
            $manifestPath = $this->modulesDir . '/' . $folder . '/manifest.json';
            if (file_exists($manifestPath)) {
                $manifest = json_decode((string) file_get_contents($manifestPath), true) ?: [];
                $compat = $this->scanner->checkCompatibility($manifest);
                if (!$compat['is_compatible']) {
                    return [
                        'status'  => 'error',
                        'code'    => 422,
                        'message' => 'Activation impossible : ' . ($compat['error'] ?? 'Module incompatible avec cette version du Core.'),
                        'data'    => null
                    ];
                }
            }

            // 2. Instanciation et exécution de install()
            try {
                $instance = $this->loadModuleInstance($folder);
                if ($instance !== null && $module['status'] !== 'active') {
                    $instance->install();
                }

                // Activation en base
                $stmt = $this->db->prepare("
                    UPDATE modules
                    SET is_active = 1, status = 'active', last_error = NULL, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $moduleId]);

                $this->logAction($actorId, $isSuperadmin, 'MODULE_ACTIVATED', "Module « {$module['name']} » ({$folder}) activé avec succès.");

                return [
                    'status'  => 'success',
                    'code'    => 200,
                    'message' => "Module « {$module['name']} » activé avec succès.",
                    'data'    => [
                        'id'        => $moduleId,
                        'folder'    => $folder,
                        'is_active' => true,
                        'status'    => 'active'
                    ]
                ];
            } catch (Throwable $e) {
                // Échec d'activation / installation
                $stmt = $this->db->prepare("
                    UPDATE modules
                    SET is_active = 0, status = 'crashed', last_error = :err, updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                $stmt->execute(['id' => $moduleId, 'err' => $e->getMessage()]);

                $this->logAction($actorId, $isSuperadmin, 'MODULE_CRASH_ON_INSTALL', "Crash lors de l'activation du module {$folder} : " . $e->getMessage());

                return [
                    'status'  => 'error',
                    'code'    => 500,
                    'message' => "Échec lors de l'activation du module : " . $e->getMessage(),
                    'data'    => ['error' => $e->getMessage()]
                ];
            }
        } else {
            // Désactivation
            $stmt = $this->db->prepare("
                UPDATE modules
                SET is_active = 0, status = 'inactive', updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute(['id' => $moduleId]);

            $this->logAction($actorId, $isSuperadmin, 'MODULE_DEACTIVATED', "Module « {$module['name']} » ({$folder}) désactivé.");

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => "Module « {$module['name']} » désactivé.",
                'data'    => [
                    'id'        => $moduleId,
                    'folder'    => $folder,
                    'is_active' => false,
                    'status'    => 'inactive'
                ]
            ];
        }
    }

    /**
     * Désinstalle complètement un module et exécute son script de nettoyage (uninstall).
     *
     * @param int|string $idOrFolder
     * @param int|null   $actorId
     * @param bool       $isSuperadmin
     * @return array<string, mixed>
     */
    public function uninstall(int|string $idOrFolder, ?int $actorId = null, bool $isSuperadmin = false): array
    {
        $module = $this->findModule($idOrFolder);
        if (!$module) {
            $this->scanner->scan();
            $module = $this->findModule($idOrFolder);
        }

        $folder = is_string($idOrFolder) && !is_numeric($idOrFolder) ? $idOrFolder : (string) ($module['folder_name'] ?? '');

        if ($folder === '') {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Module introuvable.',
                'data'    => null
            ];
        }

        try {
            $instance = $this->loadModuleInstance($folder);
            if ($instance !== null) {
                $instance->uninstall();
            }

            if ($module) {
                $stmt = $this->db->prepare("DELETE FROM modules WHERE id = :id");
                $stmt->execute(['id' => (int) $module['id']]);
            }

            $modName = (string) ($module['name'] ?? $folder);
            $this->logAction($actorId, $isSuperadmin, 'MODULE_UNINSTALLED', "Module « {$modName} » ({$folder}) désinstallé et nettoyé.");

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => "Module « {$modName} » désinstallé avec succès (données SQL nettoyées).",
                'data'    => ['folder' => $folder]
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => "Échec de la désinstallation du module : " . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Démarre (boot) tous les modules actifs avec protection SAFE MODE (CA3).
     * Si un module lève une exception, le CMS continue sans interruption
     * et le module défaillant est automatiquement désactivé avec le statut 'crashed'.
     */
    public function bootActiveModules(): void
    {
        try {
            $stmt = $this->db->query("SELECT * FROM modules WHERE is_active = 1");
            $activeModules = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return;
        }

        foreach ($activeModules as $mod) {
            $folder = (string) $mod['folder_name'];
            $modId  = (int) $mod['id'];

            try {
                $instance = $this->loadModuleInstance($folder);
                if ($instance !== null) {
                    $instance->boot($this->eventManager);
                }
            } catch (Throwable $e) {
                // SAFE MODE AUTOMATIQUE : Capture sans planter le CMS
                $errorMsg = sprintf('Crash du module %s : %s dans %s:%d', $folder, $e->getMessage(), basename($e->getFile()), $e->getLine());

                for ($retry = 0; $retry < 5; $retry++) {
                    try {
                        $stmtCrash = $this->db->prepare("
                            UPDATE modules
                            SET is_active = 0, status = 'crashed', last_error = :err, updated_at = CURRENT_TIMESTAMP
                            WHERE id = :id
                        ");
                        $stmtCrash->execute([
                            'id'  => $modId,
                            'err' => $errorMsg
                        ]);

                        $this->logAction(null, true, 'MODULE_SAFE_MODE_TRIGGERED', $errorMsg);
                        break;
                    } catch (Throwable) {
                        usleep(50000);
                    }
                }
            }
        }
    }

    /**
     * Charge la classe d'entrée du module (Module.php).
     */
    public function loadModuleInstance(string $folderName): ?ModuleInterface
    {
        $filePath = $this->modulesDir . '/' . $folderName . '/Module.php';
        if (!file_exists($filePath)) {
            return null;
        }

        require_once $filePath;

        // Conventions de nommage supportées : \Modules\<Folder>\Module ou \<Folder>\Module ou \Module
        $possibleClasses = [
            "\\Modules\\{$folderName}\\Module",
            "\\{$folderName}\\Module",
            "Module"
        ];

        foreach ($possibleClasses as $className) {
            if (class_exists($className)) {
                $instance = new $className();
                if ($instance instanceof ModuleInterface) {
                    return $instance;
                }
            }
        }

        throw new RuntimeException("La classe principale dans {$filePath} doit implémenter \\Core\\Sdk\\ModuleInterface.");
    }

    /**
     * Recherche un module par son ID ou son dossier.
     *
     * @return array<string, mixed>|null
     */
    private function findModule(int|string $idOrFolder): ?array
    {
        if (is_numeric($idOrFolder)) {
            $stmt = $this->db->prepare("SELECT * FROM modules WHERE id = :id");
            $stmt->execute(['id' => (int) $idOrFolder]);
        } else {
            $stmt = $this->db->prepare("SELECT * FROM modules WHERE folder_name = :folder");
            $stmt->execute(['folder' => (string) $idOrFolder]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * Consigne une trace dans sys_logs et optionnellement app_logs.
     */
    private function logAction(?int $actorId, bool $isSuperadmin, string $action, string $details): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (:aid, :act, :det, :ip)
            ");
            $stmt->execute([
                'aid' => $isSuperadmin ? 1 : null,
                'act' => $action,
                'det' => $details,
                'ip'  => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
            ]);

            if ($actorId !== null && !$isSuperadmin) {
                $stmtApp = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, target_id, details)
                    VALUES (:uid, :act, 'module', NULL, :det)
                ");
                $stmtApp->execute([
                    'uid' => $actorId,
                    'act' => $action,
                    'det' => $details
                ]);
            }
        } catch (Throwable) {
        }
    }
}
