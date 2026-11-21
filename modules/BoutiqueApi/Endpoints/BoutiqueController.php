<?php

declare(strict_types=1);

namespace Modules\BoutiqueApi\Endpoints;

use Core\Api\Database\Database;
use Core\Api\Sdk\EventManager;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Contrôleur API REST du module Boutique Officielle du Club (Lot 6 — Sprints 25 & 26).
 * Expose le catalogue étendu (produits, variantes de taille, disponibilité, remises/accès adhérents,
 * flocage personnalisé et lots/packs), les paramétrages paiement hors-ligne & livraison,
 * le tunnel de commande avec suivi et la liaison automatique Facturation PDF.
 */
final class BoutiqueController
{
    private const ALLOWED_AVAILABILITY = ['en_stock', 'sur_commande', 'rupture', 'masque'];
    private const ALLOWED_DISCOUNT_TYPES = ['none', 'percent', 'fixed'];
    private const ALLOWED_OFFLINE_PAYMENTS = ['cheque', 'especes', 'virement'];
    private const ALLOWED_DELIVERY_METHODS = ['retrait_dojo', 'remise_main_propre', 'livraison_postale'];
    private const ALLOWED_ORDER_STATUSES = [
        'en_attente_paiement',
        'payee',
        'en_preparation',
        'prete_ou_expediee',
        'livree',
        'annulee',
    ];

    private PDO $db;

