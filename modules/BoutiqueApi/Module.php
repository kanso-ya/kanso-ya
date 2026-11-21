<?php

declare(strict_types=1);

namespace Modules\BoutiqueApi;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Modules\BoutiqueApi\Endpoints\BoutiqueController;
use PDO;

/**
 * Module Démonstrateur Boutique Officielle du Club (Architecture V2 - US 17.3).
 * Illustre le SDK Kanso-ya : hooks de menu, routes UI/API, isolation SQL (mod_boutique_products) et assets publiés.
 */
final class Module implements ModuleInterface
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Installation : création de la table isolée et insertion du stock initial de démo.
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

        $stmtCount = $this->db->query("SELECT COUNT(*) FROM mod_boutique_products");
        $count = $stmtCount !== false ? (int) $stmtCount->fetchColumn() : 0;

        if ($count === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO mod_boutique_products (name, price, stock, category)
                VALUES (:name, :price, :stock, :cat)
            ");
            $sampleProducts = [
                ['name' => 'Kimono Club Kanso-ya',      'price' => 45.00, 'stock' => 15, 'cat' => 'Tenues'],
                ['name' => 'Ceinture Noire Broderie Or', 'price' => 25.00, 'stock' => 8,  'cat' => 'Grades'],
                ['name' => 'Gourde Isotherme',           'price' => 12.00, 'stock' => 30, 'cat' => 'Accessoires'],
            ];
            foreach ($sampleProducts as $product) {
                $stmt->execute($product);
            }
        }
    }

    /**
     * Désinstallation : suppression propre des tables mod_boutique_*.
     */
    public function uninstall(): void
    {
        $this->db->exec("DROP TABLE IF EXISTS mod_boutique_products");
    }

    /**
     * Démarrage : enregistrement des hooks menu, routes UI et endpoints API REST.
     */
    public function boot(EventManager $em): void
    {
        $em->addFilter('admin_sidebar_menu', static function (array $items): array {
            $items[] = [
                'id'         => 'boutique',
                'label'      => 'Boutique Club',
                'url'        => '/boutique',
                'icon'       => '🛍️',
                'permission' => 'finances.manage',
            ];
            return $items;
        });

        $em->addFilter('ui_routes', static function (array $routes): array {
            $pagePath = dirname(__DIR__) . '/BoutiqueUi/Pages/boutique.php';
            $routes['/boutique'] = ['page' => $pagePath];
            $routes['/boutique.php'] = ['page' => $pagePath];
            return $routes;
        });

        $em->addFilter('api_routes', static function (array $routes): array {
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/boutique/products',
                'handler' => static fn (): array => (new BoutiqueController())->listProducts(),
            ];
            return $routes;
        });

        $em->addAction('on_registration_validated', static function (array $payload): void {
            // Hook d'extension disponible pour déclencher un bon d'achat ou une notification boutique
        });
    }
}
