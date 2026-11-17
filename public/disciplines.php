<?php

declare(strict_types=1);

$pageTitle     = 'Disciplines, Grades & Niveaux';
$activeNav     = 'disciplines';
$requireAuth   = true;
$requiredPerms = ['roles.manage'];
$pageScripts   = ['/assets/ts/disciplines.js'];

ob_start();
?>
<div class="space-y-6">
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 bg-white p-6 rounded-2xl shadow-xs border border-slate-200">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">Référentiel Pédagogique & Sportif (Point 11)</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Disciplines, Grades (Âge) & Niveaux (Ceintures / Gants)</h1>
            <p class="text-xs text-slate-500 mt-0.5">
                Distinguez la <strong>Discipline</strong> (Judo, Boxe, Musique), le <strong>Grade d'âge</strong> (Poussin, Minime, Benjamin) et le <strong>Niveau technique</strong> (Couleur de ceinture ou de gant), avec affichage des effectifs inscrits.
            </p>
        </div>
        <button id="btn-open-modal" type="button" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold shadow-xs transition shrink-0">
            + Nouvelle Discipline
        </button>
    </div>

    <!-- Barre d'onglets par discipline (Point 15) -->
    <div id="disciplines-tabs-bar" class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-slate-200" data-testid="disciplines-tabs-bar"></div>

    <!-- Affichage de la discipline sélectionnée avec Grades, Niveaux et Badges cliquables -->
    <div id="disciplines-list" class="space-y-6"></div>
</div>

<!-- Modale Création / Édition distinguant Grade (Âge) et Niveau (Ceinture/Gant) -->
<div id="discipline-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-6 md:p-8 max-h-[90vh] overflow-y-auto">
        <h2 id="discipline-modal-title" class="text-xl font-extrabold text-slate-900 mb-4">Configurer une Discipline, ses Grades & ses Niveaux</h2>
        <form id="discipline-form" class="space-y-5" novalidate>
            <input type="hidden" id="discipline-edit-id" value="">

            <div>
                <label for="discipline-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">1. Nom de la Discipline (ex: Judo, Karaté, Boxe, Musique) *</label>
                <input type="text" id="discipline-name" name="name" placeholder="Ex: Judo" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-bold">
                <p id="name-error" class="hidden text-xs text-red-600 font-semibold mt-1">Le nom de la discipline est obligatoire.</p>
            </div>

            <div class="space-y-3 pt-2 border-t border-slate-100">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-700 block">2. Grades (Catégories d'âge) & Niveaux (Ceintures / Gants)</span>
                        <span class="text-[11px] text-slate-500">Choisissez le type de chaque ligne : Grade (Poussin, Benjamin, Minime) ou Niveau (Ceinture, Gant).</span>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" id="btn-add-level" class="text-xs bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 px-3 py-1.5 rounded-xl font-bold transition">
                            + Ajouter un Grade / Niveau
                        </button>
                    </div>
                </div>
                <div id="levels-container" class="space-y-2.5 max-h-64 overflow-y-auto pr-1"></div>
            </div>

            <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
                <button type="button" id="btn-close-modal" class="px-4 py-2 text-slate-600 hover:bg-slate-100 rounded-xl text-xs font-semibold">Annuler</button>
                <button type="submit" id="btn-submit" class="px-6 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition">
                    Enregistrer
                </button>
            </div>
        </form>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';