    /**
     * @param PDO|null $db Connexion PDO optionnelle.
     */
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::getInstance();
    }

    /**
     * Indique si l'utilisateur courant peut administrer le catalogue (finances.manage ou Superadmin).
     *
     * @param array<string, mixed> $ctx Contexte transmis par ApiRouter.
     * @return bool
     */
    public function canManageShop(array $ctx): bool
    {
        if (!empty($ctx['is_superadmin'])) {
            return true;
        }
        if (empty($ctx['is_authenticated'])) {
            return false;
        }
        $perms = is_array($ctx['permissions'] ?? null) ? $ctx['permissions'] : [];
        return in_array('finances.manage', $perms, true);
    }

    /**
     * Calcule le tarif préférentiel adhérent selon le type de remise.
     *
     * @param float  $publicPrice   Prix public.
     * @param string $discountType  'none' | 'percent' | 'fixed'.
     * @param float  $discountValue Valeur de remise.
     * @return float Prix adhérent arrondi (plancher 0.00).
     */
    public static function computeMemberPrice(float $publicPrice, string $discountType, float $discountValue): float
    {
        if ($discountType === 'percent' && $discountValue > 0) {
            return max(0.0, round($publicPrice * (1 - min(100.0, $discountValue) / 100.0), 2));
        }
        if ($discountType === 'fixed' && $discountValue > 0) {
            return max(0.0, round($publicPrice - $discountValue, 2));
        }
        return round($publicPrice, 2);
    }

    /**
     * Retourne la liste des produits visibles (tableau JSend rétrocompatible Sprint 17).
     * Filtre automatiquement les produits `masque` (hors gestionnaires) et `members_only` (hors connectés).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function listProducts(array $ctx = []): array
    {
        try {
            $canManage = $this->canManageShop($ctx);
            $isAuth = !empty($ctx['is_authenticated']) || !empty($ctx['is_superadmin']);

            $conditions = [];
            if (!$canManage) {
                $conditions[] = "availability != 'masque'";
            }
            if (!$isAuth) {
                $conditions[] = 'members_only = 0';
            }
            $where = empty($conditions) ? '1 = 1' : implode(' AND ', $conditions);

            $stmt = $this->db->query("SELECT * FROM mod_boutique_products WHERE {$where} ORDER BY id ASC");
            $products = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

            $vStmt = $this->db->query('SELECT id, product_id, size_label, stock FROM mod_boutique_variants ORDER BY id ASC');
            $allVariants = $vStmt !== false ? $vStmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $variantsByProduct = [];
            foreach ($allVariants as $v) {
                $variantsByProduct[(int) $v['product_id']][] = [
                    'id'         => (int) $v['id'],
                    'size_label' => (string) $v['size_label'],
                    'stock'      => (int) $v['stock'],
                ];
            }

            $enriched = [];
            foreach ($products as $p) {
                $pid = (int) $p['id'];
                $price = round((float) $p['price'], 2);
                $dType = (string) ($p['member_discount_type'] ?? 'none');
                $dVal = round((float) ($p['member_discount_value'] ?? 0.0), 2);
                $memberPrice = self::computeMemberPrice($price, $dType, $dVal);
                $effectivePrice = $isAuth ? $memberPrice : $price;

                $enriched[] = [
                    'id'                    => $pid,
                    'name'                  => (string) $p['name'],
                    'description'           => (string) ($p['description'] ?? ''),
                    'price'                 => $price,
                    'member_price'          => $memberPrice,
                    'effective_price'       => $effectivePrice,
                    'stock'                 => (int) $p['stock'],
                    'category'              => (string) ($p['category'] ?? 'Équipement'),
                    'availability'          => (string) ($p['availability'] ?? 'en_stock'),
                    'member_discount_type'  => $dType,
                    'member_discount_value' => $dVal,
                    'members_only'          => (int) ($p['members_only'] ?? 0) === 1,
                    'flocking_enabled'      => (int) ($p['flocking_enabled'] ?? 0) === 1,
                    'flocking_price'        => round((float) ($p['flocking_price'] ?? 0.0), 2),
                    'flocking_max_chars'    => (int) ($p['flocking_max_chars'] ?? 20),
                    'variants'              => $variantsByProduct[$pid] ?? [],
                    'created_at'            => (string) ($p['created_at'] ?? ''),
                ];
            }

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Produits de la boutique récupérés.',
                'data'    => $enriched,
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

    /**
     * Crée un nouveau produit avec variantes de taille, remise adhérent et flocage (gestionnaires).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function createProduct(array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $validated = $this->validateProductPayload($payload);
        if (isset($validated['error'])) {
            return ['status' => 'error', 'code' => 422, 'message' => (string) $validated['error'], 'data' => null];
        }

        try {
            $newId = Database::transaction(function () use ($validated): int {
                $variants = $validated['variants'];
                $totalStock = !empty($variants)
                    ? array_sum(array_map(static fn (array $v): int => (int) $v['stock'], $variants))
                    : (int) $validated['stock'];

                $ins = $this->db->prepare("
                    INSERT INTO mod_boutique_products (
                        name, description, price, stock, category, availability,
                        member_discount_type, member_discount_value, members_only,
                        flocking_enabled, flocking_price, flocking_max_chars
                    ) VALUES (
                        :name, :desc, :price, :stock, :cat, :avail,
                        :dtype, :dval, :monly, :flock, :fprice, :fmax
                    )
                ");
                $ins->execute([
                    'name'   => $validated['name'],
                    'desc'   => $validated['description'],
                    'price'  => $validated['price'],
                    'stock'  => $totalStock,
                    'cat'    => $validated['category'],
                    'avail'  => $validated['availability'],
                    'dtype'  => $validated['member_discount_type'],
                    'dval'   => $validated['member_discount_value'],
                    'monly'  => $validated['members_only'],
                    'flock'  => $validated['flocking_enabled'],
                    'fprice' => $validated['flocking_price'],
                    'fmax'   => $validated['flocking_max_chars'],
                ]);
                $pid = (int) $this->db->lastInsertId();

                if (!empty($variants)) {
                    $vIns = $this->db->prepare('INSERT INTO mod_boutique_variants (product_id, size_label, stock) VALUES (:pid, :sz, :st)');
                    foreach ($variants as $v) {
                        $vIns->execute(['pid' => $pid, 'sz' => $v['size_label'], 'st' => $v['stock']]);
                    }
                }

                return $pid;
            });

            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => 'Produit ajouté au catalogue avec succès.',
                'data'    => ['id' => $newId],
            ];
        } catch (Throwable $e) {
            return ['status' => 'error', 'code' => 500, 'message' => 'Erreur lors de la création du produit : ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Met à jour un produit existant et ses variantes (gestionnaires).
     *
     * @param int                  $productId Identifiant produit.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed>
     */
    public function updateProduct(int $productId, array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $sel = $this->db->prepare('SELECT * FROM mod_boutique_products WHERE id = :id LIMIT 1');
        $sel->execute(['id' => $productId]);
        $existing = $sel->fetch(PDO::FETCH_ASSOC);
        if ($existing === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Produit introuvable.', 'data' => null];
        }

        $payload = array_merge($existing, is_array($ctx['payload'] ?? null) ? $ctx['payload'] : []);
        $validated = $this->validateProductPayload($payload);
        if (isset($validated['error'])) {
            return ['status' => 'error', 'code' => 422, 'message' => (string) $validated['error'], 'data' => null];
        }

        $hasVariantsKey = is_array($ctx['payload'] ?? null) && array_key_exists('variants', $ctx['payload']);

        Database::transaction(function () use ($productId, $validated, $hasVariantsKey): void {
            $variants = $validated['variants'];
            $totalStock = ($hasVariantsKey && !empty($variants))
                ? array_sum(array_map(static fn (array $v): int => (int) $v['stock'], $variants))
                : (int) $validated['stock'];

            $upd = $this->db->prepare("
                UPDATE mod_boutique_products
                SET name = :name, description = :desc, price = :price, stock = :stock,
                    category = :cat, availability = :avail,
                    member_discount_type = :dtype, member_discount_value = :dval,
                    members_only = :monly, flocking_enabled = :flock,
                    flocking_price = :fprice, flocking_max_chars = :fmax
                WHERE id = :id
            ");
            $upd->execute([
                'name'   => $validated['name'],
                'desc'   => $validated['description'],
                'price'  => $validated['price'],
                'stock'  => $totalStock,
                'cat'    => $validated['category'],
                'avail'  => $validated['availability'],
                'dtype'  => $validated['member_discount_type'],
                'dval'   => $validated['member_discount_value'],
                'monly'  => $validated['members_only'],
                'flock'  => $validated['flocking_enabled'],
                'fprice' => $validated['flocking_price'],
                'fmax'   => $validated['flocking_max_chars'],
                'id'     => $productId,
            ]);

            if ($hasVariantsKey) {
                $this->db->prepare('DELETE FROM mod_boutique_variants WHERE product_id = :pid')->execute(['pid' => $productId]);
                $vIns = $this->db->prepare('INSERT INTO mod_boutique_variants (product_id, size_label, stock) VALUES (:pid, :sz, :st)');
                foreach ($variants as $v) {
                    $vIns->execute(['pid' => $productId, 'sz' => $v['size_label'], 'st' => $v['stock']]);
                }
            }
        });

        return ['status' => 'success', 'code' => 200, 'message' => 'Produit mis à jour.', 'data' => ['id' => $productId]];
    }

    /**
     * Supprime un produit du catalogue (gestionnaires).
     *
     * @param int                  $productId Identifiant produit.
     * @param array<string, mixed> $ctx       Contexte de requête.
     * @return array<string, mixed>
     */
    public function deleteProduct(int $productId, array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $del = $this->db->prepare('DELETE FROM mod_boutique_products WHERE id = :id');
        $del->execute(['id' => $productId]);
        if ($del->rowCount() === 0) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Produit introuvable.', 'data' => null];
        }

        return ['status' => 'success', 'code' => 200, 'message' => 'Produit supprimé.', 'data' => ['id' => $productId]];
    }

    /**
     * Liste les lots/packs visibles avec leurs composants résolus (articles existants et/ou exclusifs).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function listBundles(array $ctx = []): array
    {
        $canManage = $this->canManageShop($ctx);
        $isAuth = !empty($ctx['is_authenticated']) || !empty($ctx['is_superadmin']);

        $conditions = [];
        if (!$canManage) {
            $conditions[] = "availability != 'masque'";
        }
        if (!$isAuth) {
            $conditions[] = 'members_only = 0';
        }
        $where = empty($conditions) ? '1 = 1' : implode(' AND ', $conditions);

        $bStmt = $this->db->query("SELECT * FROM mod_boutique_bundles WHERE {$where} ORDER BY id DESC");
        $bundles = $bStmt !== false ? $bStmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $iStmt = $this->db->query("
            SELECT bi.id, bi.bundle_id, bi.product_id, bi.custom_item_name, bi.quantity,
                   p.name AS linked_product_name
            FROM mod_boutique_bundle_items bi
            LEFT JOIN mod_boutique_products p ON p.id = bi.product_id
            ORDER BY bi.id ASC
        ");
        $allItems = $iStmt !== false ? $iStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $itemsByBundle = [];
        foreach ($allItems as $it) {
            $bid = (int) $it['bundle_id'];
            $isExclusive = empty($it['product_id']);
            $itemsByBundle[$bid][] = [
                'id'           => (int) $it['id'],
                'product_id'   => !empty($it['product_id']) ? (int) $it['product_id'] : null,
                'label'        => $isExclusive
                    ? (string) ($it['custom_item_name'] ?? 'Article exclusif')
                    : (string) ($it['linked_product_name'] ?? 'Article catalogue'),
                'is_exclusive' => $isExclusive,
                'quantity'     => (int) ($it['quantity'] ?? 1),
            ];
        }

        $result = [];
        foreach ($bundles as $b) {
            $bid = (int) $b['id'];
            $price = round((float) $b['price'], 2);
            $dType = (string) ($b['member_discount_type'] ?? 'none');
            $dVal = round((float) ($b['member_discount_value'] ?? 0.0), 2);
            $memberPrice = self::computeMemberPrice($price, $dType, $dVal);

            $result[] = [
                'id'                    => $bid,
                'name'                  => (string) $b['name'],
                'description'           => (string) ($b['description'] ?? ''),
                'price'                 => $price,
                'member_price'          => $memberPrice,
                'effective_price'       => $isAuth ? $memberPrice : $price,
                'member_discount_type'  => $dType,
                'member_discount_value' => $dVal,
                'members_only'          => (int) ($b['members_only'] ?? 0) === 1,
                'availability'          => (string) ($b['availability'] ?? 'en_stock'),
                'items'                 => $itemsByBundle[$bid] ?? [],
            ];
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Lots de la boutique récupérés.',
            'data'    => $result,
        ];
    }

    /**
     * Crée un lot/pack composé d'articles existants et/ou exclusifs au lot (gestionnaires).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function createBundle(array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $name = trim((string) ($payload['name'] ?? ''));
        $desc = trim((string) ($payload['description'] ?? ''));
        $price = (float) ($payload['price'] ?? -1);
        $dType = strtolower(trim((string) ($payload['member_discount_type'] ?? 'none')));
        $dVal = max(0.0, (float) ($payload['member_discount_value'] ?? 0.0));
        $membersOnly = !empty($payload['members_only']) ? 1 : 0;
        $availability = strtolower(trim((string) ($payload['availability'] ?? 'en_stock')));
        $rawItems = $payload['items'] ?? [];

        if ($name === '' || $price < 0) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Nom du lot et prix positif obligatoires.', 'data' => null];
        }
        if (!in_array($dType, self::ALLOWED_DISCOUNT_TYPES, true) || !in_array($availability, self::ALLOWED_AVAILABILITY, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Type de remise ou disponibilité invalide.', 'data' => null];
        }
        if (!is_array($rawItems) || empty($rawItems)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Un lot doit contenir au moins un article (existant ou exclusif).', 'data' => null];
        }

        $items = [];
        foreach ($rawItems as $it) {
            if (!is_array($it)) {
                continue;
            }
            $pid = !empty($it['product_id']) ? (int) $it['product_id'] : null;
            $custom = trim((string) ($it['custom_item_name'] ?? ''));
            $qty = max(1, (int) ($it['quantity'] ?? 1));
            if ($pid === null && $custom === '') {
                continue;
            }
            $items[] = [
                'product_id'       => $pid,
                'custom_item_name' => $custom !== '' ? htmlspecialchars($custom, ENT_QUOTES, 'UTF-8') : null,
                'quantity'         => $qty,
            ];
        }

        if (empty($items)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Aucun composant valide fourni pour ce lot.', 'data' => null];
        }

        $bundleId = Database::transaction(function () use ($name, $desc, $price, $dType, $dVal, $membersOnly, $availability, $items): int {
            $ins = $this->db->prepare("
                INSERT INTO mod_boutique_bundles (name, description, price, member_discount_type, member_discount_value, members_only, availability)
                VALUES (:name, :desc, :price, :dtype, :dval, :monly, :avail)
            ");
            $ins->execute([
                'name'  => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
                'desc'  => $desc !== '' ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') : null,
                'price' => round($price, 2),
                'dtype' => $dType,
                'dval'  => round($dVal, 2),
                'monly' => $membersOnly,
                'avail' => $availability,
            ]);
            $bid = (int) $this->db->lastInsertId();

            $iIns = $this->db->prepare('INSERT INTO mod_boutique_bundle_items (bundle_id, product_id, custom_item_name, quantity) VALUES (:bid, :pid, :cname, :qty)');
            foreach ($items as $it) {
                $iIns->execute([
                    'bid'   => $bid,
                    'pid'   => $it['product_id'],
                    'cname' => $it['custom_item_name'],
                    'qty'   => $it['quantity'],
                ]);
            }

            return $bid;
        });

        return [
            'status'  => 'success',
            'code'    => 201,
            'message' => 'Lot créé avec succès.',
            'data'    => ['id' => $bundleId],
        ];
    }

    /**
     * Supprime un lot/pack (gestionnaires).
     *
     * @param int                  $bundleId Identifiant du lot.
     * @param array<string, mixed> $ctx      Contexte de requête.
     * @return array<string, mixed>
     */
    public function deleteBundle(int $bundleId, array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $del = $this->db->prepare('DELETE FROM mod_boutique_bundles WHERE id = :id');
        $del->execute(['id' => $bundleId]);
        if ($del->rowCount() === 0) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Lot introuvable.', 'data' => null];
        }

        return ['status' => 'success', 'code' => 200, 'message' => 'Lot supprimé.', 'data' => ['id' => $bundleId]];
    }

    /**
     * Valide et normalise le payload de création/mise à jour d'un produit.
     *
     * @param array<string, mixed> $payload Données brutes.
     * @return array<string, mixed>
     */
    private function validateProductPayload(array $payload): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $desc = trim((string) ($payload['description'] ?? ''));
        $price = (float) ($payload['price'] ?? -1);
        $stock = max(0, (int) ($payload['stock'] ?? 0));
        $category = trim((string) ($payload['category'] ?? 'Équipement'));
        $availability = strtolower(trim((string) ($payload['availability'] ?? 'en_stock')));
        $dType = strtolower(trim((string) ($payload['member_discount_type'] ?? 'none')));
        $dVal = (float) ($payload['member_discount_value'] ?? 0.0);
        $membersOnly = !empty($payload['members_only']) ? 1 : 0;
        $flockingEnabled = !empty($payload['flocking_enabled']) ? 1 : 0;
        $flockingPrice = (float) ($payload['flocking_price'] ?? 0.0);
        $flockingMaxChars = max(1, min(60, (int) ($payload['flocking_max_chars'] ?? 20)));

        if ($name === '') {
            return ['error' => 'Le nom du produit est obligatoire.'];
        }
        if ($price < 0) {
            return ['error' => 'Le prix public doit être supérieur ou égal à 0.'];
        }
        if (!in_array($availability, self::ALLOWED_AVAILABILITY, true)) {
            return ['error' => 'Statut de disponibilité invalide (en_stock, sur_commande, rupture, masque).'];
        }
        if (!in_array($dType, self::ALLOWED_DISCOUNT_TYPES, true) || $dVal < 0) {
            return ['error' => 'Paramètres de remise adhérent invalides.'];
        }
        if ($flockingPrice < 0) {
            return ['error' => 'Le supplément flocage ne peut pas être négatif.'];
        }

        $variants = [];
        if (isset($payload['variants']) && is_array($payload['variants'])) {
            foreach ($payload['variants'] as $v) {
                if (!is_array($v)) {
                    continue;
                }
                $sz = trim((string) ($v['size_label'] ?? ''));
                if ($sz === '') {
                    continue;
                }
                $variants[] = [
                    'size_label' => htmlspecialchars($sz, ENT_QUOTES, 'UTF-8'),
                    'stock'      => max(0, (int) ($v['stock'] ?? 0)),
                ];
            }
        }

        return [
            'name'                  => htmlspecialchars($name, ENT_QUOTES, 'UTF-8'),
            'description'           => $desc !== '' ? htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') : null,
            'price'                 => round($price, 2),
            'stock'                 => $stock,
            'category'              => $category !== '' ? htmlspecialchars($category, ENT_QUOTES, 'UTF-8') : 'Équipement',
            'availability'          => $availability,
            'member_discount_type'  => $dType,
            'member_discount_value' => round($dVal, 2),
            'members_only'          => $membersOnly,
            'flocking_enabled'      => $flockingEnabled,
            'flocking_price'        => round($flockingPrice, 2),
            'flocking_max_chars'    => $flockingMaxChars,
            'variants'              => $variants,
        ];
    }

    /**
     * Retourne les paramètres de paiement hors-ligne & livraison de la Boutique.
     * L'option paiement en ligne est toujours exposée comme "Indisponible pour le moment".
     *
     * @return array<string, mixed>
     */
    public function getSettings(): array
    {
        $config = $this->loadShopSettings();
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Paramètres Boutique récupérés.',
            'data'    => $config,
        ];
    }

    /**
     * Met à jour les paramètres paiement hors-ligne et livraison (gestionnaires uniquement).
     * Rejette explicitement toute tentative d'activation du paiement en ligne (422).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function updateSettings(array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];

        if (!empty($payload['online_payment_enabled'])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Le paiement en ligne est indisponible pour le moment (hors périmètre CMS).',
                'data'    => null,
            ];
        }

        $current = $this->loadShopSettings();
        $payMethods = $current['payment_methods'];
        if (isset($payload['payment_methods']) && is_array($payload['payment_methods'])) {
            $filteredPay = [];
            foreach ($payload['payment_methods'] as $pm) {
                $clean = strtolower(trim((string) $pm));
                if ($clean === 'online' || $clean === 'cb' || $clean === 'stripe' || $clean === 'helloasso') {
                    return [
                        'status'  => 'error',
                        'code'    => 422,
                        'message' => 'Le paiement en ligne est indisponible pour le moment.',
                        'data'    => null,
                    ];
                }
                if (in_array($clean, self::ALLOWED_OFFLINE_PAYMENTS, true) && !in_array($clean, $filteredPay, true)) {
                    $filteredPay[] = $clean;
                }
            }
            if (empty($filteredPay)) {
                return ['status' => 'error', 'code' => 422, 'message' => 'Au moins un mode de paiement hors-ligne doit être activé.', 'data' => null];
            }
            $payMethods = $filteredPay;
        }

        $delivMethods = $current['delivery_methods'];
        if (isset($payload['delivery_methods']) && is_array($payload['delivery_methods'])) {
            $filteredDeliv = [];
            foreach ($payload['delivery_methods'] as $dm) {
                $clean = strtolower(trim((string) $dm));
                if (in_array($clean, self::ALLOWED_DELIVERY_METHODS, true) && !in_array($clean, $filteredDeliv, true)) {
                    $filteredDeliv[] = $clean;
                }
            }
            if (empty($filteredDeliv)) {
                return ['status' => 'error', 'code' => 422, 'message' => 'Au moins un mode de livraison doit être activé.', 'data' => null];
            }
            $delivMethods = $filteredDeliv;
        }

        $postalFee = $current['shipping_postal_fee'];
        if (array_key_exists('shipping_postal_fee', $payload)) {
            $fee = (float) $payload['shipping_postal_fee'];
            if ($fee < 0) {
                return ['status' => 'error', 'code' => 422, 'message' => 'Les frais de livraison postale ne peuvent pas être négatifs.', 'data' => null];
            }
            $postalFee = round($fee, 2);
        }

        $sql = Database::isSqlite($this->db)
            ? 'INSERT INTO mod_boutique_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP) ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP'
            : 'INSERT INTO mod_boutique_settings (setting_key, setting_value, updated_at) VALUES (:k, :v, CURRENT_TIMESTAMP) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = CURRENT_TIMESTAMP';
        $stmt = $this->db->prepare($sql);
        $stmt->execute(['k' => 'payment_methods', 'v' => (string) json_encode($payMethods)]);
        $stmt->execute(['k' => 'delivery_methods', 'v' => (string) json_encode($delivMethods)]);
        $stmt->execute(['k' => 'shipping_postal_fee', 'v' => number_format($postalFee, 2, '.', '')]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Paramètres Boutique enregistrés avec succès.',
            'data'    => $this->loadShopSettings(),
        ];
    }

    /**
     * Liste les commandes (toutes pour finances.manage/Superadmin, propres commandes pour Adhérent).
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function listOrders(array $ctx): array
    {
        if (empty($ctx['is_authenticated']) && empty($ctx['is_superadmin'])) {
            return ['status' => 'error', 'code' => 401, 'message' => 'Authentification requise pour consulter le suivi des commandes.', 'data' => null];
        }

        $canManage = $this->canManageShop($ctx);
        $userId = (int) ($ctx['user_id'] ?? 0);

        if ($canManage) {
            $stmt = $this->db->query('SELECT * FROM mod_boutique_orders ORDER BY id DESC LIMIT 100');
            $orders = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } else {
            $uEmail = $this->resolveUserEmail($userId);
            $stmt = $this->db->prepare("
                SELECT * FROM mod_boutique_orders
                WHERE user_id = :uid OR (:uemail != '' AND LOWER(buyer_email) = :uemail2)
                ORDER BY id DESC
                LIMIT 100
            ");
            $stmt->execute(['uid' => $userId, 'uemail' => $uEmail, 'uemail2' => $uEmail]);
            $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $iStmt = $this->db->query('SELECT * FROM mod_boutique_order_items ORDER BY id ASC');
        $allItems = $iStmt !== false ? $iStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $itemsByOrder = [];
        foreach ($allItems as $it) {
            $itemsByOrder[(int) $it['order_id']][] = $it;
        }

        foreach ($orders as &$ord) {
            $oid = (int) $ord['id'];
            $ord['id'] = $oid;
            $ord['items'] = $itemsByOrder[$oid] ?? [];
            $hist = json_decode((string) ($ord['status_history_json'] ?? '[]'), true);
            $ord['status_history'] = is_array($hist) ? $hist : [];
        }
        unset($ord);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Commandes récupérées.',
            'data'    => [
                'can_manage' => $canManage,
                'orders'     => $orders,
            ],
        ];
    }

    /**
     * Crée une commande hors-ligne avec recalcul tarifaire serveur, contrôle de stock atomique
     * et décrémentation de stock produit/variante.
     *
     * @param array<string, mixed> $ctx Contexte de requête.
     * @return array<string, mixed>
     */
    public function createOrder(array $ctx): array
    {
        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $isAuth = !empty($ctx['is_authenticated']) || !empty($ctx['is_superadmin']);
        $userId = (int) ($ctx['user_id'] ?? 0);

        $buyerName = trim((string) ($payload['buyer_name'] ?? ''));
        $buyerEmail = strtolower(trim((string) ($payload['buyer_email'] ?? '')));
        $buyerAddress = trim((string) ($payload['buyer_address'] ?? ''));
        $paymentMethod = strtolower(trim((string) ($payload['payment_method'] ?? '')));
        $deliveryMethod = strtolower(trim((string) ($payload['delivery_method'] ?? '')));
        $rawItems = $payload['items'] ?? [];

        if ($buyerName === '' || !filter_var($buyerEmail, FILTER_VALIDATE_EMAIL)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Nom et e-mail valide de l\'acheteur obligatoires.', 'data' => null];
        }
        if ($paymentMethod === 'online' || $paymentMethod === 'cb' || $paymentMethod === 'stripe' || $paymentMethod === 'helloasso') {
            return ['status' => 'error', 'code' => 422, 'message' => 'Le paiement en ligne est indisponible pour le moment.', 'data' => null];
        }

        $settings = $this->loadShopSettings();
        if (!in_array($paymentMethod, $settings['payment_methods'], true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Mode de paiement hors-ligne non autorisé ou désactivé.', 'data' => null];
        }
        if (!in_array($deliveryMethod, $settings['delivery_methods'], true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Mode de livraison non autorisé ou désactivé.', 'data' => null];
        }
        if ($deliveryMethod === 'livraison_postale' && $buyerAddress === '') {
            return ['status' => 'error', 'code' => 422, 'message' => 'Adresse postale obligatoire pour une livraison postale.', 'data' => null];
        }
        if (!is_array($rawItems) || empty($rawItems)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Le panier ne peut pas être vide.', 'data' => null];
        }

        try {
            $order = Database::transaction(function () use (
                $rawItems,
                $isAuth,
                $userId,
                $buyerName,
                $buyerEmail,
                $buyerAddress,
                $paymentMethod,
                $deliveryMethod,
                $settings
            ): array {
                $resolvedItems = [];
                $subtotal = 0.0;

                foreach ($rawItems as $it) {
                    if (!is_array($it)) {
                        continue;
                    }
                    $type = strtolower(trim((string) ($it['item_type'] ?? 'product')));
                    $itemId = (int) ($it['item_id'] ?? 0);
                    $qty = max(1, (int) ($it['quantity'] ?? 1));
                    $sizeLabel = trim((string) ($it['size_label'] ?? ''));
                    $flockingText = trim((string) ($it['flocking_text'] ?? ''));

                    if ($itemId <= 0) {
                        throw new RuntimeException('Article de panier invalide.', 422);
                    }

                    if ($type === 'bundle') {
                        $bStmt = $this->db->prepare('SELECT * FROM mod_boutique_bundles WHERE id = :id LIMIT 1');
                        $bStmt->execute(['id' => $itemId]);
                        $bundle = $bStmt->fetch(PDO::FETCH_ASSOC);
                        if ($bundle === false) {
                            throw new RuntimeException('Lot introuvable.', 422);
                        }
                        if ((int) ($bundle['members_only'] ?? 0) === 1 && !$isAuth) {
                            throw new RuntimeException('Ce lot est réservé aux adhérents connectés.', 403);
                        }
                        if (in_array((string) $bundle['availability'], ['rupture', 'masque'], true)) {
                            throw new RuntimeException('Ce lot n\'est pas disponible à la commande.', 409);
                        }
                        $pubPrice = round((float) $bundle['price'], 2);
                        $unitPrice = $isAuth
                            ? self::computeMemberPrice($pubPrice, (string) $bundle['member_discount_type'], (float) $bundle['member_discount_value'])
                            : $pubPrice;
                        $lineTotal = round($unitPrice * $qty, 2);
                        $subtotal += $lineTotal;

                        $resolvedItems[] = [
                            'item_type'      => 'bundle',
                            'item_id'        => $itemId,
                            'item_label'     => (string) $bundle['name'],
                            'size_label'     => $sizeLabel !== '' ? htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8') : null,
                            'flocking_text'  => null,
                            'flocking_price' => 0.0,
                            'quantity'       => $qty,
                            'unit_price'     => $unitPrice,
                            'line_total'     => $lineTotal,
                        ];
                    } else {
                        $pStmt = $this->db->prepare('SELECT * FROM mod_boutique_products WHERE id = :id LIMIT 1');
                        $pStmt->execute(['id' => $itemId]);
                        $prod = $pStmt->fetch(PDO::FETCH_ASSOC);
                        if ($prod === false) {
                            throw new RuntimeException('Produit introuvable.', 422);
                        }
                        if ((int) ($prod['members_only'] ?? 0) === 1 && !$isAuth) {
                            throw new RuntimeException('Ce produit est réservé aux adhérents connectés.', 403);
                        }
                        if (in_array((string) $prod['availability'], ['rupture', 'masque'], true)) {
                            throw new RuntimeException('Ce produit n\'est pas disponible à la commande.', 409);
                        }

                        // Contrôle et décrémentation de stock (variante si présente, sinon produit global)
                        if ($sizeLabel !== '') {
                            $vStmt = $this->db->prepare('SELECT id, stock FROM mod_boutique_variants WHERE product_id = :pid AND size_label = :sz LIMIT 1');
                            $vStmt->execute(['pid' => $itemId, 'sz' => $sizeLabel]);
                            $variant = $vStmt->fetch(PDO::FETCH_ASSOC);
                            if ($variant !== false) {
                                if ((string) $prod['availability'] === 'en_stock' && (int) $variant['stock'] < $qty) {
                                    throw new RuntimeException(sprintf('Stock insuffisant pour la taille %s.', $sizeLabel), 409);
                                }
                                $newVStock = max(0, (int) $variant['stock'] - $qty);
                                $this->db->prepare('UPDATE mod_boutique_variants SET stock = :st WHERE id = :id')->execute(['st' => $newVStock, 'id' => (int) $variant['id']]);
                            }
                        } elseif ((string) $prod['availability'] === 'en_stock' && (int) $prod['stock'] < $qty) {
                            throw new RuntimeException(sprintf('Stock insuffisant pour %s.', (string) $prod['name']), 409);
                        }

                        $newPStock = max(0, (int) $prod['stock'] - $qty);
                        $this->db->prepare('UPDATE mod_boutique_products SET stock = :st WHERE id = :id')->execute(['st' => $newPStock, 'id' => $itemId]);

                        $pubPrice = round((float) $prod['price'], 2);
                        $unitPrice = $isAuth
                            ? self::computeMemberPrice($pubPrice, (string) $prod['member_discount_type'], (float) $prod['member_discount_value'])
                            : $pubPrice;

                        $flockPrice = 0.0;
                        $cleanFlock = null;
                        if ($flockingText !== '' && (int) ($prod['flocking_enabled'] ?? 0) === 1) {
                            $maxChars = max(1, (int) ($prod['flocking_max_chars'] ?? 20));
                            $cleanFlock = htmlspecialchars(mb_substr($flockingText, 0, $maxChars), ENT_QUOTES, 'UTF-8');
                            $flockPrice = round((float) ($prod['flocking_price'] ?? 0.0), 2);
                        }

                        $lineTotal = round(($unitPrice + $flockPrice) * $qty, 2);
                        $subtotal += $lineTotal;

                        $resolvedItems[] = [
                            'item_type'      => 'product',
                            'item_id'        => $itemId,
                            'item_label'     => (string) $prod['name'],
                            'size_label'     => $sizeLabel !== '' ? htmlspecialchars($sizeLabel, ENT_QUOTES, 'UTF-8') : null,
                            'flocking_text'  => $cleanFlock,
                            'flocking_price' => $flockPrice,
                            'quantity'       => $qty,
                            'unit_price'     => $unitPrice,
                            'line_total'     => $lineTotal,
                        ];
                    }
                }

                if (empty($resolvedItems)) {
                    throw new RuntimeException('Aucun article valide dans le panier.', 422);
                }

                $shippingAmount = $deliveryMethod === 'livraison_postale' ? (float) $settings['shipping_postal_fee'] : 0.0;
                $totalAmount = round($subtotal + $shippingAmount, 2);

                $year = date('Y');
                $cntStmt = $this->db->prepare('SELECT COUNT(*) FROM mod_boutique_orders WHERE order_number LIKE :pat');
                $cntStmt->execute(['pat' => "CMD-{$year}-%"]);
                $seq = ((int) $cntStmt->fetchColumn()) + 1;
                $orderNumber = sprintf('CMD-%s-%04d', $year, $seq);

                $history = [
                    ['status' => 'en_attente_paiement', 'at' => date('Y-m-d H:i:s'), 'note' => 'Commande enregistrée'],
                ];

                $oIns = $this->db->prepare("
                    INSERT INTO mod_boutique_orders (
                        order_number, user_id, buyer_name, buyer_email, buyer_address,
                        payment_method, delivery_method, subtotal_amount, shipping_amount, total_amount,
                        status, status_history_json
                    ) VALUES (
                        :num, :uid, :bname, :bemail, :baddr,
                        :pm, :dm, :sub, :ship, :tot,
                        'en_attente_paiement', :hist
                    )
                ");
                $oIns->execute([
                    'num'    => $orderNumber,
                    'uid'    => $userId > 0 ? $userId : null,
                    'bname'  => htmlspecialchars($buyerName, ENT_QUOTES, 'UTF-8'),
                    'bemail' => $buyerEmail,
                    'baddr'  => $buyerAddress !== '' ? htmlspecialchars($buyerAddress, ENT_QUOTES, 'UTF-8') : null,
                    'pm'     => $paymentMethod,
                    'dm'     => $deliveryMethod,
                    'sub'    => round($subtotal, 2),
                    'ship'   => round($shippingAmount, 2),
                    'tot'    => $totalAmount,
                    'hist'   => json_encode($history, JSON_UNESCAPED_UNICODE),
                ]);
                $orderId = (int) $this->db->lastInsertId();

                $oiIns = $this->db->prepare("
                    INSERT INTO mod_boutique_order_items (
                        order_id, item_type, item_id, item_label, size_label,
                        flocking_text, flocking_price, quantity, unit_price, line_total
                    ) VALUES (
                        :oid, :itype, :iid, :ilabel, :sz,
                        :flock, :fprice, :qty, :uprice, :ltot
                    )
                ");
                foreach ($resolvedItems as $ri) {
                    $oiIns->execute([
                        'oid'    => $orderId,
                        'itype'  => $ri['item_type'],
                        'iid'    => $ri['item_id'],
                        'ilabel' => $ri['item_label'],
                        'sz'     => $ri['size_label'],
                        'flock'  => $ri['flocking_text'],
                        'fprice' => $ri['flocking_price'],
                        'qty'    => $ri['quantity'],
                        'uprice' => $ri['unit_price'],
                        'ltot'   => $ri['line_total'],
                    ]);
                }

                return [
                    'id'              => $orderId,
                    'order_number'    => $orderNumber,
                    'subtotal_amount' => round($subtotal, 2),
                    'shipping_amount' => round($shippingAmount, 2),
                    'total_amount'    => $totalAmount,
                    'status'          => 'en_attente_paiement',
                    'items'           => $resolvedItems,
                ];
            });

            return [
                'status'  => 'success',
                'code'    => 201,
                'message' => sprintf('Commande %s enregistrée avec succès.', (string) $order['order_number']),
                'data'    => ['order' => $order],
            ];
        } catch (RuntimeException $e) {
            $errCode = in_array($e->getCode(), [403, 409, 422], true) ? $e->getCode() : 422;
            return ['status' => 'error', 'code' => $errCode, 'message' => $e->getMessage(), 'data' => null];
        } catch (Throwable $e) {
            return ['status' => 'error', 'code' => 500, 'message' => 'Erreur lors de la commande : ' . $e->getMessage(), 'data' => null];
        }
    }

    /**
     * Fait évoluer le statut d'une commande (gestionnaires uniquement).
     * Déclenche automatiquement la facturation PDF officielle (hook 'issue_official_invoice')
     * lors du passage au statut 'payee', et restaure le stock lors d'une annulation.
     *
     * @param int                  $orderId Identifiant commande.
     * @param array<string, mixed> $ctx     Contexte de requête.
     * @return array<string, mixed>
     */
    public function updateOrderStatus(int $orderId, array $ctx): array
    {
        if (!$this->canManageShop($ctx)) {
            return ['status' => 'error', 'code' => 403, 'message' => 'Accès refusé : droit finances.manage requis.', 'data' => null];
        }

        $payload = is_array($ctx['payload'] ?? null) ? $ctx['payload'] : [];
        $newStatus = strtolower(trim((string) ($payload['status'] ?? '')));
        $note = trim((string) ($payload['note'] ?? ''));

        if (!in_array($newStatus, self::ALLOWED_ORDER_STATUSES, true)) {
            return ['status' => 'error', 'code' => 422, 'message' => 'Statut de commande invalide.', 'data' => null];
        }

        $stmt = $this->db->prepare('SELECT * FROM mod_boutique_orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($order === false) {
            return ['status' => 'error', 'code' => 404, 'message' => 'Commande introuvable.', 'data' => null];
        }

        $prevStatus = (string) $order['status'];
        $itemsStmt = $this->db->prepare('SELECT * FROM mod_boutique_order_items WHERE order_id = :oid ORDER BY id ASC');
        $itemsStmt->execute(['oid' => $orderId]);
        $orderItems = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        $history = json_decode((string) ($order['status_history_json'] ?? '[]'), true);
        if (!is_array($history)) {
            $history = [];
        }
        $history[] = [
            'status' => $newStatus,
            'at'     => date('Y-m-d H:i:s'),
            'note'   => $note !== '' ? htmlspecialchars($note, ENT_QUOTES, 'UTF-8') : null,
        ];

        Database::transaction(function () use ($orderId, $newStatus, $prevStatus, $history, $orderItems): void {
            $this->db->prepare('UPDATE mod_boutique_orders SET status = :st, status_history_json = :hist WHERE id = :id')
                ->execute([
                    'st'   => $newStatus,
                    'hist' => json_encode($history, JSON_UNESCAPED_UNICODE),
                    'id'   => $orderId,
                ]);

            // Restauration du stock en cas d'annulation
            if ($newStatus === 'annulee' && $prevStatus !== 'annulee') {
                foreach ($orderItems as $oi) {
                    if (($oi['item_type'] ?? '') === 'product') {
                        $pid = (int) $oi['item_id'];
                        $qty = (int) $oi['quantity'];
                        $this->db->prepare('UPDATE mod_boutique_products SET stock = stock + :q WHERE id = :id')->execute(['q' => $qty, 'id' => $pid]);
                        if (!empty($oi['size_label'])) {
                            $this->db->prepare('UPDATE mod_boutique_variants SET stock = stock + :q WHERE product_id = :pid AND size_label = :sz')
                                ->execute(['q' => $qty, 'pid' => $pid, 'sz' => (string) $oi['size_label']]);
                        }
                    }
                }
            }
        });

        // Déclenchement hors transaction SQLite de la Facturation PDF officielle si passage à 'payee'
        if ($newStatus === 'payee') {
            $invoiceItems = [];
            foreach ($orderItems as $oi) {
                $desc = (string) $oi['item_label'];
                if (!empty($oi['size_label'])) {
                    $desc .= ' (Taille ' . (string) $oi['size_label'] . ')';
                }
                if (!empty($oi['flocking_text'])) {
                    $desc .= ' [Flocage: ' . (string) $oi['flocking_text'] . ']';
                }
                $invoiceItems[] = [
                    'label'      => $desc,
                    'qty'        => (int) $oi['quantity'],
                    'unit_price' => round((float) $oi['unit_price'] + (float) $oi['flocking_price'], 2),
                ];
            }
            if ((float) ($order['shipping_amount'] ?? 0) > 0) {
                $invoiceItems[] = [
                    'label'      => 'Frais de livraison postale',
                    'qty'        => 1,
                    'unit_price' => round((float) $order['shipping_amount'], 2),
                ];
            }

            try {
                EventManager::getInstance()->dispatch('issue_official_invoice', [
                    'source_type'       => 'boutique',
                    'source_id'         => $orderId,
                    'user_id'           => !empty($order['user_id']) ? (int) $order['user_id'] : null,
                    'recipient_name'    => (string) $order['buyer_name'],
                    'recipient_email'   => (string) $order['buyer_email'],
                    'recipient_address' => (string) ($order['buyer_address'] ?? ''),
                    'items'             => $invoiceItems,
                    'discount_amount'   => 0.0,
                    'payment_method'    => (string) $order['payment_method'],
                    'issued_by'         => (int) ($ctx['user_id'] ?? 0) ?: null,
                    'send_email'        => true,
                ]);
            } catch (Throwable) {
            }
        }

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => sprintf('Statut de la commande %s mis à jour (%s).', (string) $order['order_number'], $newStatus),
            'data'    => ['id' => $orderId, 'status' => $newStatus],
        ];
    }

    /**
     * Charge la configuration effective de la Boutique avec valeurs par défaut.
     *
     * @return array{
     *     payment_methods: list<string>,
     *     online_payment_available: bool,
     *     online_payment_label: string,
     *     delivery_methods: list<string>,
     *     shipping_postal_fee: float
     * }
     */
    private function loadShopSettings(): array
    {
        $payMethods = ['cheque', 'especes', 'virement'];
        $delivMethods = ['retrait_dojo', 'remise_main_propre', 'livraison_postale'];
        $postalFee = 6.50;

        try {
            $stmt = $this->db->query('SELECT setting_key, setting_value FROM mod_boutique_settings');
            if ($stmt !== false) {
                /** @var array<string, string> $rows */
                $rows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                if (!empty($rows['payment_methods'])) {
                    $dec = json_decode($rows['payment_methods'], true);
                    if (is_array($dec) && !empty($dec)) {
                        $payMethods = array_values(array_map('strval', $dec));
                    }
                }
                if (!empty($rows['delivery_methods'])) {
                    $dec = json_decode($rows['delivery_methods'], true);
                    if (is_array($dec) && !empty($dec)) {
                        $delivMethods = array_values(array_map('strval', $dec));
                    }
                }
                if (isset($rows['shipping_postal_fee']) && is_numeric($rows['shipping_postal_fee'])) {
                    $postalFee = round(max(0.0, (float) $rows['shipping_postal_fee']), 2);
                }
            }
        } catch (Throwable) {
        }

        return [
            'payment_methods'          => $payMethods,
            'online_payment_available' => false,
            'online_payment_label'     => 'Indisponible pour le moment',
            'delivery_methods'         => $delivMethods,
            'shipping_postal_fee'      => $postalFee,
        ];
    }

    /**
     * Retourne l'e-mail en minuscules d'un utilisateur connecté.
     *
     * @param int $userId Identifiant users.id.
     * @return string
     */
    private function resolveUserEmail(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }
        $stmt = $this->db->prepare('SELECT email FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        return strtolower(trim((string) ($stmt->fetchColumn() ?: '')));
    }
}
