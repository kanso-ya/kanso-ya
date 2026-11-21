<?php

declare(strict_types=1);

namespace Modules\BoutiqueApi\Endpoints;

use Core\Api\Database\Database;
use PDO;
use Throwable;

/**
 * Contrôleur API REST du module Boutique Officielle du Club (US 17.3).
 * Expose les produits stockés dans la table isolée `mod_boutique_products` au format JSend.
 */
final class BoutiqueController
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Retourne la liste des produits de la boutique au format JSend.
     *
     * @return array<string, mixed>
     */
    public function listProducts(): array
    {
        try {
            $checkStmt = $this->db->query(
                "SELECT name FROM sqlite_master WHERE type='table' AND name='mod_boutique_products'"
            );
            $tableExists = $checkStmt !== false && $checkStmt->fetch() !== false;

            if (!$tableExists) {
                return [
                    'status'  => 'error',
                    'code'    => 503,
                    'message' => 'La table mod_boutique_products est introuvable. Veuillez activer le module Boutique.',
                    'data'    => null,
                ];
            }

            $stmt = $this->db->query(
                "SELECT id, name, price, stock, category, created_at FROM mod_boutique_products ORDER BY id ASC"
            );
            $products = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Produits de la boutique récupérés.',
                'data'    => $products,
            ];
        } catch (Throwable $e) {
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la récupération des produits : ' . $e->getMessage(),
                'data'    => null,
            ];
        }
    }
}
