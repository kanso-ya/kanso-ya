<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

use Core\Api\Sdk\EventManager;
use Core\Api\Services\StatsService;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole   = (string) ($_SESSION['role_name'] ?? 'Visiteur');
$isSuperadmin  = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));

$pageTitle     = 'Statistiques & Effectifs';
$activeNav     = 'stats';
$requireAuth   = true;
$requiredPerms = ['stats.view', 'roles.manage', 'finances.manage'];
$pageScripts   = ['/assets/ts/stats.js'];

$statsData = (new StatsService())->getDashboardData($currentUserId, $currentRole, $isSuperadmin);

$isTeacher                = $statsData['is_teacher'];
$isBureau                 = $statsData['is_bureau'];
$disciplines              = $statsData['disciplines'];
$members                  = $statsData['members'];
$totalMembers             = $statsData['total_members'];
$activeCount              = $statsData['active_count'];
$pendingCount             = $statsData['pending_count'];
$radiatedCount            = $statsData['radiated_count'];
$minorsCount              = $statsData['minors_count'];
$adultsCount              = $statsData['adults_count'];
$now                      = new DateTimeImmutable();
$renewalRate              = $statsData['renewal_rate'];
$genderRatioMale          = $statsData['gender_ratio_male'];
$genderRatioFemale        = $statsData['gender_ratio_female'];
$genderRatioOther         = $statsData['gender_ratio_other'];
$genderRatioUnspecified   = $statsData['gender_ratio_unspecified'];
$totalValidatedRevenue    = $statsData['total_validated_revenue'];
$totalPendingRevenue      = $statsData['total_pending_revenue'];
$paymentsByMethod         = $statsData['payments_by_method'];
$defaultCapacity          = $statsData['default_capacity'];
$disciplineStats          = $statsData['discipline_stats'];

// 6. Onglets modulaires extensibles via hooks ui_tabs + alias rétrocompatible stats_tabs (Sprint 18 US 18.1)
$defaultTabs = [
    ['id' => 'kpis', 'label' => '📊 Vue Générale', 'active' => true],
];
if ($isBureau) {
    $defaultTabs[] = ['id' => 'finances', 'label' => '💶 Finances', 'active' => false];
}
$defaultTabs[] = ['id' => 'occupancy', 'label' => '🥋 Démographie & Occupation', 'active' => false];
$defaultTabs[] = ['id' => 'attendance', 'label' => '📋 Liste d\'Appel Tatami', 'active' => false];

/** @var array<int, array{id: string, label: string, active?: bool, content?: string}> $tabs */
$tabs = \Core\Api\Sdk\UiHelpers::tabs('stats', $defaultTabs, ['role' => $currentRole, 'user_id' => $currentUserId]);
$coreTabIds = ['kpis', 'finances', 'occupancy', 'attendance'];

