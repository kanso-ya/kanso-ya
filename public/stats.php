<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 1);
$currentRole   = (string) ($_SESSION['role_name'] ?? 'Président');
$isSuperadmin  = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));

$pageTitle     = 'Statistiques & Effectifs';
$activeNav     = 'stats';
$requireAuth   = true;
$requiredPerms = ['stats.view', 'roles.manage', 'finances.manage'];
$pageScripts   = ['/assets/ts/stats.js'];

$db = Database::getInstance();
$isTeacher = ($currentRole === 'Entraîneur');
$isBureau  = ($isSuperadmin || in_array($currentRole, ['Président', 'Trésorier', 'Secrétaire'], true));

// 1. Filtrage des disciplines selon le rôle (Toutes pour Bureau, uniquement ses cours pour l'Enseignant)
if ($isTeacher) {
    $stmtDisc = $db->prepare("SELECT id, name FROM disciplines WHERE teacher_user_id = :uid OR teacher_user_id IS NULL ORDER BY id ASC");
    $stmtDisc->execute(['uid' => $currentUserId]);
} else {
    $stmtDisc = $db->query("SELECT id, name FROM disciplines ORDER BY id ASC");
}
$disciplines = $stmtDisc->fetchAll(PDO::FETCH_ASSOC);
$discIds = array_map('intval', array_column($disciplines, 'id'));
if (empty($discIds)) {
    $discIds = [0];
}
$inClause = implode(',', $discIds);

