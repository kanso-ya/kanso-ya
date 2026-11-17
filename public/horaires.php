<?php

declare(strict_types=1);

/**
 * Page Publique Horaires & Tarifs Dynamique (Point 32).
 * Accessible sur /horaires sans authentification requise.
 */
require_once __DIR__ . '/../core/Api/Database/Database.php';

use Core\Api\Database\Database;

$pageTitle   = 'Horaires des Cours & Tarifs';
$activeNav   = 'horaires';
$requireAuth = false;

$disciplines = [];
$cmsContent  = null;
$basePrice   = 150.0;

try {
    $db = Database::getInstance();
    
    // Récupération du tarif de base dans sys_settings
    $stmtSettings = $db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'membership_base_price' LIMIT 1");
    if ($stmtSettings) {
        $val = $stmtSettings->fetchColumn();
        if ($val !== false) {
            $basePrice = (float) $val;
        }
    }

    // 1. Récupération des disciplines avec leurs cours (grades)
    $stmtDisc = $db->query("
        SELECT d.id, d.name,
               COUNT(DISTINCT mp.member_id) AS enrolled_count
        FROM disciplines d
        LEFT JOIN member_practices mp ON d.id = mp.discipline_id
        GROUP BY d.id
        ORDER BY d.id ASC
    ");
    $disciplines = $stmtDisc ? $stmtDisc->fetchAll(PDO::FETCH_ASSOC) : [];

    foreach ($disciplines as &$d) {
        $d['price'] = $basePrice;
        $d['max_capacity'] = 30; // Capacité standard du club
        $stmtLvl = $db->prepare("
            SELECT name, level_type, color_hex, age_min, age_max
            FROM levels
            WHERE discipline_id = :did
            ORDER BY age_min ASC, id ASC
        ");
        $stmtLvl->execute(['did' => $d['id']]);
        $d['levels'] = $stmtLvl->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($d);

    // 2. Vérification d'un texte personnalisé dans pages (CMS)
    $stmtPage = $db->prepare("SELECT title, content, status FROM pages WHERE slug = 'horaires' LIMIT 1");
    $stmtPage->execute();
    $cmsPage = $stmtPage->fetch(PDO::FETCH_ASSOC);
    if ($cmsPage && $cmsPage['status'] === 'published') {
        $cmsContent = $cmsPage;
    }
} catch (Throwable) {
}

ob_start();
?>
<div class="space-y-8 max-w-5xl mx-auto">
    <!-- En-tête -->
    <div class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 text-center space-y-2">
        <span class="text-xs font-bold uppercase tracking-wider text-emerald-600">Saison Sportive & Entraînements</span>
        <h1 class="text-3xl font-extrabold text-slate-900"><?= htmlspecialchars($cmsContent['title'] ?? 'Horaires des Cours & Grille Tarifaire') ?></h1>
        <p class="text-sm text-slate-500 max-w-2xl mx-auto">
            Retrouvez ci-dessous l'ensemble des créneaux, catégories d'âge et tarifs annuels de nos disciplines.
        </p>
    </div>

    <!-- Contenu additionnel éventuel issu du CMS -->
    <?php if ($cmsContent && !empty($cmsContent['content'])): ?>
        <div class="bg-emerald-50/70 border border-emerald-200 rounded-2xl p-6 text-sm text-emerald-950 prose max-w-none">
            <?= $cmsContent['content'] ?>
        </div>
    <?php endif; ?>

    <!-- Grille des Disciplines & Horaires -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-6" id="schedules-grid">
        <?php foreach ($disciplines as $disc): ?>
            <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 space-y-4 hover:shadow-md transition">
                <div class="flex items-start justify-between border-b border-slate-100 pb-3">
                    <div>
                        <span class="text-xs font-bold uppercase text-emerald-600">🥋 Discipline</span>
                        <h2 class="text-xl font-black text-slate-900 mt-0.5"><?= htmlspecialchars((string) $disc['name']) ?></h2>
                    </div>
                    <div class="text-right">
                        <span class="text-lg font-black text-slate-900"><?= number_format((float) $disc['price'], 2, ',', ' ') ?> €</span>
                        <span class="text-[11px] text-slate-400 block">/ saison</span>
                    </div>
                </div>

                <!-- Capacité & Remplissage -->
                <div class="flex items-center justify-between text-xs text-slate-600 bg-slate-50 p-2.5 rounded-xl">
                    <span>Places disponibles :</span>
                    <strong class="text-slate-900 font-bold">
                        <?= max(0, (int) $disc['max_capacity'] - (int) $disc['enrolled_count']) ?> restantes
                        <span class="font-normal text-slate-400">(Capacité : <?= (int) $disc['max_capacity'] ?>)</span>
                    </strong>
                </div>

                <!-- Cours & Catégories d'âge -->
                <div class="space-y-2">
                    <span class="text-xs font-bold uppercase tracking-wider text-slate-400 block">Créneaux & Cours (Grades d'âge)</span>
                    <div class="space-y-1.5">
                        <?php
                        $grades = array_filter($disc['levels'] ?? [], fn($l) => ($l['level_type'] ?? 'grade') === 'grade');
                        if (empty($grades)): ?>
                            <div class="text-xs text-slate-500 italic p-2 bg-slate-50 rounded-lg">Tous âges & tous niveaux confondus</div>
                        <?php else: ?>
                            <?php foreach ($grades as $g): ?>
                                <div class="flex items-center justify-between p-2 rounded-lg bg-slate-50 border border-slate-100 text-xs">
                                    <span class="font-bold text-slate-800">🥋 <?= htmlspecialchars((string) $g['name']) ?></span>
                                    <span class="text-slate-500 font-mono text-[11px]">
                                        <?= (int) ($g['age_min'] ?? 0) ?> à <?= (int) ($g['age_max'] ?? 99) ?> ans
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="pt-2 border-t border-slate-100 flex justify-end">
                    <a href="/register" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-xs transition">
                        S'inscrire à cette discipline →
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
