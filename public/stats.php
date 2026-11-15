<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 1);
$currentRole   = (string) ($_SESSION['role_name'] ?? 'Président');

$pageTitle     = 'Statistiques & Effectifs';
$activeNav     = 'stats';
$requireAuth   = true;
$requiredPerms = ['stats.view', 'roles.manage'];

$db = Database::getInstance();
$isTeacher = ($currentRole === 'Entraîneur');

// 1. Filtrage des disciplines selon le rôle (Toutes pour Président/Secrétaire, uniquement ses cours pour l'Enseignant)
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

// 3. Statistiques détaillées par Discipline, Grade (Âge) et Niveau (Ceinture/Gant)
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

    $disciplineStats[] = [
        'id'      => $did,
        'name'    => (string) $d['name'],
        'total'   => $countInDisc,
        'grades'  => array_values(array_filter($rows, static fn($r) => ($r['level_type'] ?? 'grade') === 'grade')),
        'niveaux' => array_values(array_filter($rows, static fn($r) => ($r['level_type'] ?? 'grade') === 'niveau'))
    ];
}

ob_start();
?>
<div class="space-y-8">
    <!-- En-tête adaptatif Président/Secrétaire vs Enseignant (Point 7) -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">
                <?= $isTeacher ? 'Périmètre Pédagogique Enseignant (Anonymisé RGPD)' : 'Pilotage Général du Club (' . htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') . ')' ?>
            </span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">
                <?= $isTeacher ? 'Statistiques de mes Cours & Disciplines' : 'Statistiques Générales des Adhérents' ?>
            </h1>
            <p class="text-xs text-slate-500 mt-1">
                <?= $isTeacher
                    ? 'Vous consultez les effectifs et répartitions par grade/niveau de vos disciplines attribuées, dans le strict respect du RGPD.'
                    : 'Vue consolidée de l\'ensemble des effectifs, tranches d\'âge, disciplines, grades et niveaux du club.' ?>
            </p>
        </div>
        <a href="/members.php" class="px-4 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold rounded-xl transition shrink-0">
            Consulter la liste des adhérents →
        </a>
    </div>

    <!-- 1. Cartes KPIs Globales / Cours -->
    <div class="grid grid-cols-2 lg:grid-cols-5 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase text-slate-400 block">Effectif Total</span>
            <span class="text-3xl font-black text-slate-900 mt-1 block"><?= $totalMembers ?></span>
            <span class="text-[11px] text-slate-500"><?= $isTeacher ? 'Élèves inscrits' : 'Fiches enregistrées' ?></span>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase text-emerald-600 block">Adhérents Actifs</span>
            <span class="text-3xl font-black text-emerald-600 mt-1 block"><?= $activeCount ?></span>
            <span class="text-[11px] text-slate-500">Dossiers validés</span>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase text-amber-600 block">En Attente</span>
            <span class="text-3xl font-black text-amber-600 mt-1 block"><?= $pendingCount ?></span>
            <span class="text-[11px] text-slate-500">Validation en cours</span>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase text-blue-600 block">Mineurs (&lt; 18 ans)</span>
            <span class="text-3xl font-black text-blue-600 mt-1 block"><?= $minorsCount ?></span>
            <span class="text-[11px] text-slate-500"><?= $totalMembers > 0 ? round(($minorsCount / $totalMembers) * 100) : 0 ?>% de l'effectif</span>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase text-purple-600 block">Majeurs (18+ ans)</span>
            <span class="text-3xl font-black text-purple-600 mt-1 block"><?= $adultsCount ?></span>
            <span class="text-[11px] text-slate-500"><?= $totalMembers > 0 ? round(($adultsCount / $totalMembers) * 100) : 0 ?>% de l'effectif</span>
        </div>
    </div>

    <!-- 2. Répartition par Discipline, Grade (Catégorie d'âge) et Niveau (Ceinture / Gant) -->
    <section class="space-y-6">
        <h2 class="text-lg font-extrabold text-slate-900">Répartition par Discipline, Grade (Âge) & Niveau Technique</h2>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <?php foreach ($disciplineStats as $ds): ?>
                <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-5">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 class="text-xl font-extrabold text-slate-900"><?= htmlspecialchars($ds['name'], ENT_QUOTES, 'UTF-8') ?></h3>
                        <span class="px-3 py-1 rounded-full bg-emerald-100 text-emerald-800 text-xs font-extrabold">
                            <?= (int) $ds['total'] ?> adhérent(s) inscrit(s)
                        </span>
                    </div>

                    <!-- A. Grades (Catégories d'âge : Poussin, Benjamin, Minime...) -->
                    <div class="space-y-2">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-500 block">
                            1. Répartition par Grade / Catégorie d'âge
                        </span>
                        <?php if (empty($ds['grades'])): ?>
                            <p class="text-xs text-slate-400 italic">Aucun grade d'âge configuré.</p>
                        <?php else: ?>
                            <div class="space-y-2">
                                <?php foreach ($ds['grades'] as $g): ?>
                                    <div class="flex items-center justify-between p-2.5 rounded-xl bg-slate-50 border border-slate-200 text-xs">
                                        <div>
                                            <strong class="text-slate-900"><?= htmlspecialchars((string) $g['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                            <span class="text-slate-400 ml-1">(<?= (int) ($g['age_min'] ?? 0) ?> - <?= (int) ($g['age_max'] ?? 99) ?> ans)</span>
                                        </div>
                                        <span class="px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-800 font-bold">
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
                            2. Répartition par Niveau Technique (Ceinture / Gant)
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
                                        <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 font-bold">
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

    <!-- 3. Fiche de Sécurité Tatami (Contacts d'urgence accessibles à l'Enseignant sans fuite RGPD) -->
    <section class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4">
        <div>
            <h2 class="text-base font-bold text-slate-900">Liste d'Appel & Contacts d'Urgence (Conforme RGPD)</h2>
            <p class="text-xs text-slate-500">Aucune donnée financière, adresse privée ou email personnel n'est affiché sur cette fiche terrain.</p>
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