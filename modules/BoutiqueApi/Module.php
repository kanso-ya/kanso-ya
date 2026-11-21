<?php

declare(strict_types=1);

namespace Modules\BoutiqueApi;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use Core\Api\Sdk\ModuleInterface;
use Modules\BoutiqueApi\Endpoints\BoutiqueController;
use PDO;

/**
 * Module Boutique Officielle du Club (V2.1 — Lot 6 / Sprint 25).
 * Gère le catalogue avancé : variantes de taille, disponibilité, remises/accès adhérents,
 * flocage personnalisé et lots/packs composés d'articles existants et/ou exclusifs.
 */
final class Module implements ModuleInterface
{
    private PDO $db;

    /**
     * @param PDO|null $db Connexion PDO optionnelle.
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Installation : création idempotente des 4 tables mod_boutique_* (SQLite & MySQL)
     * et seed initial des 3 produits de référence (rétrocompatibilité Sprint 17).
     *
     * @return void
     */
    public function install(): void
    {
        $ddl = <<<SQL
        CREATE TABLE IF NOT EXISTS mod_boutique_products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            stock INTEGER NOT NULL DEFAULT 0,
            category VARCHAR(100) NOT NULL DEFAULT 'Équipement',
            availability VARCHAR(30) NOT NULL DEFAULT 'en_stock',
            member_discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
            member_discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            members_only INTEGER NOT NULL DEFAULT 0,
            flocking_enabled INTEGER NOT NULL DEFAULT 0,
            flocking_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            flocking_max_chars INTEGER NOT NULL DEFAULT 20,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_variants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            product_id INTEGER NOT NULL,
            size_label VARCHAR(40) NOT NULL,
            stock INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY(product_id) REFERENCES mod_boutique_products(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_bundles (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(200) NOT NULL,
            description TEXT NULL,
            price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            member_discount_type VARCHAR(20) NOT NULL DEFAULT 'none',
            member_discount_value DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            members_only INTEGER NOT NULL DEFAULT 0,
            availability VARCHAR(30) NOT NULL DEFAULT 'en_stock',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_bundle_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bundle_id INTEGER NOT NULL,
            product_id INTEGER NULL,
            custom_item_name VARCHAR(200) NULL,
            quantity INTEGER NOT NULL DEFAULT 1,
            FOREIGN KEY(bundle_id) REFERENCES mod_boutique_bundles(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_settings (
            setting_key VARCHAR(100) PRIMARY KEY,
            setting_value TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_number VARCHAR(40) NOT NULL UNIQUE,
            user_id INTEGER NULL,
            buyer_name VARCHAR(200) NOT NULL,
            buyer_email VARCHAR(255) NOT NULL,
            buyer_address TEXT NULL,
            payment_method VARCHAR(40) NOT NULL DEFAULT 'cheque',
            delivery_method VARCHAR(40) NOT NULL DEFAULT 'retrait_dojo',
            subtotal_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            shipping_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            total_amount DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            status VARCHAR(30) NOT NULL DEFAULT 'en_attente_paiement',
            status_history_json TEXT NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS mod_boutique_order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            item_type VARCHAR(20) NOT NULL DEFAULT 'product',
            item_id INTEGER NOT NULL,
            item_label VARCHAR(255) NOT NULL,
            size_label VARCHAR(40) NULL,
            flocking_text VARCHAR(60) NULL,
            flocking_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            quantity INTEGER NOT NULL DEFAULT 1,
            unit_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            line_total DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
            FOREIGN KEY(order_id) REFERENCES mod_boutique_orders(id) ON DELETE CASCADE
        );
SQL;
        Database::execDdlStatements($this->db, $ddl);

        // Migration incrémentale si mod_boutique_products provenait de la v2.0
        $extraCols = [
            'description'           => 'TEXT NULL',
            'availability'          => "VARCHAR(30) NOT NULL DEFAULT 'en_stock'",
            'member_discount_type'  => "VARCHAR(20) NOT NULL DEFAULT 'none'",
            'member_discount_value' => 'DECIMAL(10, 2) NOT NULL DEFAULT 0.00',
            'members_only'          => 'INTEGER NOT NULL DEFAULT 0',
            'flocking_enabled'      => 'INTEGER NOT NULL DEFAULT 0',
            'flocking_price'        => 'DECIMAL(10, 2) NOT NULL DEFAULT 0.00',
            'flocking_max_chars'    => 'INTEGER NOT NULL DEFAULT 20',
        ];
        foreach ($extraCols as $col => $def) {
            if (!Database::tableHasColumn($this->db, 'mod_boutique_products', $col)) {
                $this->db->exec("ALTER TABLE mod_boutique_products ADD COLUMN {$col} {$def}");
            }
        }

        $stmtCount = $this->db->query('SELECT COUNT(*) FROM mod_boutique_products');
        $count = $stmtCount !== false ? (int) $stmtCount->fetchColumn() : 0;

        if ($count === 0) {
            $stmt = $this->db->prepare("
                INSERT INTO mod_boutique_products (
                    name, description, price, stock, category, availability,
                    member_discount_type, member_discount_value, members_only,
                    flocking_enabled, flocking_price, flocking_max_chars
                ) VALUES (
                    :name, :desc, :price, :stock, :cat, :avail,
                    :dtype, :dval, :monly, :flock, :fprice, :fmax
                )
            ");
            $sampleProducts = [
                [
                    'name'   => 'Kimono Club Kanso-ya',
                    'desc'   => 'Kimono coton lourd brodé aux couleurs du dojo.',
                    'price'  => 45.00,
                    'stock'  => 15,
                    'cat'    => 'Tenues',
                    'avail'  => 'en_stock',
                    'dtype'  => 'percent',
                    'dval'   => 10.00,
                    'monly'  => 0,
                    'flock'  => 1,
                    'fprice' => 6.00,
                    'fmax'   => 18,
                ],
                [
                    'name'   => 'Ceinture Noire Broderie Or',
                    'desc'   => 'Ceinture coton épais avec broderie dorée optionnelle.',
                    'price'  => 25.00,
                    'stock'  => 8,
                    'cat'    => 'Grades',
                    'avail'  => 'en_stock',
                    'dtype'  => 'fixed',
                    'dval'   => 3.00,
                    'monly'  => 0,
                    'flock'  => 1,
                    'fprice' => 5.00,
                    'fmax'   => 20,
                ],
                [
                    'name'   => 'Gourde Isotherme',
                    'desc'   => 'Gourde inox 750ml gravée Kanso-ya.',
                    'price'  => 12.00,
                    'stock'  => 30,
                    'cat'    => 'Accessoires',
                    'avail'  => 'en_stock',
                    'dtype'  => 'none',
                    'dval'   => 0.00,
                    'monly'  => 0,
                    'flock'  => 0,
                    'fprice' => 0.00,
                    'fmax'   => 20,
                ],
            ];

            $vStmt = $this->db->prepare('INSERT INTO mod_boutique_variants (product_id, size_label, stock) VALUES (:pid, :size, :stock)');
            foreach ($sampleProducts as $idx => $product) {
                $stmt->execute($product);
                $pid = (int) $this->db->lastInsertId();
                if ($idx === 0) {
                    foreach ([['140cm', 4], ['160cm', 6], ['180cm', 5]] as [$sz, $st]) {
                        $vStmt->execute(['pid' => $pid, 'size' => $sz, 'stock' => $st]);
                    }
                } elseif ($idx === 1) {
                    foreach ([['260cm', 4], ['280cm', 4]] as [$sz, $st]) {
                        $vStmt->execute(['pid' => $pid, 'size' => $sz, 'stock' => $st]);
                    }
                }
            }
        }
    }

    /**
     * Désinstallation : suppression propre de toutes les tables mod_boutique_*.
     *
     * @return void
     */
    public function uninstall(): void
    {
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_order_items');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_orders');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_settings');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_bundle_items');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_bundles');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_variants');
        $this->db->exec('DROP TABLE IF EXISTS mod_boutique_products');
    }

    /**
     * Démarrage : enregistrement des hooks menu, routes UI et endpoints API REST.
     *
     * @param EventManager $em Gestionnaire d'événements SDK.
     * @return void
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
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->listProducts($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/boutique/products',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->createProduct($ctx),
            ];
            $routes[] = [
                'method'  => ['PUT', 'PATCH'],
                'pattern' => '#^/api/v1/modules/boutique/products/(\d+)$#',
                'handler' => static fn (array $ctx, array $m): array => (new BoutiqueController())->updateProduct((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'DELETE',
                'pattern' => '#^/api/v1/modules/boutique/products/(\d+)$#',
                'handler' => static fn (array $ctx, array $m): array => (new BoutiqueController())->deleteProduct((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/boutique/bundles',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->listBundles($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/boutique/bundles',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->createBundle($ctx),
            ];
            $routes[] = [
                'method'  => 'DELETE',
                'pattern' => '#^/api/v1/modules/boutique/bundles/(\d+)$#',
                'handler' => static fn (array $ctx, array $m): array => (new BoutiqueController())->deleteBundle((int) $m[1], $ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/boutique/settings',
                'handler' => static fn (): array => (new BoutiqueController())->getSettings(),
            ];
            $routes[] = [
                'method'  => ['PUT', 'PATCH'],
                'pattern' => '/api/v1/modules/boutique/settings',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->updateSettings($ctx),
            ];
            $routes[] = [
                'method'  => 'GET',
                'pattern' => '/api/v1/modules/boutique/orders',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->listOrders($ctx),
            ];
            $routes[] = [
                'method'  => 'POST',
                'pattern' => '/api/v1/modules/boutique/orders',
                'handler' => static fn (array $ctx): array => (new BoutiqueController())->createOrder($ctx),
            ];
            $routes[] = [
                'method'  => 'PATCH',
                'pattern' => '#^/api/v1/modules/boutique/orders/(\d+)/status$#',
                'handler' => static fn (array $ctx, array $m): array => (new BoutiqueController())->updateOrderStatus((int) $m[1], $ctx),
            ];
            return $routes;
        });
    }
}
