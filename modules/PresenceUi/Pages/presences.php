<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Vue principale du module Présences & Suivi Tatami (/presences).
 * Optimisée pour smartphone/tablette au bord du tatami et conforme RGPD.
 */

$pageTitle     = 'Présences & Suivi Tatami';
$activeNav     = 'presence';
$requireAuth   = true;
$requiredPerms = ['stats.view'];
$pageScripts   = ['/assets/modules/presence/presence.js'];

$today = date('Y-m-d');

ob_start();
?>
<link rel="stylesheet" href="/assets/modules/presence/presence.css">

<div class="space-y-6">
    <!-- En-tête du module -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-brand-primary">Module Extension — Appel Numérique</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">📋 Présences & Suivi Tatami</h1>
            <p class="text-xs text-slate-500 mt-1">Ouvrez une feuille d'appel par discipline et créneau, pointez vos pratiquants en 1 clic et consultez leur assiduité.</p>
        </div>
        <a href="/stats" class="px-4 py-2.5 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shrink-0">
            Voir l'onglet Assiduité dans /stats →
        </a>
    </div>

    <!-- Formulaire d'ouverture / création idempotente de séance -->
    <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <h2 class="text-base font-bold text-slate-900">1. Ouvrir ou créer une feuille d'appel</h2>
        <form id="presence-session-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 items-end">
            <div>
                <label for="presence-discipline-select" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Discipline *</label>
                <select id="presence-discipline-select" data-testid="presence-discipline-select" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm bg-white">
                    <option value="">Chargement...</option>
                </select>
            </div>
            <div>
                <label for="presence-date-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date du cours *</label>
                <input type="date" id="presence-date-input" data-testid="presence-date-input" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>" required class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <label for="presence-slot-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Créneau horaire *</label>
                <input type="text" id="presence-slot-input" data-testid="presence-slot-input" value="18:00-19:30" required placeholder="Ex: 18:00-19:30" class="w-full px-3.5 py-2.5 border border-slate-300 rounded-xl text-sm">
            </div>
            <div>
                <button type="submit" id="btn-open-presence-session" data-testid="btn-open-presence-session" class="w-full px-4 py-2.5 rounded-xl bg-brand-primary hover:opacity-90 text-white text-xs font-bold uppercase tracking-wider transition">
                    Ouvrir la feuille d'appel
                </button>
            </div>
        </form>
    </section>

    <!-- Feuille d'appel active -->
    <section id="presence-roll-section" class="hidden bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
            <div>
                <h2 id="presence-roll-title" class="text-lg font-extrabold text-slate-900">Feuille d'appel</h2>
                <p id="presence-roll-meta" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" id="btn-mark-all-present" class="px-3 py-2 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold transition">
                    ✓ Tous présents
                </button>
                <button type="button" id="btn-save-presence-roll" data-testid="btn-save-presence-roll" class="px-5 py-2 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold uppercase tracking-wider shadow-xs transition">
                    Enregistrer l'appel
                </button>
            </div>
        </div>

        <div id="presence-roster-list" data-testid="presence-roster-list" class="divide-y divide-slate-100"></div>
    </section>

    <!-- Séances récentes -->
    <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-3">
        <h2 class="text-base font-bold text-slate-900">Séances récentes</h2>
        <div id="presence-recent-sessions" class="divide-y divide-slate-100 text-xs text-slate-600">
            <p class="py-3 text-slate-400 italic">Chargement des séances...</p>
        </div>
    </section>
</div>

<!-- Modale Historique d'Assiduité Individuel (RGPD) -->
<div id="presence-history-modal" data-testid="presence-history-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-lg w-full p-6 space-y-4">
        <div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3">
            <div>
                <span class="text-[11px] font-bold uppercase text-brand-primary">Suivi d'assiduité</span>
                <h3 id="presence-history-member-name" class="text-lg font-extrabold text-slate-900">Adhérent</h3>
            </div>
            <button type="button" id="btn-close-presence-history" class="px-2.5 py-1 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-600 text-xs font-bold">✕</button>
        </div>

        <div class="grid grid-cols-4 gap-2 text-center">
            <div class="p-3 rounded-xl bg-slate-50 border border-slate-200">
                <span class="text-[10px] uppercase font-bold text-slate-400 block">Taux</span>
                <span id="presence-history-rate" data-testid="presence-history-rate" class="text-lg font-black text-emerald-600">0%</span>
            </div>
            <div class="p-3 rounded-xl bg-emerald-50 border border-emerald-100">
                <span class="text-[10px] uppercase font-bold text-emerald-600 block">Présent</span>
                <span id="presence-history-present" class="text-lg font-black text-emerald-700">0</span>
            </div>
            <div class="p-3 rounded-xl bg-red-50 border border-red-100">
                <span class="text-[10px] uppercase font-bold text-red-600 block">Absent</span>
                <span id="presence-history-absent" class="text-lg font-black text-red-700">0</span>
            </div>
            <div class="p-3 rounded-xl bg-amber-50 border border-amber-100">
                <span class="text-[10px] uppercase font-bold text-amber-600 block">Excusé</span>
                <span id="presence-history-excused" class="text-lg font-black text-amber-700">0</span>
            </div>
        </div>

        <div id="presence-history-records" class="max-h-64 overflow-y-auto divide-y divide-slate-100 text-xs"></div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require dirname(__DIR__, 3) . '/Core/Ui/Templates/layout.php';