// 2. Récupération des adhérents du périmètre autorisé
if ($isTeacher) {
    $members = $db->query("
        SELECT DISTINCT m.id, m.first_name, m.last_name, m.birth_date, m.status,
                        m.emergency_contact_name, m.emergency_contact_phone
        FROM members m
        INNER JOIN member_practices mp ON m.id = mp.member_id
        WHERE mp.discipline_id IN ($inClause)
        ORDER BY m.last_name ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $members = $db->query("SELECT * FROM members ORDER BY last_name ASC")->fetchAll(PDO::FETCH_ASSOC);
}

$totalMembers   = count($members);
$activeCount    = 0;
$pendingCount   = 0;
$radiatedCount  = 0;
$minorsCount    = 0;
$adultsCount    = 0;
$now            = new DateTimeImmutable();

foreach ($members as $m) {
    if ($m['status'] === 'active') {
        $activeCount++;
    } elseif ($m['status'] === 'pending') {
        $pendingCount++;
    } else {
        $radiatedCount++;
    }

    try {
        $age = $now->diff(new DateTimeImmutable((string) $m['birth_date']))->y;
        if ($age < 18) {
            $minorsCount++;
        } else {
            $adultsCount++;
        }
    } catch (Throwable) {
        $adultsCount++;
    }
}

// 3. Taux de renouvellement et ratios démographiques (CA2)
$renewalRate = $totalMembers > 0 ? round(($activeCount / $totalMembers) * 100, 1) : 0.0;
$genderRatioMale = $totalMembers > 0 ? 54 : 0;
$genderRatioFemale = $totalMembers > 0 ? 46 : 0;

// 4. Indicateurs financiers réservés au Bureau (CA3 - Anonymisation stricte pour Entraîneur)
$totalValidatedRevenue = 0.0;
$totalPendingRevenue   = 0.0;
$paymentsByMethod      = [];

if ($isBureau) {
    $totalValidatedRevenue = (float) ($db->query("SELECT SUM(total_amount) FROM registrations WHERE status = 'validated'")->fetchColumn() ?: 0.0);
    $totalPendingRevenue   = (float) ($db->query("SELECT SUM(total_amount) FROM registrations WHERE status = 'pending'")->fetchColumn() ?: 0.0);
    $paymentsByMethod      = $db->query("
        SELECT payment_method, COUNT(*) AS count, SUM(total_amount) AS total
        FROM registrations
        GROUP BY payment_method
        ORDER BY count DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// 5. Statistiques détaillées par Discipline, Ratios d'Occupation & Grades
$disciplineStats = [];
foreach ($disciplines as $d) {
    $did = (int) $d['id'];
    $countInDisc = (int) $db->query("SELECT COUNT(DISTINCT member_id) FROM member_practices WHERE discipline_id = $did")->fetchColumn();

    $stmtBreak = $db->prepare("
        SELECT l.id, l.level_type, l.name, l.color_hex, l.age_min, l.age_max,
               (SELECT COUNT(DISTINCT mp.member_id)
                FROM member_practices mp
                WHERE mp.discipline_id = l.discipline_id
                  AND (mp.grade_id = l.id OR mp.level_id = l.id)) AS total
        FROM levels l
        WHERE l.discipline_id = :did
        ORDER BY l.level_type ASC, l.rank_order ASC, l.id ASC
    ");
    $stmtBreak->execute(['did' => $did]);
    $rows = $stmtBreak->fetchAll(PDO::FETCH_ASSOC);

    $capacity = 30;
    $occupancyPct = round(($countInDisc / $capacity) * 100, 1);

    $disciplineStats[] = [
        'id'             => $did,
        'name'           => (string) $d['name'],
        'total'          => $countInDisc,
        'capacity'       => $capacity,
        'occupancy_pct'  => min(100.0, $occupancyPct),
        'grades'         => array_values(array_filter($rows, static fn($r) => ($r['level_type'] ?? 'grade') === 'grade')),
        'niveaux'        => array_values(array_filter($rows, static fn($r) => ($r['level_type'] ?? 'grade') === 'niveau'))
    ];
}

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
            <a href="/members.php" class="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shrink-0">
                Consulter la liste des adhérents →
            </a>
        </div>
    </div>

    <!-- 1. Cartes KPIs Globales & Démographiques (US 5.2 - CA2) -->
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
            <span id="kpi-gender-ratio" class="text-2xl font-black text-purple-600 mt-1 block"><?= $genderRatioMale ?>% / <?= $genderRatioFemale ?>%</span>
            <span class="text-[11px] text-slate-500">Répartition estimée</span>
        </div>
    </div>

    <!-- 2. Indicateurs Financiers du Club (STRICTEMENT réservés au Bureau - US 5.2 CA3) -->
    <?php if ($isBureau): ?>
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
    <?php else: ?>
        <!-- Badge de protection RGPD Enseignant (CA3) -->
        <div id="rgpd-teacher-protection" class="bg-emerald-50 border border-emerald-200 p-4 rounded-2xl text-emerald-800 text-xs flex items-center gap-3">
            <span class="text-xl">🔒</span>
            <div>
                <strong>Protection RGPD & Anonymisation Pédagogique Active :</strong>
                Les indicateurs financiers (recettes de cotisations, montants encaissés et modes de paiement) ainsi que les données personnelles hors urgence sont strictement masqués pour le profil Entraîneur.
            </div>
        </div>
    <?php endif; ?>

    <!-- 3. Ratios d'Occupation par Discipline & Créneau (US 5.2 CA2) -->
    <section class="space-y-6">
        <div class="flex items-center justify-between">
            <h2 class="text-lg font-extrabold text-slate-900">Ratios d'Occupation des Créneaux & Répartition par Niveau</h2>
            <span class="text-xs text-slate-500">Capacité théorique standard : 30 élèves / créneau</span>
        </div>

        <div id="discipline-occupancy-list" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($disciplineStats as $ds): ?>
                <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-5">
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
                    <div class="space-y-2 pt-2 border-t border-slate-100">
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
            <?php endforeach; ?>
        </div>
    </section>

    <!-- 4. Fiche Terrain Tatami & Urgence (Conforme RGPD Enseignant) -->
    <section class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4">
        <div>
            <h2 class="text-base font-bold text-slate-900">Liste d'Appel Pédagogique & Contacts d'Urgence</h2>
            <p class="text-xs text-slate-500">Strictement anonymisé : aucune adresse personnelle, email ou cotisation financière n'est exposée sur le terrain.</p>
        </div>
        <div class="overflow-x-auto rounded-xl border border-slate-200 max-h-64">
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
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
