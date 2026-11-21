<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Page Boutique Officielle du Club (/boutique — Lot 6 / Sprints 25 & 26).
 * Interface 3 onglets :
 * - Catalogue & Panier (produits, variantes taille, flocage, lots, panier & commande hors-ligne)
 * - Suivi Commandes (historique acheteur + gestion statuts Bureau & liaison Facturation PDF)
 * - Administration & Paramètres (paiement hors-ligne, livraison, création produits & lots).
 */

use Core\Api\Database\Database;
use Modules\BoutiqueApi\Endpoints\BoutiqueController;

$pageTitle   = 'Boutique Officielle du Club';
$activeNav   = 'boutique';
$requireAuth = false;
$pageScripts = ['/assets/modules/boutique/boutique.js'];

$isSuperadmin = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));
$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole = (string) ($_SESSION['role_name'] ?? 'Visiteur');
$isAuthenticated = $isSuperadmin || ($currentUserId > 0 && $currentRole !== 'Visiteur');

$sessionPerms = [];
$buyerDefaultName = '';
$buyerDefaultEmail = '';

if ($isSuperadmin) {
    $sessionPerms = ['finances.manage'];
    $buyerDefaultName = 'Administrateur Kanso-ya';
    $buyerDefaultEmail = 'admin@kanso-ya.fr';
} elseif ($isAuthenticated) {
    try {
        $dbPerms = Database::getInstance();
        $stmtRole = $dbPerms->prepare(
            'SELECT u.first_name, u.last_name, u.email, r.permissions
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = :uid LIMIT 1'
        );
        $stmtRole->execute(['uid' => $currentUserId]);
        $uRow = $stmtRole->fetch();
        if (is_array($uRow)) {
            $buyerDefaultName = trim((string) ($uRow['first_name'] ?? '') . ' ' . (string) ($uRow['last_name'] ?? ''));
            $buyerDefaultEmail = (string) ($uRow['email'] ?? '');
            $rawPerms = $uRow['permissions'] ?? '';
            if (is_string($rawPerms)) {
                $decodedPerms = json_decode($rawPerms, true);
                if (is_array($decodedPerms)) {
                    $sessionPerms = $decodedPerms;
                }
            }
        }
    } catch (Throwable) {
    }
    if (empty($sessionPerms) && in_array($currentRole, ['Président', 'Trésorier'], true)) {
        $sessionPerms = ['finances.manage'];
    }
}
$canManage = $isSuperadmin || in_array('finances.manage', $sessionPerms, true);

$ctx = [
    'is_authenticated' => $isAuthenticated,
    'is_superadmin'    => $isSuperadmin,
    'user_id'          => $currentUserId,
    'permissions'      => $sessionPerms,
];

$products = [];
$bundles = [];
$settings = [
    'payment_methods'          => ['cheque', 'especes', 'virement'],
    'online_payment_available' => false,
    'online_payment_label'     => 'Indisponible pour le moment',
    'delivery_methods'         => ['retrait_dojo', 'remise_main_propre', 'livraison_postale'],
    'shipping_postal_fee'      => 6.50,
];
$tableExists = false;

try {
    $ctrl = new BoutiqueController();
    $resP = $ctrl->listProducts($ctx);
    if (($resP['status'] ?? '') === 'success' && is_array($resP['data'] ?? null)) {
        $tableExists = true;
        $products = $resP['data'];
    }
    $resB = $ctrl->listBundles($ctx);
    if (($resB['status'] ?? '') === 'success' && is_array($resB['data'] ?? null)) {
        $bundles = $resB['data'];
    }
    $resS = $ctrl->getSettings();
    if (($resS['status'] ?? '') === 'success' && is_array($resS['data'] ?? null)) {
        $settings = array_merge($settings, $resS['data']);
    }
} catch (Throwable) {
}

$availLabels = [
    'en_stock'     => ['En stock', 'bg-emerald-50 text-emerald-700 border-emerald-200'],
    'sur_commande' => ['Sur commande', 'bg-blue-50 text-blue-700 border-blue-200'],
    'rupture'      => ['Rupture', 'bg-red-50 text-red-700 border-red-200'],
    'masque'       => ['Masqué (Admin)', 'bg-slate-200 text-slate-700 border-slate-300'],
];

