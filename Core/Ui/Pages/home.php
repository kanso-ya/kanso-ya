<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$pageTitle   = 'Accueil & Actualités du Club';
$activeNav   = 'home';
$requireAuth = false;
$pageScripts = ['/assets/ts/home.js'];

ob_start();
?>
<div class="space-y-8">
    <!-- Bannière Hero du Dojo -->
    <section class="bg-gradient-to-br from-slate-900 via-slate-800 to-slate-900 rounded-2xl p-8 md:p-10 text-white shadow-md flex flex-col md:flex-row items-start md:items-center justify-between gap-6 border border-slate-700/60">
        <div class="space-y-3 max-w-2xl">
            <span class="px-3 py-1 rounded-full bg-emerald-500/20 text-emerald-300 text-xs font-bold uppercase tracking-wider">
                Saison Sportive 2026-2027
            </span>
            <h1 class="text-2xl sm:text-4xl font-extrabold tracking-tight">
                Bienvenue au Dojo Kanso-ya
            </h1>
            <p class="text-slate-300 text-sm sm:text-base leading-relaxed">
                Découvrez la vie du club, les résultats de nos compétiteurs et inscrivez-vous en ligne (individuel ou famille avec remise automatique de 10% dès le 2ème pratiquant).
            </p>
        </div>
        <div class="flex flex-wrap gap-3 shrink-0">
            <a href="/register" class="px-5 py-3 brand-primary-bg hover:opacity-95 text-white text-sm font-bold rounded-xl shadow-sm transition">
                S'inscrire au Club →
            </a>
            <a href="/horaires" class="px-5 py-3 bg-slate-700/80 hover:bg-slate-700 text-white text-sm font-semibold rounded-xl border border-slate-600 transition">
                Horaires & Tarifs
            </a>
        </div>
    </section>

    <!-- Filtres par catégorie & Flux des Actualités -->
    <section class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 md:p-8 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <h2 class="text-xl font-bold text-slate-900">Actualités & Vie du Club</h2>
                <p class="text-xs text-slate-500">Articles publiés accessibles selon votre profil.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2" id="home-category-filters">
                <button type="button" data-filter="all" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-900 text-white">Tous</button>
                <button type="button" data-filter="Vie du club" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200">Vie du club</button>
                <button type="button" data-filter="Compétition" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200">Compétition</button>
                <button type="button" data-filter="Événements" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200">Événements</button>
            </div>
        </div>

        <div id="home-articles-grid" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Injecté par home.ts -->
        </div>
    </section>
</div>

<!-- Modale de lecture intégrale d'un article -->
<div id="article-reader-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-2xl p-6 md:p-8 max-h-[85vh] overflow-y-auto space-y-4">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <span id="reader-badge" class="text-xs font-semibold px-2.5 py-0.5 rounded"></span>
            <button type="button" id="btn-close-reader" class="text-slate-400 hover:text-slate-700 text-lg font-bold">✕</button>
        </div>
        <h2 id="reader-title" class="text-2xl font-extrabold text-slate-900"></h2>
        <div id="reader-content" class="prose max-w-none text-slate-700 text-sm leading-relaxed space-y-3"></div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';