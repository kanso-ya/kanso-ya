<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Vue principale du module Événements, Stages & Compétitions (/evenements).
 * Accessible en consultation publique ; inscription en 1 clic pour les adhérents/familles connectés
 * et gestion complète (création, clôture, liste participants CSV) pour les encadrants/Bureau.
 */

$pageTitle   = 'Événements, Stages & Compétitions';
$activeNav   = 'evenements';
$requireAuth = false;
$pageScripts = ['/assets/modules/evenements/evenements.js'];

$defaultDate = date('Y-m-d', strtotime('+14 days'));

ob_start();
?>
<link rel="stylesheet" href="/assets/modules/evenements/evenements.css">

<div class="space-y-6">
    <!-- En-tête -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-brand-primary">Module Extension — Agenda du Club</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">📅 Événements, Stages & Compétitions</h1>
            <p class="text-xs text-slate-500 mt-1">Consultez le calendrier sportif du club et inscrivez-vous ou vos enfants en 1 clic.</p>
        </div>
        <a href="/" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition shrink-0">
            ← Retour à l'accueil
        </a>
    </div>

    <!-- Formulaire de création d'événement (affiché uniquement aux gestionnaires) -->
    <section id="events-create-section" class="hidden bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <h2 class="text-base font-bold text-slate-900">➕ Créer un nouvel événement</h2>
        <form id="events-create-form" data-testid="events-create-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
            <div class="sm:col-span-2 lg:col-span-1">
                <label for="event-title-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Titre *</label>
                <input type="text" id="event-title-input" data-testid="event-title-input" required placeholder="Ex: Stage Kata Hiver" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label for="event-type-select" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Type *</label>
                <select id="event-type-select" data-testid="event-type-select" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                    <option value="stage">Stage</option>
                    <option value="grade">Passage de grade</option>
                    <option value="competition">Compétition</option>
                    <option value="autre">Autre événement</option>
                </select>
            </div>
            <div>
                <label for="event-date-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date *</label>
                <input type="date" id="event-date-input" data-testid="event-date-input" value="<?= htmlspecialchars($defaultDate, ENT_QUOTES, 'UTF-8') ?>" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label for="event-location-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Lieu</label>
                <input type="text" id="event-location-input" data-testid="event-location-input" placeholder="Dojo Principal" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label for="event-capacity-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Jauge maximale (vide = illimitée)</label>
                <input type="number" id="event-capacity-input" data-testid="event-capacity-input" min="0" placeholder="Ex: 20" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label for="event-discipline-select" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Discipline cible</label>
                <select id="event-discipline-select" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                    <option value="">Toutes disciplines</option>
                </select>
            </div>
            <div class="sm:col-span-2 lg:col-span-2">
                <label for="event-desc-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Description</label>
                <input type="text" id="event-desc-input" placeholder="Informations pratiques, tenue, horaires..." class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
            </div>
            <div class="flex items-end">
                <button type="submit" id="btn-create-event" data-testid="btn-create-event" class="w-full px-4 py-2.5 rounded-xl bg-brand-primary hover:opacity-90 text-white text-xs font-bold uppercase tracking-wider transition">
                    Publier l'événement
                </button>
            </div>
        </form>
    </section>

    <!-- Grille du calendrier -->
    <div id="events-list-grid" data-testid="events-list-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-5">
        <p class="text-xs text-slate-400 italic col-span-full">Chargement du calendrier...</p>
    </div>
</div>

<!-- Modale Liste des Participants (Gestionnaires) -->
<div id="event-participants-modal" data-testid="event-participants-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-lg w-full p-6 space-y-4">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <span class="text-[11px] font-bold uppercase text-brand-primary">Liste des inscrits</span>
                <h3 id="participants-modal-title" class="text-lg font-extrabold text-slate-900">Événement</h3>
            </div>
            <button type="button" id="btn-close-participants-modal" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold">✕</button>
        </div>

        <div id="participants-list-container" class="max-h-64 overflow-y-auto divide-y divide-slate-100 text-xs"></div>

        <div class="flex justify-end pt-2 border-t border-slate-100">
            <button type="button" id="btn-export-participants-csv" data-testid="btn-export-participants-csv" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition">
                ⬇ Exporter CSV
            </button>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require dirname(__DIR__, 3) . '/Core/Ui/Templates/layout.php';