$paymentLabels = [
    'cheque'   => 'Chèque (ordre du club)',
    'especes'  => 'Espèces (au dojo)',
    'virement' => 'Virement bancaire',
];

$deliveryLabels = [
    'retrait_dojo'       => 'Retrait au dojo (Gratuit)',
    'remise_main_propre' => 'Remise en main propre (Gratuit)',
    'livraison_postale'  => 'Livraison postale (+' . number_format((float) $settings['shipping_postal_fee'], 2, ',', ' ') . ' €)',
];

$activePayMethods = is_array($settings['payment_methods'] ?? null) ? $settings['payment_methods'] : ['cheque'];
$activeDelivMethods = is_array($settings['delivery_methods'] ?? null) ? $settings['delivery_methods'] : ['retrait_dojo'];
$postalFee = (float) ($settings['shipping_postal_fee'] ?? 6.50);

ob_start();
?>
<link rel="stylesheet" href="/assets/modules/boutique/boutique.css">
<div class="space-y-6"
     id="boutique-app-root"
     data-postal-fee="<?= number_format($postalFee, 2, '.', '') ?>"
     data-can-manage="<?= $canManage ? '1' : '0' ?>"
     data-is-authenticated="<?= $isAuthenticated ? '1' : '0' ?>">

    <!-- En-tête de la Boutique -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">🛍️</span>
                <h1 class="text-xl font-extrabold text-slate-900">Boutique Officielle du Club</h1>
            </div>
            <p class="text-xs text-slate-500 mt-1">Équipements certifiés, kimonos brodés, flocages personnalisés, lots et suivi de vos commandes.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <?php if ($isAuthenticated): ?>
                <span class="px-3 py-1.5 rounded-full text-xs font-semibold bg-indigo-50 text-indigo-700 border border-indigo-200">
                    🎟️ Tarifs préférentiels adhérent actifs
                </span>
            <?php else: ?>
                <a href="/login" class="px-3 py-1.5 rounded-full text-xs font-semibold bg-slate-100 hover:bg-slate-200 text-slate-700 transition">
                    🔑 Connectez-vous pour vos tarifs adhérents
                </a>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <button type="button" id="btn-toggle-boutique-admin" data-testid="btn-toggle-boutique-admin" class="px-3.5 py-1.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition">
                    ⚙️ Administration
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$tableExists): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center text-amber-800 space-y-2">
            <div class="text-2xl">⚠️</div>
            <div class="font-bold">Le module Boutique n'est pas encore installé ou actif.</div>
            <p class="text-xs text-amber-700">Rendez-vous dans la console d'administration pour activer le module.</p>
        </div>
    <?php else: ?>

        <!-- BARRE D'ONGLETS BOUTIQUE -->
        <div class="flex flex-wrap items-center gap-2 border-b border-slate-200 pb-3" role="tablist">
            <button type="button" id="boutique-tab-catalog" data-testid="boutique-tab-catalog" data-boutique-tab="catalog"
                    class="px-4 py-2 rounded-xl text-xs font-bold bg-slate-900 text-white transition">
                🛍️ Catalogue & Panier
            </button>
            <button type="button" id="boutique-tab-orders" data-testid="boutique-tab-orders" data-boutique-tab="orders"
                    class="px-4 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition">
                📦 Suivi Commandes
            </button>
            <?php if ($canManage): ?>
                <button type="button" id="boutique-tab-admin" data-testid="boutique-tab-admin" data-boutique-tab="admin"
                        class="px-4 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-slate-700 hover:bg-slate-50 transition">
                    ⚙️ Administration & Paramètres
                </button>
            <?php endif; ?>
        </div>

        <!-- =========================================================================
             ONGLET 1 : CATALOGUE & PANIER
             ========================================================================= -->
        <div id="boutique-section-catalog" class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
            <div class="lg:col-span-2 space-y-6">
                <!-- GRILLE DES PRODUITS -->
                <section class="space-y-3">
                    <h2 class="text-base font-extrabold text-slate-900">Articles du Catalogue</h2>
                    <div id="boutique-products-grid" data-testid="boutique-products-grid" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php foreach ($products as $prod): ?>
                            <?php
                            $pid = (int) $prod['id'];
                            $availKey = (string) ($prod['availability'] ?? 'en_stock');
                            [$availText, $availClass] = $availLabels[$availKey] ?? $availLabels['en_stock'];
                            $pubPrice = (float) $prod['price'];
                            $effPrice = (float) $prod['effective_price'];
                            $hasMemberDiscount = $isAuthenticated && $effPrice < $pubPrice;
                            $variants = is_array($prod['variants'] ?? null) ? $prod['variants'] : [];
                            $flockEnabled = !empty($prod['flocking_enabled']);
                            $flockPrice = (float) ($prod['flocking_price'] ?? 0.0);
                            $flockMax = (int) ($prod['flocking_max_chars'] ?? 20);
                            $disabledAdd = ($availKey === 'rupture' || $availKey === 'masque');
                            ?>
                            <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4"
                                 data-testid="product-card-<?= $pid ?>"
                                 data-product-card="<?= $pid ?>"
                                 data-product-name="<?= htmlspecialchars((string) $prod['name'], ENT_QUOTES, 'UTF-8') ?>"
                                 data-base-price="<?= number_format($effPrice, 2, '.', '') ?>"
                                 data-flocking-price="<?= number_format($flockPrice, 2, '.', '') ?>">
                                <div class="space-y-2.5">
                                    <div class="flex flex-wrap justify-between items-center gap-1.5">
                                        <span class="px-2.5 py-0.5 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">
                                            <?= htmlspecialchars((string) ($prod['category'] ?? 'Équipement'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold border <?= $availClass ?>">
                                            <?= htmlspecialchars($availText, ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <?php if (!empty($prod['members_only'])): ?>
                                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-indigo-50 text-indigo-700 border border-indigo-200">
                                                Adhérents uniquement
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <h3 class="font-bold text-slate-900 text-base leading-snug">
                                        <?= htmlspecialchars((string) $prod['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </h3>
                                    <?php if (!empty($prod['description'])): ?>
                                        <p class="text-xs text-slate-500"><?= htmlspecialchars((string) $prod['description'], ENT_QUOTES, 'UTF-8') ?></p>
                                    <?php endif; ?>

                                    <div class="flex items-baseline gap-2 pt-1">
                                        <?php if ($hasMemberDiscount): ?>
                                            <span class="text-xs text-slate-400 line-through"><?= number_format($pubPrice, 2, ',', ' ') ?> €</span>
                                        <?php endif; ?>
                                        <span data-testid="product-effective-price-<?= $pid ?>" data-effective-price-display="<?= $pid ?>" class="text-base font-extrabold text-emerald-600">
                                            <?= number_format($effPrice, 2, ',', ' ') ?> €
                                        </span>
                                        <?php if ($hasMemberDiscount): ?>
                                            <span class="text-[10px] font-bold text-indigo-600">Tarif adhérent</span>
                                        <?php endif; ?>
                                    </div>

                                    <?php if (!empty($variants)): ?>
                                        <div>
                                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Taille</label>
                                            <select data-size-select="<?= $pid ?>" data-testid="product-size-select-<?= $pid ?>" class="w-full px-2.5 py-1.5 border border-slate-200 rounded-xl text-xs bg-slate-50">
                                                <?php foreach ($variants as $v): ?>
                                                    <option value="<?= htmlspecialchars((string) $v['size_label'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars((string) $v['size_label'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $v['stock'] ?> dispo)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <?php if ($flockEnabled): ?>
                                        <div class="p-2.5 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                                            <label class="inline-flex items-center gap-1.5 text-xs font-semibold text-slate-700 cursor-pointer">
                                                <input type="checkbox" data-flocking-toggle="<?= $pid ?>" data-testid="product-flocking-toggle-<?= $pid ?>" class="rounded border-slate-300">
                                                <span>Flocage personnalisé (+<?= number_format($flockPrice, 2, ',', ' ') ?> €)</span>
                                            </label>
                                            <input type="text" maxlength="<?= $flockMax ?>" data-flocking-text="<?= $pid ?>" data-testid="product-flocking-text-<?= $pid ?>" placeholder="Texte à floquer (max <?= $flockMax ?> car.)" class="hidden w-full px-2.5 py-1.5 border border-slate-300 rounded-lg text-xs bg-white">
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                                    <div class="text-xs text-slate-500">
                                        Stock : <strong data-testid="product-stock-<?= $pid ?>" class="text-slate-800"><?= (int) $prod['stock'] ?> ex.</strong>
                                    </div>
                                    <button type="button"
                                            <?= $disabledAdd ? 'disabled' : '' ?>
                                            data-add-product="<?= $pid ?>"
                                            data-testid="btn-add-to-cart-product-<?= $pid ?>"
                                            class="px-3.5 py-2 rounded-xl <?= $disabledAdd ? 'bg-slate-200 text-slate-500 cursor-not-allowed' : 'brand-primary-bg hover:opacity-90 text-white' ?> text-xs font-semibold shadow-xs transition">
                                        <?= $disabledAdd ? 'Indisponible' : '+ Panier' ?>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <!-- GRILLE DES LOTS / PACKS -->
                <section class="space-y-3">
                    <h2 class="text-base font-extrabold text-slate-900">🎁 Lots & Packs du Club</h2>
                    <div id="boutique-bundles-grid" data-testid="boutique-bundles-grid" class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <?php if (empty($bundles)): ?>
                            <p class="text-xs text-slate-400 italic col-span-full">Aucun lot promotionnel disponible pour le moment.</p>
                        <?php else: ?>
                            <?php foreach ($bundles as $b): ?>
                                <?php
                                $bid = (int) $b['id'];
                                $bItems = is_array($b['items'] ?? null) ? $b['items'] : [];
                                $bPrice = (float) $b['effective_price'];
                                ?>
                                <div class="bg-white rounded-2xl border border-indigo-200 p-5 shadow-xs flex flex-col justify-between space-y-3"
                                     data-testid="bundle-card-<?= $bid ?>"
                                     data-bundle-card="<?= $bid ?>"
                                     data-bundle-name="<?= htmlspecialchars((string) $b['name'], ENT_QUOTES, 'UTF-8') ?>"
                                     data-bundle-price="<?= number_format($bPrice, 2, '.', '') ?>">
                                    <div class="space-y-2">
                                        <div class="flex items-center justify-between gap-2">
                                            <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-indigo-50 text-indigo-700">Lot / Pack</span>
                                            <span class="text-base font-extrabold text-indigo-700"><?= number_format($bPrice, 2, ',', ' ') ?> €</span>
                                        </div>
                                        <h3 class="font-bold text-slate-900 text-base"><?= htmlspecialchars((string) $b['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                        <ul class="text-xs text-slate-600 space-y-1 list-disc list-inside">
                                            <?php foreach ($bItems as $bi): ?>
                                                <li>
                                                    <?= (int) $bi['quantity'] ?>× <?= htmlspecialchars((string) $bi['label'], ENT_QUOTES, 'UTF-8') ?>
                                                    <?php if (!empty($bi['is_exclusive'])): ?>
                                                        <span class="text-[10px] font-bold text-amber-700">(Exclusif au lot)</span>
                                                    <?php endif; ?>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                    <div class="pt-3 border-t border-indigo-100 flex justify-end">
                                        <button type="button"
                                                data-add-bundle="<?= $bid ?>"
                                                data-testid="btn-add-to-cart-bundle-<?= $bid ?>"
                                                class="px-3.5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition">
                                            + Ajouter le lot
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </section>
            </div>

            <!-- PANNEAU PANIER & VALIDATION COMMANDE (colonne droite) -->
            <aside id="boutique-cart-panel" data-testid="boutique-cart-panel" class="bg-white rounded-2xl border border-slate-200 p-5 shadow-sm space-y-4 lg:sticky lg:top-6">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <h2 class="text-sm font-extrabold text-slate-900">🛒 Votre Panier</h2>
                    <button type="button" id="btn-clear-cart" class="text-[11px] font-semibold text-slate-400 hover:text-red-600 transition">Vider</button>
                </div>

                <div id="cart-items-list" data-testid="cart-items-list" class="space-y-2 text-xs text-slate-500">
                    <p class="italic text-slate-400">Votre panier est vide. Ajoutez un article ou un lot.</p>
                </div>

                <form id="boutique-checkout-form" class="space-y-3 border-t border-slate-100 pt-3">
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nom & Prénom *</label>
                        <input type="text" id="cart-buyer-name" data-testid="cart-buyer-name" required
                               value="<?= htmlspecialchars($buyerDefaultName, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="Ex: Kenji Tanaka"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">E-mail de suivi & facture *</label>
                        <input type="email" id="cart-buyer-email" data-testid="cart-buyer-email" required
                               value="<?= htmlspecialchars($buyerDefaultEmail, ENT_QUOTES, 'UTF-8') ?>"
                               placeholder="prenom.nom@exemple.fr"
                               class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Mode de paiement hors-ligne *</label>
                        <select id="cart-payment-select" data-testid="cart-payment-select" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                            <?php foreach ($activePayMethods as $pm): ?>
                                <?php if (isset($paymentLabels[$pm])): ?>
                                    <option value="<?= htmlspecialchars((string) $pm, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($paymentLabels[$pm], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-[10px] text-slate-400 mt-1">ℹ️ Paiement en ligne (CB) : indisponible pour le moment.</p>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Mode de livraison *</label>
                        <select id="cart-delivery-select" data-testid="cart-delivery-select" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                            <?php foreach ($activeDelivMethods as $dm): ?>
                                <?php if (isset($deliveryLabels[$dm])): ?>
                                    <option value="<?= htmlspecialchars((string) $dm, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($deliveryLabels[$dm], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="cart-address-wrapper" class="hidden">
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Adresse postale de livraison *</label>
                        <textarea id="cart-buyer-address" data-testid="cart-buyer-address" rows="2" placeholder="12 rue du Dojo, 75011 Paris" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white"></textarea>
                    </div>

                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 flex items-center justify-between">
                        <span class="text-xs font-bold text-slate-700">Total TTC estimé</span>
                        <span id="cart-total-display" data-testid="cart-total-display" class="text-base font-extrabold text-emerald-700">0,00 €</span>
                    </div>

                    <button type="submit" id="btn-submit-order" data-testid="btn-submit-order"
                            class="w-full py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold uppercase tracking-wider transition">
                        Valider la commande
                    </button>
                </form>

                <div id="cart-order-confirmation" data-testid="cart-order-confirmation" class="hidden p-3 rounded-xl bg-emerald-50 border border-emerald-200 text-xs text-emerald-800 space-y-1"></div>
            </aside>
        </div>

        <!-- =========================================================================
             ONGLET 2 : SUIVI COMMANDES
             ========================================================================= -->
        <section id="boutique-section-orders" class="hidden bg-white rounded-2xl border border-slate-200 p-6 shadow-xs space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-base font-extrabold text-slate-900">📦 Suivi des Commandes Boutique</h2>
                    <p class="text-xs text-slate-500">
                        <?= $canManage ? 'Vue complète du Bureau : suivez les règlements, préparez les commandes et déclenchez la facturation PDF.' : 'Retrouvez l\'état d\'avancement de vos commandes personnelles.' ?>
                    </p>
                </div>
                <button type="button" id="btn-refresh-orders" class="px-3 py-1.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-xs font-semibold text-slate-700 transition">
                    🔄 Actualiser
                </button>
            </div>

            <?php if (!$isAuthenticated): ?>
                <div class="p-6 text-center text-xs text-slate-500">
                    Connectez-vous pour consulter le suivi de vos commandes.
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table id="boutique-orders-table" data-testid="boutique-orders-table" class="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr class="border-b border-slate-200 text-slate-500 uppercase text-[10px]">
                                <th class="py-2 pr-3">Référence</th>
                                <th class="py-2 pr-3">Acheteur</th>
                                <th class="py-2 pr-3">Articles</th>
                                <th class="py-2 pr-3">Paiement / Livraison</th>
                                <th class="py-2 pr-3">Total TTC</th>
                                <th class="py-2 pr-3">Statut</th>
                                <?php if ($canManage): ?>
                                    <th class="py-2">Action Bureau</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="boutique-orders-tbody" class="divide-y divide-slate-100">
                            <tr>
                                <td colspan="<?= $canManage ? 7 : 6 ?>" class="py-6 text-center text-slate-400 italic">Chargement des commandes…</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <!-- =========================================================================
             ONGLET 3 : ADMINISTRATION & PARAMÈTRES (Bureau / Superadmin uniquement)
             ========================================================================= -->
        <?php if ($canManage): ?>
            <section id="boutique-admin-panel" data-testid="boutique-admin-panel" class="hidden bg-white rounded-2xl border border-slate-200 p-6 shadow-xs space-y-6">
                <div class="border-b border-slate-100 pb-3">
                    <span class="text-[11px] font-bold uppercase text-brand-primary">Administration Boutique (Bureau)</span>
                    <h2 class="text-base font-extrabold text-slate-900">Paramètres Paiement / Livraison & Gestion du Catalogue</h2>
                </div>

                <!-- Formulaire Paramètres Paiement Hors-Ligne & Livraison -->
                <form id="boutique-settings-form" data-testid="boutique-settings-form" class="grid grid-cols-1 md:grid-cols-2 gap-4 bg-amber-50/50 p-4 rounded-xl border border-amber-200/80">
                    <div class="md:col-span-2 text-xs font-bold uppercase text-amber-900">💳 Paramétrage des Paiements & Livraisons</div>

                    <div class="space-y-2 bg-white p-3.5 rounded-xl border border-slate-200">
                        <div class="text-xs font-bold text-slate-800">Modes de paiement acceptés</div>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-pay-cheque" data-testid="setting-pay-cheque" <?= in_array('cheque', $activePayMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Chèque à l'ordre du club</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-pay-especes" data-testid="setting-pay-especes" <?= in_array('especes', $activePayMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Espèces au dojo</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-pay-virement" data-testid="setting-pay-virement" <?= in_array('virement', $activePayMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Virement bancaire</span>
                        </label>
                        <label class="flex items-center justify-between gap-2 p-2 rounded-lg bg-slate-100 border border-slate-200 text-xs text-slate-500 cursor-not-allowed">
                            <span class="inline-flex items-center gap-2">
                                <input type="checkbox" id="setting-pay-online-disabled" data-testid="setting-pay-online-disabled" disabled class="rounded border-slate-300">
                                <span>Paiement en ligne (CB / Virement instantané)</span>
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-amber-100 text-amber-800 border border-amber-200">
                                Indisponible pour le moment
                            </span>
                        </label>
                    </div>

                    <div class="space-y-2 bg-white p-3.5 rounded-xl border border-slate-200">
                        <div class="text-xs font-bold text-slate-800">Modes de livraison & frais</div>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-deliv-dojo" data-testid="setting-deliv-dojo" <?= in_array('retrait_dojo', $activeDelivMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Retrait au dojo (Gratuit)</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-deliv-hand" data-testid="setting-deliv-hand" <?= in_array('remise_main_propre', $activeDelivMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Remise en main propre (Gratuit)</span>
                        </label>
                        <label class="flex items-center gap-2 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="setting-deliv-postal" data-testid="setting-deliv-postal" <?= in_array('livraison_postale', $activeDelivMethods, true) ? 'checked' : '' ?> class="rounded border-slate-300">
                            <span>Livraison postale</span>
                        </label>
                        <div class="pt-1">
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Frais de livraison postale (€)</label>
                            <input type="number" step="0.01" min="0" id="setting-postal-fee" data-testid="setting-postal-fee"
                                   value="<?= number_format($postalFee, 2, '.', '') ?>"
                                   class="w-40 px-3 py-1.5 border border-slate-300 rounded-xl text-xs bg-white">
                        </div>
                    </div>

                    <div class="md:col-span-2 flex justify-end">
                        <button type="submit" id="btn-save-boutique-settings" data-testid="btn-save-boutique-settings" class="px-5 py-2 rounded-xl bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold uppercase tracking-wider transition">
                            Enregistrer les paramètres Boutique
                        </button>
                    </div>
                </form>

                <!-- Formulaire Ajout Produit (Sprint 25) -->
                <form id="boutique-create-product-form" data-testid="boutique-create-product-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <div class="sm:col-span-2 lg:col-span-4 text-xs font-bold uppercase text-slate-700">➕ Ajouter un produit au catalogue</div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nom du produit *</label>
                        <input type="text" id="prod-name-input" data-testid="prod-name-input" required placeholder="Ex: Sweat Club Capuche" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Catégorie</label>
                        <input type="text" id="prod-category-input" data-testid="prod-category-input" value="Tenues" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Prix public (€) *</label>
                        <input type="number" step="0.01" min="0" id="prod-price-input" data-testid="prod-price-input" required value="35.00" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Disponibilité</label>
                        <select id="prod-availability-select" data-testid="prod-availability-select" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                            <option value="en_stock">En stock</option>
                            <option value="sur_commande">Sur commande</option>
                            <option value="rupture">Rupture</option>
                            <option value="masque">Masqué</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Remise adhérent</label>
                        <select id="prod-discount-type-select" data-testid="prod-discount-type-select" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                            <option value="none">Aucune</option>
                            <option value="percent">Pourcentage (%)</option>
                            <option value="fixed">Montant fixe (€)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Valeur remise</label>
                        <input type="number" step="0.01" min="0" id="prod-discount-value-input" data-testid="prod-discount-value-input" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Tailles & stocks (ex: S:5, M:10, L:8)</label>
                        <input type="text" id="prod-sizes-input" data-testid="prod-sizes-input" placeholder="S:5, M:10, L:8" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Supplément flocage (€)</label>
                        <input type="number" step="0.01" min="0" id="prod-flocking-price-input" data-testid="prod-flocking-price-input" value="0" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div class="sm:col-span-2 flex flex-wrap items-center gap-4 pt-1">
                        <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="prod-members-only-check" data-testid="prod-members-only-check" class="rounded border-slate-300">
                            <span>Réservé aux adhérents uniquement</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="prod-flocking-check" data-testid="prod-flocking-check" class="rounded border-slate-300">
                            <span>Autoriser le flocage personnalisé</span>
                        </label>
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit" id="btn-create-product" data-testid="btn-create-product" class="px-5 py-2 rounded-xl bg-brand-primary hover:opacity-90 text-white text-xs font-bold uppercase tracking-wider transition">
                            Ajouter le produit
                        </button>
                    </div>
                </form>

                <!-- Formulaire Création de Lot / Pack (Sprint 25) -->
                <form id="boutique-create-bundle-form" data-testid="boutique-create-bundle-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 bg-indigo-50/50 p-4 rounded-xl border border-indigo-200/70">
                    <div class="sm:col-span-2 lg:col-span-4 text-xs font-bold uppercase text-indigo-800">🎁 Créer un Lot / Pack (articles existants et/ou exclusifs)</div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Nom du lot *</label>
                        <input type="text" id="bundle-name-input" data-testid="bundle-name-input" required placeholder="Ex: Pack Rentrée Dojo" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Prix du lot (€) *</label>
                        <input type="number" step="0.01" min="0" id="bundle-price-input" data-testid="bundle-price-input" required value="55.00" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Article catalogue inclus</label>
                        <select id="bundle-existing-product-select" data-testid="bundle-existing-product-select" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                            <option value="">— Aucun article existant —</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Article exclusif au lot (optionnel)</label>
                        <input type="text" id="bundle-custom-item-input" data-testid="bundle-custom-item-input" placeholder="Ex: Sac Dojo Exclusif" class="w-full px-3 py-2 border border-slate-300 rounded-xl text-xs bg-white">
                    </div>
                    <div class="sm:col-span-2 flex items-center">
                        <label class="inline-flex items-center gap-1.5 text-xs text-slate-700 cursor-pointer">
                            <input type="checkbox" id="bundle-members-only-check" data-testid="bundle-members-only-check" class="rounded border-slate-300">
                            <span>Lot réservé aux adhérents uniquement</span>
                        </label>
                    </div>
                    <div class="sm:col-span-2 flex justify-end">
                        <button type="submit" id="btn-create-bundle" data-testid="btn-create-bundle" class="px-5 py-2 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-bold uppercase tracking-wider transition">
                            Créer le lot
                        </button>
                    </div>
                </form>
            </section>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require dirname(__DIR__, 3) . '/Core/Ui/Templates/layout.php';
