<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Page Publique Horaires & Tarifs Dynamique (Point 32).
 * Accessible sur /horaires sans authentification requise.
 */

use Core\Api\Services\ScheduleService;

$pageTitle   = 'Horaires des Cours & Tarifs';
$activeNav   = 'horaires';
$requireAuth = false;

$scheduleData    = (new ScheduleService())->getPublicScheduleData();
$disciplines     = $scheduleData['disciplines'];
$cmsContent      = $scheduleData['cms_content'];
$basePrice       = $scheduleData['base_price'];
$defaultCapacity = $scheduleData['default_capacity'];

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

    <!-- Barre d'Onglets par Discipline (1 seule discipline visible à la fois - Point 12) -->
    <?php if (!empty($disciplines)): ?>
        <div id="horaires-discipline-tabs" data-testid="discipline-tabs" role="tablist" aria-label="Disciplines" class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-slate-200">
            <?php foreach ($disciplines as $idx => $disc): ?>
                <button type="button"
                        role="tab"
                        aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>"
                        data-testid="discipline-tab-item"
                        data-discipline-id="<?= (int) $disc['id'] ?>"
                        class="horaires-disc-tab-btn px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 <?= $idx === 0 ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>">
                    <span>🥋 <?= htmlspecialchars((string) $disc['name']) ?></span>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-mono <?= $idx === 0 ? 'bg-black/20 text-white' : 'bg-slate-100 text-slate-600' ?>">
                        <?= number_format((float) $disc['price'], 0, ',', ' ') ?> €
                    </span>
                </button>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Affichage de la Discipline sélectionnée -->
    <div class="space-y-6" id="schedules-grid">
        <?php foreach ($disciplines as $idx => $disc): ?>
            <div class="horaires-disc-card bg-white rounded-2xl shadow-xs border border-slate-200 p-6 space-y-4 hover:shadow-md transition <?= $idx > 0 ? 'hidden' : '' ?>"
                 role="tabpanel"
                 data-discipline-id="<?= (int) $disc['id'] ?>"
                 data-testid="discipline-panel-<?= (int) $disc['id'] ?>">
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
                    <a href="/register?discipline=<?= (int) $disc['id'] ?>"
                       data-testid="horaires-register-discipline-btn"
                       data-discipline-id="<?= (int) $disc['id'] ?>"
                       class="bg-brand-primary text-white px-4 py-2 rounded-xl text-xs font-bold shadow-xs transition">
                        S'inscrire à cette discipline →
                    </a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <script>
        (function () {
            const buttons = Array.from(document.querySelectorAll('.horaires-disc-tab-btn'));
            const cards = Array.from(document.querySelectorAll('.horaires-disc-card'));
            if (!buttons.length || !cards.length) return;

            buttons.forEach((btn) => {
                btn.addEventListener('click', () => {
                    const targetId = btn.getAttribute('data-discipline-id');
                    buttons.forEach((b) => {
                        const isSel = b.getAttribute('data-discipline-id') === targetId;
                        b.setAttribute('aria-selected', isSel ? 'true' : 'false');
                        b.className = 'horaires-disc-tab-btn px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 ' + (
                            isSel ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50'
                        );
                        const badge = b.querySelector('span:last-child');
                        if (badge) {
                            badge.className = 'px-2 py-0.5 rounded-full text-[10px] font-mono ' + (
                                isSel ? 'bg-black/20 text-white' : 'bg-slate-100 text-slate-600'
                            );
                        }
                    });
                    cards.forEach((c) => {
                        c.classList.toggle('hidden', c.getAttribute('data-discipline-id') !== targetId);
                    });
                });
            });
        })();
    </script>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
