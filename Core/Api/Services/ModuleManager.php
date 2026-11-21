<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use FilesystemIterator;
use PDO;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use Throwable;

/**
 * Gestionnaire du cycle de vie et chargeur dynamique des modules (US 6.3 / US 17.1).
 * Intègre le mécanisme de Safe Mode automatique garantissant la résilience en cas de panne d'un module
 * ainsi que la publication/purge sécurisée des assets statiques des modules.
 */
final class ModuleManager
{
    /**
     * Extensions autorisées lors de la publication d'assets statiques (jamais de .php ni de dotfiles).
     *
     * @var array<int, string>
     */
    private const ALLOWED_ASSET_EXTENSIONS = [
        'js',
        'css',
        'map',
        'svg',
        'png',
        'jpg',
        'jpeg',
        'webp',
        'woff',
        'woff2',
    ];

    private static bool $booted = false;
    private PDO $db;
    private string $modulesDir;
    private string $publicAssetsDir;
    private ModuleScanner $scanner;
    private EventManager $eventManager;

    public function __construct(?PDO $db = null, ?string $modulesDir = null, ?string $publicAssetsDir = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->modulesDir = $modulesDir ?? dirname(__DIR__, 3) . '/modules';
        $this->publicAssetsDir = $publicAssetsDir ?? dirname(__DIR__, 3) . '/public/assets/modules';
        $this->scanner = new ModuleScanner($this->db, $this->modulesDir);
        $this->eventManager = EventManager::getInstance();
    }