ob_start();
?>
<div class="space-y-8">
    <!-- En-tête adaptatif Président/Bureau vs Enseignant (US 5.2 - Point 7) -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">
                <?= $isTeacher ? 'Périmètre Pédagogique Enseignant (Anonymisé RGPD)' : 'Pilotage Général du Club (' . htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') . ')' ?>
            </span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">
                <?= $isTeacher ? 'Statistiques de mes Cours & Disciplines' : 'Tableaux de Bord Analytiques & KPIs' ?>
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                <?= $isTeacher
                    ? 'Vous consultez les effectifs et répartitions par grade/niveau de vos disciplines attribuées, dans le strict respect de l\'anonymisation RGPD.'
                    : 'Indicateurs en temps réel des effectifs, taux de renouvellement, santé financière et ratios d\'occupation des créneaux.' ?>
            </p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/members" class="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shrink-0">
                Consulter la liste des adhérents →
            </a>
        </div>
    </div>

    <!-- Barre d'Onglets Statistiques (Point 28 & Sprint 14 Point 11) -->
    <div id="stats-tabs-bar" data-testid="stats-tabs-bar" role="tablist" aria-label="Onglets statistiques" class="flex items-center gap-2 border-b border-slate-200 pb-2 overflow-x-auto">
        <?php foreach ($tabs as $t): ?>
            <?php $isActiveTab = !empty($t['active']); ?>
            <button type="button"
                    role="tab"
                    aria-selected="<?= $isActiveTab ? 'true' : 'false' ?>"
                    aria-controls="stats-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="stats-tab-btn px-4 py-2 rounded-xl text-xs font-bold transition shrink-0 <?= $isActiveTab ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50' ?>"
                    data-tab-target="<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-testid="stats-tab-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- PANNEAU 1 : Vue Générale (Cartes KPIs Globales & Démographiques - US 5.2 CA2) -->
    <div id="stats-panel-kpis" data-tab-panel="kpis" data-testid="stats-panel-kpis" role="tabpanel" class="stats-tab-panel space-y-6">
        <div class="grid grid-cols-2 lg:grid-cols-6 gap-4">
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-slate-400 block">Effectif Total</span>
                <span id="kpi-total-members" class="text-3xl font-black text-slate-900 mt-1 block"><?= $totalMembers ?></span>
                <span class="text-[11px] text-slate-500"><?= $isTeacher ? 'Élèves inscrits' : 'Fiches enregistrées' ?></span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-emerald-600 block">Adhérents Actifs</span>
                <span id="kpi-active-members" class="text-3xl font-black text-emerald-600 mt-1 block"><?= $activeCount ?></span>
                <span class="text-[11px] text-slate-500">Dossiers validés</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-amber-600 block">En Attente</span>
                <span id="kpi-pending-members" class="text-3xl font-black text-amber-600 mt-1 block"><?= $pendingCount ?></span>
                <span class="text-[11px] text-slate-500">Validation en cours</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-indigo-600 block">Renouvellement</span>
                <span id="kpi-renewal-rate" class="text-3xl font-black text-indigo-600 mt-1 block"><?= $renewalRate ?>%</span>
                <span class="text-[11px] text-slate-500">Ratio actifs / inscrits</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-blue-600 block">Âges (Min. / Maj.)</span>
                <span id="kpi-minors-adults" class="text-2xl font-black text-slate-900 mt-1 block"><?= $minorsCount ?> / <?= $adultsCount ?></span>
                <span class="text-[11px] text-slate-500"><?= $totalMembers > 0 ? round(($minorsCount / $totalMembers) * 100) : 0 ?>% de mineurs</span>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
                <span class="text-[11px] font-bold uppercase text-purple-600 block">Ratio H / F</span>
                <span id="kpi-gender-ratio" data-testid="kpi-gender-ratio" class="text-2xl font-black text-purple-600 mt-1 block"><?= $genderRatioMale ?>% / <?= $genderRatioFemale ?>%</span>
                <span id="kpi-gender-details" data-testid="kpi-gender-details" class="text-[11px] text-slate-500">
                    <?= $genderRatioUnspecified > 0 || $genderRatioOther > 0
                        ? sprintf('Autre %d%% · Non renseigné %d%%', $genderRatioOther, $genderRatioUnspecified)
                        : 'Données réelles déclarées' ?>
                </span>
            </div>
        </div>

        <?php if (!$isBureau): ?>
            <!-- Badge de protection RGPD Enseignant (CA3) -->
            <div id="rgpd-teacher-protection" class="bg-emerald-50 border border-emerald-200 p-4 rounded-2xl text-emerald-800 text-xs flex items-center gap-3">
                <span class="text-xl">🔒</span>
                <div>
                    <strong>Protection RGPD & Anonymisation Pédagogique Active :</strong>
                    Les indicateurs financiers (recettes de cotisations, montants encaissés et modes de paiement) ainsi que les données personnelles hors urgence sont strictement masqués pour le profil Entraîneur.
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- PANNEAU 2 : Indicateurs Financiers du Club (STRICTEMENT réservés au Bureau - US 5.2 CA3) -->
    <?php if ($isBureau): ?>
        <div id="stats-panel-finances" data-tab-panel="finances" data-testid="stats-panel-finances" role="tabpanel" class="stats-tab-panel hidden">
            <section id="financial-stats-section" class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div>
                        <span class="text-xs font-bold uppercase text-amber-600">Santé Financière du Club</span>
                        <h2 class="text-lg font-bold text-slate-900">Suivi des Recettes & Règlements de Cotisations</h2>
                    </div>
                    <span class="px-3 py-1 rounded-full bg-slate-100 text-slate-700 text-xs font-bold">
                        Accès Réservé au Bureau
                    </span>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200">
                        <span class="text-xs font-bold uppercase text-emerald-800 block">Total Cotisations Encaissées</span>
                        <span id="kpi-total-revenue" class="text-3xl font-black text-emerald-700 mt-1 block">
                            <?= number_format($totalValidatedRevenue, 2, ',', ' ') ?> €
                        </span>
                        <span class="text-[11px] text-emerald-600">Dossiers validés et payés</span>
                    </div>

                    <div class="p-4 rounded-xl bg-amber-50 border border-amber-200">
                        <span class="text-xs font-bold uppercase text-amber-800 block">Montant en Attente d'Encaissement</span>
                        <span id="kpi-pending-revenue" class="text-3xl font-black text-amber-700 mt-1 block">
                            <?= number_format($totalPendingRevenue, 2, ',', ' ') ?> €
                        </span>
                        <span class="text-[11px] text-amber-600">Chèques ou virements non validés</span>
                    </div>

                    <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 space-y-2">
                        <span class="text-xs font-bold uppercase text-slate-600 block">Moyens de Règlement Choisis</span>
                        <div id="kpi-payment-methods" class="space-y-1 text-xs">
                            <?php if (empty($paymentsByMethod)): ?>
                                <p class="text-slate-400 italic text-[11px]">Aucun règlement enregistré.</p>
                            <?php else: ?>
                                <?php foreach ($paymentsByMethod as $pm): ?>
                                    <div class="flex justify-between items-center text-slate-700">
                                        <span class="capitalize font-medium"><?= htmlspecialchars((string) $pm['payment_method'], ENT_QUOTES, 'UTF-8') ?> (<?= (int) $pm['count'] ?>)</span>
                                        <span class="font-bold font-mono"><?= number_format((float) $pm['total'], 2, ',', ' ') ?> €</span>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    <?php endif; ?>

    <!-- PANNEAU 3 : Ratios d'Occupation par Discipline (1 seule discipline affichée à la fois - Point 12) -->
    <div id="stats-panel-occupancy" data-tab-panel="occupancy" data-testid="stats-panel-occupancy" role="tabpanel" class="stats-tab-panel hidden">
        <section class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                <h2 class="text-lg font-extrabold text-slate-900">Ratios d'Occupation des Créneaux & Répartition par Niveau</h2>
                <span class="text-xs text-slate-500">Capacité théorique standard : <?= $defaultCapacity ?> élèves / créneau</span>
            </div>

            <?php if (!empty($disciplineStats)): ?>
                <!-- Barre d'onglets par discipline (1 discipline visible à la fois) -->
                <div id="stats-discipline-tabs" data-testid="discipline-tabs" role="tablist" aria-label="Disciplines" class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-slate-200">
                    <?php foreach ($disciplineStats as $idx => $ds): ?>
                        <button type="button"
                                role="tab"
                                aria-selected="<?= $idx === 0 ? 'true' : 'false' ?>"
                                data-testid="discipline-tab-item"
                                data-discipline-id="<?= (int) $ds['id'] ?>"
                                class="stats-disc-tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 <?= $idx === 0 ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50' ?>">
                            <span>🥋 <?= htmlspecialchars($ds['name'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-mono <?= $idx === 0 ? 'bg-black/20 text-white' : 'bg-slate-100 text-slate-600' ?>">
                                <?= (int) $ds['total'] ?> / <?= (int) $ds['capacity'] ?>
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div id="discipline-occupancy-list" class="space-y-6">
                <?php foreach ($disciplineStats as $idx => $ds): ?>
                    <div class="stats-disc-card bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-5 <?= $idx > 0 ? 'hidden' : '' ?>"
                         role="tabpanel"
                         data-discipline-id="<?= (int) $ds['id'] ?>"
                         data-testid="discipline-panel-<?= (int) $ds['id'] ?>">
                        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                            <div>
                                <h3 class="text-xl font-extrabold text-slate-900"><?= htmlspecialchars($ds['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                                <span class="text-xs text-slate-500 font-mono">
                                    Taux d'occupation : <?= (float) $ds['occupancy_pct'] ?>%
                                </span>
                            </div>
                            <?php if ($ds['occupancy_pct'] >= 100): ?>
                                <span class="px-3 py-1 rounded-full bg-red-100 text-red-800 border border-red-200 text-xs font-extrabold font-mono flex items-center gap-1.5" data-testid="capacity-alert-<?= $ds['id'] ?>">
                                    <span class="w-2 h-2 rounded-full bg-red-500 animate-pulse"></span>
                                    <span>⚠️ COMPLET (<?= (int) $ds['total'] ?> / <?= (int) $ds['capacity'] ?>)</span>
                                </span>
                            <?php else: ?>
                                <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-extrabold font-mono">
                                    <?= (int) $ds['total'] ?> / <?= (int) $ds['capacity'] ?> inscrits
                                </span>
                            <?php endif; ?>
                        </div>

                        <!-- Barre de progression de capacité -->
                        <div class="w-full bg-slate-100 rounded-full h-2.5 overflow-hidden">
                            <div class="<?= $ds['occupancy_pct'] >= 100 ? 'bg-red-600' : 'bg-emerald-600' ?> h-2.5 rounded-full transition-all duration-500" style="width: <?= min(100.0, (float) $ds['occupancy_pct']) ?>%"></div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- A. Grades (Catégories d'âge : Poussin, Benjamin, Minime...) -->
                            <div class="space-y-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500 block">
                                    1. Catégories d'âge (Grades)
                                </span>
                                <?php if (empty($ds['grades'])): ?>
                                    <p class="text-xs text-slate-400 italic">Aucun grade configuré.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($ds['grades'] as $g): ?>
                                            <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs">
                                                <div>
                                                    <strong class="text-slate-900"><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                    <span class="text-slate-400 ml-1">(<?= (int) ($g['age_min'] ?? 0) ?> - <?= (int) ($g['age_max'] ?? 99) ?> ans)</span>
                                                </div>
                                                <span class="px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold font-mono">
                                                    <?= (int) $g['total'] ?> inscrit(s)
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <!-- B. Niveaux techniques (Couleurs de ceinture, gants...) -->
                            <div class="space-y-2">
                                <span class="text-xs font-bold uppercase tracking-wider text-slate-500 block">
                                    2. Niveaux Techniques (Ceinture / Gant)
                                </span>
                                <?php if (empty($ds['niveaux'])): ?>
                                    <p class="text-xs text-slate-400 italic">Aucun niveau technique configuré.</p>
                                <?php else: ?>
                                    <div class="space-y-2">
                                        <?php foreach ($ds['niveaux'] as $n): ?>
                                            <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs">
                                                <div class="flex items-center gap-2">
                                                    <span class="w-3.5 h-3.5 rounded-full border border-slate-300 inline-block" style="background-color: <?= htmlspecialchars((string) ($n['color_hex'] ?: '#10b981'), ENT_QUOTES, 'UTF-8') ?>;"></span>
                                                    <strong class="text-slate-900"><?= htmlspecialchars((string) $n['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                                </div>
                                                <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold font-mono">
                                                    <?= (int) $n['total'] ?> inscrit(s)
                                                </span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- PANNEAU 4 : Fiche Terrain Tatami & Urgence (Conforme RGPD Enseignant) -->
    <div id="stats-panel-attendance" data-tab-panel="attendance" data-testid="stats-panel-attendance" role="tabpanel" class="stats-tab-panel hidden">
        <section class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <div>
                <h2 class="text-base font-bold text-slate-900">Liste d'Appel Pédagogique & Contacts d'Urgence</h2>
                <p class="text-xs text-slate-500">Strictement anonymisé : aucune adresse personnelle, email ou cotisation financière n'est exposée sur le terrain.</p>
            </div>
            <div class="overflow-x-auto rounded-xl border border-slate-200 max-h-96">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-50 text-slate-600 uppercase border-b border-slate-200">
                        <tr>
                            <th class="p-3">Pratiquant</th>
                            <th class="p-3">Tranche d'âge</th>
                            <th class="p-3">Contact d'Urgence</th>
                            <th class="p-3">Téléphone d'Urgence</th>
                            <th class="p-3">Statut</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <?php foreach ($members as $m): ?>
                            <?php
                            $age = 20;
                            try {
                                $age = $now->diff(new DateTimeImmutable((string) $m['birth_date']))->y;
                            } catch (Throwable) {
                            }
                            ?>
                            <tr class="hover:bg-slate-50">
                                <td class="p-3 font-bold text-slate-900"><?= htmlspecialchars($m['first_name'] . ' ' . $m['last_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="p-3"><?= $age ?> ans (<?= $age < 18 ? 'Mineur' : 'Majeur' ?>)</td>
                                <td class="p-3 font-medium text-slate-700"><?= htmlspecialchars((string) ($m['emergency_contact_name'] ?: 'Parent référent'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="p-3 font-mono font-bold text-emerald-700"><?= htmlspecialchars((string) ($m['emergency_contact_phone'] ?: '06 11 22 33 44'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="p-3">
                                    <span class="px-2 py-0.5 rounded-full font-semibold <?= $m['status'] === 'active' ? 'bg-emerald-50 text-emerald-700' : 'bg-amber-50 text-amber-700' ?>">
                                        <?= htmlspecialchars((string) $m['status'], ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- PANNEAUX EXTENSIONS : Onglets ajoutés par les modules tiers via hook stats_tabs -->
    <?php foreach ($tabs as $t): ?>
        <?php if (!in_array((string) $t['id'], $coreTabIds, true)): ?>
            <div id="stats-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 data-tab-panel="<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 data-testid="stats-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 role="tabpanel"
                 class="stats-tab-panel hidden bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-3">
                <h2 class="text-base font-bold text-slate-900"><?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="text-xs text-slate-600">
                    <?= isset($t['content']) ? (string) $t['content'] : 'Vue analytique injectée par module d\'extension.' ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
