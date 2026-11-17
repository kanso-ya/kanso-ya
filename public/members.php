<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentRole = (string) ($_SESSION['role_name'] ?? 'Visiteur');

$pageTitle       = $currentRole === 'Adhérent' ? 'Mes rattachements' : 'Adhérents';
$activeNav       = 'members';
$requireAuth     = true;
$requiredPerms   = ['members.view', 'profile.view'];
$pageScripts     = ['/assets/ts/members.js'];
$contentMaxWidth = 'max-w-[1600px]';

ob_start();
?>
<div class="space-y-6">
    <!-- Bandeau KPIs -->
    <div id="members-kpi-bar" class="grid grid-cols-2 sm:grid-cols-4 gap-4 <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Total Adhérents</span>
            <span id="kpi-total" class="text-2xl font-extrabold text-slate-900">0</span>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 block">Actifs</span>
            <span id="kpi-active" class="text-2xl font-extrabold text-emerald-600">0</span>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-amber-600 block">En attente</span>
            <span id="kpi-pending" class="text-2xl font-extrabold text-amber-600">0</span>
        </div>
        <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-2xs">
            <span class="text-[11px] font-bold uppercase tracking-wider text-red-500 block">Radiés</span>
            <span id="kpi-radiated" class="text-2xl font-extrabold text-red-600">0</span>
        </div>
    </div>

    <?php if ($currentRole === 'Adhérent'): ?>
        <div class="bg-emerald-950 text-white p-6 rounded-2xl border border-emerald-800 space-y-2">
            <span class="text-xs font-bold uppercase tracking-wider text-emerald-400">Espace Responsable Légal & Co-Tuteurs (RGPD)</span>
            <h1 class="text-xl font-extrabold">Mes rattachements & Partage familial</h1>
            <p class="text-xs text-emerald-100 leading-relaxed">
                Consultez les disciplines, cours (grades d'âge) et niveaux techniques des personnes rattachées à votre compte, modifiez leurs coordonnées et gérez la visibilité RGPD de vos informations vis-à-vis du co-tuteur.
            </p>
        </div>
    <?php endif; ?>

    <!-- Conteneur principal de l'Annuaire -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-5">
        <div class="flex flex-col xl:flex-row xl:items-center justify-between gap-4 pb-4 border-b border-slate-100">
            <div>
                <h2 id="members-main-title" class="text-2xl font-extrabold text-slate-900">
                    <?= $currentRole === 'Adhérent' ? 'Personnes rattachées à mon compte' : 'Annuaire des Adhérents & Cours' ?>
                </h2>
                <p class="text-xs text-slate-500 mt-0.5">
                    <?= $currentRole === 'Entraîneur'
                        ? '🔒 Mode Enseignant (RGPD) : seules les disciplines, grades, niveaux et contacts d\'urgence sont visibles.'
                        : 'Visualisez directement les disciplines, grades (cours d\'âge) et niveaux (ceintures), et cliquez sur Modifier pour les ajuster.' ?>
                </p>
            </div>

            <div class="flex flex-wrap items-center gap-2.5">
                <!-- Filtre par statut -->
                <div id="member-status-filters" class="flex items-center bg-slate-100 p-1 rounded-xl text-xs font-semibold <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                    <button type="button" data-status="all" class="px-3 py-1.5 rounded-lg bg-white text-slate-900 shadow-2xs transition">Tous</button>
                    <button type="button" data-status="active" class="px-3 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition">Actifs</button>
                    <button type="button" data-status="pending" class="px-3 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition">En attente</button>
                    <button type="button" data-status="radiated" class="px-3 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition">Radiés</button>
                </div>

                <!-- Filtres combinés CRM (Points 22 & 23) -->
                <select id="filter-member-age" data-testid="filter-member-age" class="border border-slate-300 rounded-xl px-3 py-2 text-xs font-semibold bg-white <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                    <option value="">Tous les âges</option>
                    <option value="minor">Mineurs (< 18 ans)</option>
                    <option value="adult">Majeurs (18 ans +)</option>
                </select>

                <select id="filter-member-health" data-testid="filter-member-health" class="border border-slate-300 rounded-xl px-3 py-2 text-xs font-semibold bg-white <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                    <option value="">Tous statuts santé</option>
                    <option value="signed">Attestation Cerfa validée</option>
                    <option value="pending">Certificat médical requis</option>
                </select>

                <!-- Filtre par Discipline -->
                <select id="member-discipline-filter" class="border border-slate-300 rounded-xl px-3 py-2 text-xs font-semibold bg-white <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                    <option value="all">Toutes les disciplines</option>
                </select>

                <input type="search" id="member-search" placeholder="Rechercher (Nom, Grade, Ceinture)..." class="border border-slate-300 rounded-xl px-3.5 py-2 text-xs w-full sm:w-48 focus:ring-2 focus:ring-emerald-500 focus:outline-none">

                <!-- Bouton Actions Groupées & Export (Points 21 & 24) -->
                <button type="button" id="btn-bulk-resend-otp" data-testid="btn-bulk-resend-otp" class="hidden px-3.5 py-2 rounded-xl bg-amber-50 hover:bg-amber-100 text-amber-800 border border-amber-300 text-xs font-bold transition">
                    📧 Renvoyer l'invitation (<span id="bulk-selected-count">0</span>)
                </button>
                <button type="button" id="btn-export-csv" data-testid="btn-export-csv" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition flex items-center gap-1.5 <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                    📥 Exporter CSV
                </button>

                <?php if (in_array($currentRole, ['Président', 'Secrétaire'], true)): ?>
                    <button id="btn-add-member" type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold shrink-0 shadow-xs transition">
                        + Nouvel Adhérent
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <!-- Onglets Disciplines Dynamiques (Sans parenthèses - Sprint 9) -->
        <div id="discipline-tabs-container" class="flex items-center gap-2 overflow-x-auto pb-1 text-xs <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>"></div>

        <div class="overflow-x-auto rounded-xl border border-slate-200 max-h-[68vh]">
            <table id="members-table" class="w-full text-left border-collapse">
                <thead class="sticky top-0 bg-slate-50 text-slate-600 text-xs uppercase tracking-wider border-b border-slate-200 z-10">
                    <tr>
                        <th class="p-3.5 w-10 text-center <?= $currentRole === 'Adhérent' ? 'hidden' : '' ?>">
                            <input type="checkbox" id="select-all-members" class="rounded text-emerald-600 focus:ring-emerald-500">
                        </th>
                        <th class="p-3.5 cursor-pointer hover:text-slate-900 select-none" data-sort="name">
                            Adhérent <span class="sort-icon text-[10px] text-slate-400">↕</span>
                        </th>
                        <th class="p-3.5 cursor-pointer hover:text-slate-900 select-none" data-sort="birth_date">
                            Naissance & Âge <span class="sort-icon text-[10px] text-slate-400">↕</span>
                        </th>
                        <th class="p-3.5 cursor-pointer hover:text-slate-900 select-none" data-sort="discipline">
                            Disciplines, Cours (Grade) & Niveau <span class="sort-icon text-[10px] text-slate-400">↕</span>
                        </th>
                        <th class="p-3.5">Contact d'Urgence</th>
                        <th class="p-3.5 cursor-pointer hover:text-slate-900 select-none" data-sort="status">
                            Statut & Santé <span class="sort-icon text-[10px] text-slate-400">↕</span>
                        </th>
                        <th class="p-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 text-sm"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modale d'ajout d'adhérent -->
<div id="modal-add-member" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6">
        <h3 class="text-lg font-extrabold text-slate-900 mb-4">Créer une Fiche Adhérent</h3>
        <form id="form-quick-member" class="space-y-4" novalidate>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom *</label>
                    <input type="text" name="first_name" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom *</label>
                    <input type="text" name="last_name" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date de naissance *</label>
                <input type="date" name="birth_date" value="2012-04-15" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email (Invitation d'activation 48h)</label>
                <input type="email" name="email" placeholder="contact@mail.com" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" id="btn-close-member-modal" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-xl text-xs font-semibold">Annuler</button>
                <button type="submit" class="px-5 py-2 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 text-xs font-bold">Enregistrer</button>
            </div>
        </form>
    </div>
</div>

<!-- Modale d'édition complète (Informations Personnelles + Multi-Disciplines / Grades / Niveaux) -->
<div id="modal-edit-member" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-6 md:p-8 max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-extrabold text-slate-900 mb-4">Modifier la fiche Adhérent & ses Cours / Grades</h3>
        <form id="form-edit-member" class="space-y-5" novalidate>
            <input type="hidden" id="edit-member-id">

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom *</label>
                    <input type="text" id="edit-first-name" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom *</label>
                    <input type="text" id="edit-last-name" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm font-semibold">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date de naissance *</label>
                    <input type="date" id="edit-birth-date" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone</label>
                    <input type="tel" id="edit-phone" placeholder="06..." class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Adresse postale</label>
                    <input type="text" id="edit-address" placeholder="Rue, Code postal, Ville" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 pt-2 border-t border-slate-100">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom du Contact d'Urgence *</label>
                    <input type="text" id="edit-emerg-name" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone d'Urgence *</label>
                    <input type="tel" id="edit-emerg-phone" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
            </div>

            <!-- GESTION MULTI-DISCIPLINES, GRADES (COURS D'ÂGE) & NIVEAUX TECHNIQUES (CEINTURES) -->
            <div id="edit-practices-section" class="pt-4 border-t border-slate-200 space-y-3">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 block">Disciplines, Cours (Grade d'âge) & Niveaux Techniques</span>
                        <span class="text-[11px] text-slate-500">Un adhérent peut suivre plusieurs disciplines et changer de cours/grade ou de ceinture.</span>
                    </div>
                    <button type="button" id="btn-edit-add-practice" class="px-3 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold transition">
                        + Ajouter une discipline
                    </button>
                </div>

                <div id="edit-practices-container" class="space-y-2.5"></div>
            </div>

            <div id="edit-status-wrapper" class="pt-2 border-t border-slate-100">
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Statut d'adhésion</label>
                <select id="edit-status" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm bg-white font-semibold">
                    <option value="active">Actif (Cotisation à jour)</option>
                    <option value="pending">En attente de validation</option>
                    <option value="radiated">Radié / Archivé</option>
                </select>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" id="btn-close-edit-modal" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-xl text-xs font-semibold">Annuler</button>
                <button type="submit" id="btn-submit-edit-member" class="px-5 py-2.5 bg-emerald-600 text-white rounded-xl hover:bg-emerald-700 text-xs font-bold shadow-xs transition">
                    Enregistrer les modifications
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modale Certificat Médical -->
<div id="modal-medical-upload" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md p-6 space-y-4">
        <h3 class="text-lg font-extrabold text-slate-900">Déposer un Certificat Médical</h3>
        <p id="medical-member-label" class="text-xs text-slate-500"></p>
        <form id="form-medical-upload" class="space-y-4" novalidate>
            <input type="hidden" id="medical-member-id">
            <div class="p-4 border-2 border-dashed border-slate-300 rounded-xl bg-slate-50 text-center">
                <input type="file" id="medical-file-input" name="file" accept=".pdf,image/jpeg,image/png" required class="text-xs text-slate-600">
                <p class="text-[11px] text-slate-400 mt-2">Formats : PDF, JPEG, PNG (Coffre-fort privé hors racine web).</p>
            </div>
            <div class="flex justify-end gap-3 pt-2">
                <button type="button" id="btn-close-medical-modal" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-xl text-xs font-semibold">Annuler</button>
                <button type="submit" id="btn-submit-medical" class="px-5 py-2 bg-slate-900 text-white rounded-xl hover:bg-slate-800 text-xs font-bold">Téléverser</button>
            </div>
        </form>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';