    /**
     * Helper statique pour initialiser les modules actifs au démarrage (idempotent par requête).
     */
    public static function boot(): void
    {
        if (self::$booted) {
            return;
        }
        self::$booted = true;
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
            $manifest = $this->readManifest($folder);
            if ($manifest !== []) {
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

            // 2. Instanciation, exécution de install() et publication des assets statiques
            try {
                $instance = $this->loadModuleInstance($folder);
                if ($instance !== null && $module['status'] !== 'active') {
                    $instance->install();
                }

                if ($manifest !== []) {
                    $this->publishModuleAssets($manifest);
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
            // Désactivation + purge des assets publiés
            $manifest = $this->readManifest($folder);
            if ($manifest !== []) {
                $this->purgeModuleAssets($manifest);
            }

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

        $folder = (string) ($module['folder_name'] ?? '');
        if ($folder === '' && is_string($idOrFolder) && !is_numeric($idOrFolder)) {
            if (is_dir($this->modulesDir . '/' . $idOrFolder)) {
                $folder = $idOrFolder;
            } elseif (!str_ends_with($idOrFolder, 'Api') && is_dir($this->modulesDir . '/' . $idOrFolder . 'Api')) {
                $folder = $idOrFolder . 'Api';
            } else {
                $folder = $idOrFolder;
            }
        }

        if ($folder === '') {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Module introuvable.',
                'data'    => null
            ];
        }

        try {
            $manifest = $this->readManifest($folder);

            $instance = $this->loadModuleInstance($folder);
            if ($instance !== null) {
                $instance->uninstall();
            }

            if ($manifest !== []) {
                $this->purgeModuleAssets($manifest);
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
     * Charge la classe d'entrée du module via manifest.json (`entry_class` PSR-4)
     * ou par repli sur `modules/{$folder}/Module.php`.
     */
    public function loadModuleInstance(string $folderName): ?ModuleInterface
    {
        // Garantit le chargement des classes SDK et de leurs alias rétrocompatibles (Core\Sdk\*)
        interface_exists(ModuleInterface::class, true);
        class_exists(EventManager::class, true);

        $manifest = $this->readManifest($folderName);
        $entryClass = isset($manifest['entry_class']) && is_string($manifest['entry_class'])
            ? ltrim(trim($manifest['entry_class']), '\\')
            : '';

        // 1. Résolution prioritaire via entry_class et l'autoloader PSR-4
        if ($entryClass !== '' && class_exists($entryClass)) {
            $instance = new $entryClass();
            if ($instance instanceof ModuleInterface) {
                return $instance;
            }
            throw new RuntimeException("La classe {$entryClass} doit implémenter " . ModuleInterface::class . '.');
        }

        // 2. Fallback sur modules/{$folderName}/Module.php
        $filePath = $this->modulesDir . '/' . $folderName . '/Module.php';
        if (!file_exists($filePath)) {
            return null;
        }

        require_once $filePath;

        $possibleClasses = [];
        if ($entryClass !== '') {
            $possibleClasses[] = $entryClass;
        }
        $possibleClasses[] = "Modules\\{$folderName}\\Module";
        $possibleClasses[] = "{$folderName}\\Module";
        $possibleClasses[] = 'Module';

        foreach ($possibleClasses as $className) {
            if (class_exists($className)) {
                $instance = new $className();
                if ($instance instanceof ModuleInterface) {
                    return $instance;
                }
            }
        }

        throw new RuntimeException("La classe principale dans {$filePath} doit implémenter " . ModuleInterface::class . '.');
    }

    /**
     * Publie les assets statiques autorisés d'un module depuis modules/{publish_dir}
     * vers public/assets/modules/{public_slug}/.
     * Seules les extensions statiques autorisées sont copiées (jamais .php ni dotfiles).
     *
     * @param array<string, mixed> $manifest
     */
    public function publishModuleAssets(array $manifest): void
    {
        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets)) {
            return;
        }

        $publishDir = isset($assets['publish_dir']) && is_string($assets['publish_dir'])
            ? trim($assets['publish_dir'], "/\\")
            : '';
        $publicSlug = isset($assets['public_slug']) && is_string($assets['public_slug'])
            ? trim($assets['public_slug'])
            : '';

        if ($publishDir === '' || $publicSlug === '') {
            return;
        }

        // Validation anti-path-traversal stricte sur public_slug
        if (!preg_match('/^[a-z0-9_-]+$/', $publicSlug)) {
            throw new RuntimeException("Slug d'assets module invalide : {$publicSlug}");
        }

        $modulesRoot = realpath($this->modulesDir);
        $sourceDir = realpath($this->modulesDir . '/' . $publishDir);
        if ($modulesRoot === false || $sourceDir === false || !is_dir($sourceDir)) {
            return;
        }

        // Vérification que sourceDir réside strictement sous /modules/
        $normalizedRoot = rtrim(str_replace('\\', '/', $modulesRoot), '/') . '/';
        $normalizedSource = rtrim(str_replace('\\', '/', $sourceDir), '/') . '/';
        if (!str_starts_with($normalizedSource, $normalizedRoot)) {
            throw new RuntimeException("Chemin publish_dir hors du répertoire modules/ interdit : {$publishDir}");
        }

        $targetDir = $this->publicAssetsDir . '/' . $publicSlug;
        if (is_dir($targetDir)) {
            $this->removeDirectoryRecursive($targetDir);
        }

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            throw new RuntimeException("Impossible de créer le dossier d'assets publics : {$targetDir}");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $sourcePrefixLen = strlen($sourceDir);

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isLink() || !$item->isFile()) {
                continue;
            }

            $relativePath = ltrim(str_replace('\\', '/', substr($item->getPathname(), $sourcePrefixLen)), '/');
            if ($relativePath === '') {
                continue;
            }

            // Rejeter tout dotfile ou fichier dans un dossier caché (.git, .htaccess, .env, etc.)
            $segments = explode('/', $relativePath);
            $hasDotSegment = false;
            foreach ($segments as $segment) {
                if ($segment === '' || str_starts_with($segment, '.')) {
                    $hasDotSegment = true;
                    break;
                }
            }
            if ($hasDotSegment) {
                continue;
            }

            // N'autoriser strictement que les extensions statiques blanches (jamais .php)
            $extension = strtolower(pathinfo($item->getFilename(), PATHINFO_EXTENSION));
            if ($extension === '' || !in_array($extension, self::ALLOWED_ASSET_EXTENSIONS, true)) {
                continue;
            }

            $destPath = $targetDir . '/' . $relativePath;
            $destParent = dirname($destPath);
            if (!is_dir($destParent) && !mkdir($destParent, 0755, true) && !is_dir($destParent)) {
                throw new RuntimeException("Impossible de créer le sous-dossier d'assets : {$destParent}");
            }

            copy($item->getPathname(), $destPath);
        }
    }

    /**
     * Supprime récursivement le dossier public/assets/modules/{public_slug}/ s'il existe.
     *
     * @param array<string, mixed> $manifest
     */
    public function purgeModuleAssets(array $manifest): void
    {
        $assets = $manifest['assets'] ?? null;
        if (!is_array($assets)) {
            return;
        }

        $publicSlug = isset($assets['public_slug']) && is_string($assets['public_slug'])
            ? trim($assets['public_slug'])
            : '';

        if ($publicSlug === '' || !preg_match('/^[a-z0-9_-]+$/', $publicSlug)) {
            return;
        }

        $targetDir = $this->publicAssetsDir . '/' . $publicSlug;
        if (is_dir($targetDir)) {
            $this->removeDirectoryRecursive($targetDir);
        }
    }

    /**
     * Lit et décode le manifest.json d'un module.
     *
     * @return array<string, mixed>
     */
    private function readManifest(string $folderName): array
    {
        $manifestPath = $this->modulesDir . '/' . $folderName . '/manifest.json';
        if (!file_exists($manifestPath)) {
            return [];
        }

        $raw = file_get_contents($manifestPath);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Supprime récursivement un répertoire et tout son contenu.
     */
    private function removeDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Recherche un module par son ID, son folder_name exact (ex. BoutiqueApi)
     * ou son nom court sans suffixe Api (ex. Boutique -> BoutiqueApi).
     *
     * @return array<string, mixed>|null
     */
    private function findModule(int|string $idOrFolder): ?array
    {
        if (is_numeric($idOrFolder)) {
            $stmt = $this->db->prepare("SELECT * FROM modules WHERE id = :id");
            $stmt->execute(['id' => (int) $idOrFolder]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        }

        $folder = (string) $idOrFolder;
        $stmt = $this->db->prepare("SELECT * FROM modules WHERE folder_name = :folder");
        $stmt->execute(['folder' => $folder]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return $row;
        }

        if (!str_ends_with($folder, 'Api')) {
            $stmt->execute(['folder' => $folder . 'Api']);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return $row;
            }
        }

        return null;
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
