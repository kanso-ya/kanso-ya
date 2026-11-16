<?php

declare(strict_types=1);

namespace Modules\Boutique;

use Core\Api\Database\Database;
use Core\Sdk\EventManager;
use Core\Sdk\ModuleInterface;
use PDO;
use Throwable;

/**
 * Module Démonstrateur Boutique Officielle du Club (US 6.4).
 * Illustre le SDK Kanso-ya : hooks de menu, isolation SQL (mod_boutique_products), cycle de vie.
 */
final class Module implements ModuleInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Installation : création de la table isolée et insertion du stock initial.
     */
    public function install(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS mod_boutique_products (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                price REAL NOT NULL,
                stock INTEGER NOT NULL DEFAULT 0,
                category TEXT DEFAULT 'Équipement',
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Peuplement initial de démonstration si vide
        $count = (int) $this->db->query("SELECT COUNT(*) FROM mod_boutique_products")->fetchColumn();
        if ($count === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO mod_boutique_products (name, price, stock, category)
                VALUES (:name, :price, :stock, :cat)
            ");
            $sampleProducts = [
                ['name' => 'Kimono Club Kanso-ya (Brodé)', 'price' => 45.00, 'stock' => 15, 'cat' => 'Tenues'],
                ['name' => 'Ceinture Noire Broderie Or',   'price' => 25.00, 'stock' => 8,  'cat' => 'Grades'],
                ['name' => 'Gourde Isotherme 750ml Inox',  'price' => 12.00, 'stock' => 30, 'cat' => 'Accessoires']
            ];
            foreach ($sampleProducts as $p) {
                $stmt->execute($p);
            }
        }
    }

    /**
     * Désinstallation : suppression propre des tables mod_*.
     */
    public function uninstall(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS mod_boutique_products");
    }

    /**
     * Démarrage : injection du lien dans la sidebar d'administration via EventManager.
     */
    public function boot(EventManager $events): void
    {
        $events->addFilter('admin_sidebar_menu', function (array $items): array {
            $items[] = [
                'id'         => 'boutique',
                'label'      => 'Boutique Club',
                'url'        => '/boutique.php',
                'icon'       => '🛍️',
                'permission' => 'finances.manage'
            ];
            return $items;
        });

        // Exemple d'action listener : alerte sur validation de dossier
        $events->addAction('on_registration_validated', function (array $payload): void {
            // Un dossier validé pourrait déclencher une notification de bienvenue ou un bon d'achat boutique
        });
    }
}
