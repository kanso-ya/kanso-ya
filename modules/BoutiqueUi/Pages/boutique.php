<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Page publique / Espace Membre : Boutique Officielle du Club (Module Démonstrateur V2).
 */

use Core\Api\Database\Database;

$pageTitle = 'Boutique Officielle du Club';
$activeNav = 'boutique';
$requireAuth = false;

$products = [];
$tableExists = false;

try {
    $db = Database::getInstance();
    $checkStmt = $db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='mod_boutique_products'");
    if ($checkStmt !== false && $checkStmt->fetch() !== false) {
        $tableExists = true;
        $prodStmt = $db->query("SELECT * FROM mod_boutique_products ORDER BY id ASC");
        if ($prodStmt !== false) {
            $products = $prodStmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable) {
}

ob_start();
?>
<link rel="stylesheet" href="/assets/modules/boutique/boutique.css">
<div class="space-y-6">
    <!-- En-tête de la Boutique -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 rounded-2xl border border-slate-200 shadow-sm">
        <div>
            <div class="flex items-center gap-2">
                <span class="text-2xl">🛍️</span>
                <h1 class="text-xl font-extrabold text-slate-900">Boutique Officielle du Club</h1>
            </div>
            <p class="text-xs text-slate-500 mt-1">Équipements certifiés, kimonos brodés et goodies aux couleurs de Kanso-ya.</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="boutique-status-badge px-3 py-1.5 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 flex items-center gap-1.5">
                <span class="boutique-status-dot w-2 h-2 rounded-full bg-emerald-500"></span>
                <span>Module Démonstrateur Actif</span>
            </span>
        </div>
    </div>

    <?php if (!$tableExists): ?>
        <div class="bg-amber-50 border border-amber-200 rounded-2xl p-6 text-center text-amber-800 space-y-2">
            <div class="text-2xl">⚠️</div>
            <div class="font-bold">Le module Boutique n'est pas encore installé ou actif.</div>
            <p class="text-xs text-amber-700">Rendez-vous dans la console d'administration pour activer le module et créer la table <code>mod_boutique_products</code>.</p>
        </div>
    <?php else: ?>
        <div id="boutique-products-grid" data-testid="boutique-products-grid" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
            <?php foreach ($products as $prod): ?>
                <div class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs hover:shadow-md transition flex flex-col justify-between space-y-4" data-testid="product-card-<?= (int) $prod['id'] ?>">
                    <div class="space-y-2">
                        <div class="flex justify-between items-start gap-2">
                            <span class="px-2.5 py-1 rounded-lg text-[10px] font-bold uppercase tracking-wider bg-slate-100 text-slate-600">
                                <?= htmlspecialchars((string) ($prod['category'] ?? 'Équipement'), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="text-sm font-extrabold text-emerald-600">
                                <?= number_format((float) $prod['price'], 2, ',', ' ') ?> €
                            </span>
                        </div>
                        <h2 class="font-bold text-slate-900 text-base leading-snug">
                            <?= htmlspecialchars((string) $prod['name'], ENT_QUOTES, 'UTF-8') ?>
                        </h2>
                    </div>

                    <div class="pt-3 border-t border-slate-100 flex items-center justify-between">
                        <div class="text-xs text-slate-500">
                            Stock : <strong class="text-slate-800"><?= (int) $prod['stock'] ?> ex.</strong>
                        </div>
                        <button type="button" class="px-3.5 py-2 rounded-xl brand-primary-bg hover:opacity-90 text-white text-xs font-semibold shadow-xs transition">
                            Commander
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require dirname(__DIR__, 3) . '/Core/Ui/Templates/layout.php';
