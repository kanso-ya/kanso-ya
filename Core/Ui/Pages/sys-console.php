<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$pageTitle     = 'Console Système & Vue d\'Ensemble';
$activeNav     = 'sys-console';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'settings.manage'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <!-- En-tête Synthétique -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Console Technique & Supervision</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Vue d'Ensemble du CMS Kanso-ya</h1>
            <p class="text-xs text-slate-400 mt-1">État du mode démo, santé de la base SQLite WAL, modules actifs et dernières connexions système.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2.5">
            <button type="button" id="btn-backup-db" data-testid="btn-backup-db" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-2">
                💾 Sauvegarder la BDD SQLite
            </button>
        </div>
    </div>

    <!-- 4 Cartes Synthétiques d'État du CMS (Sprint 14 - Point 19) -->
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" data-testid="sys-overview-cards">
        <!-- Carte 1 : État du Mode Démo -->
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col justify-between gap-3">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Mode Démo</span>
                    <span id="sys-demo-mode-badge" data-testid="sys-demo-mode-badge" class="inline-flex items-center gap-1.5 mt-2 px-2.5 py-1 rounded-full text-xs font-bold bg-slate-800 text-slate-300 border border-slate-700">
                        Inactif
                    </span>
                </div>
                <span class="text-xl" aria-hidden="true">🧪</span>
            </div>
            <button type="button" id="btn-toggle-demo-mode" data-testid="sys-demo-mode-toggle" class="w-full py-2 px-3 rounded-xl bg-slate-800 hover:bg-slate-700 text-white text-xs font-bold transition border border-slate-700">
                Activer le Mode Démo
            </button>
        </div>

        <!-- Carte 2 : Base de Données SQLite (WAL) -->
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col justify-between gap-3">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-blue-400 block">Base de Données (SQLite WAL)</span>
                    <span id="metric-db-size" class="text-2xl font-black text-blue-400 mt-1 block">...</span>
                </div>
                <span class="text-xl" aria-hidden="true">🗄️</span>
            </div>
            <div class="grid grid-cols-3 gap-1.5 pt-2 border-t border-slate-800 text-[11px]">
                <div>
                    <span class="text-slate-500 block">Comptes</span>
                    <strong id="metric-users-count" class="text-white font-mono">...</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Adhérents</span>
                    <strong id="metric-members-count" class="text-emerald-400 font-mono">...</strong>
                </div>
                <div>
                    <span class="text-slate-500 block">Articles</span>
                    <strong id="metric-articles-count" class="text-purple-400 font-mono">...</strong>
                </div>
            </div>
        </div>

        <!-- Carte 3 : Modules Actifs -->
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col justify-between gap-3">
            <div class="flex items-start justify-between gap-2">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 block">Modules Actifs</span>
                    <span id="metric-active-modules" data-testid="metric-active-modules" class="text-2xl font-black text-emerald-400 mt-1 block">...</span>
                </div>
                <span class="text-xl" aria-hidden="true">🧩</span>
            </div>
            <a href="/sys-modules" class="text-xs font-semibold text-emerald-400 hover:underline inline-flex items-center gap-1 pt-2 border-t border-slate-800">
                Gérer les extensions SDK →
            </a>
        </div>

        <!-- Carte 4 : Dernières Connexions Système -->
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800 flex flex-col justify-between gap-3">
            <div>
                <div class="flex items-center justify-between">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-amber-400 block">Dernières Connexions & Événements</span>
                    <span class="text-base" aria-hidden="true">🛡️</span>
                </div>
                <ul id="sys-recent-logins" data-testid="sys-recent-logins" class="mt-2 space-y-1.5 text-[11px] text-slate-300">
                    <li class="text-slate-500 italic">Chargement des événements...</li>
                </ul>
            </div>
            <a href="/sys-logs" class="text-xs font-semibold text-amber-400 hover:underline inline-flex items-center gap-1 pt-2 border-t border-slate-800">
                Voir tout le journal d'audit →
            </a>
        </div>
    </div>

    <!-- Raccourcis rapides vers les sous-pages dédiées -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <a href="/sys-branding" class="p-4 rounded-2xl bg-slate-900 hover:bg-slate-800/80 border border-slate-800 transition flex items-center justify-between gap-3">
            <div>
                <span class="text-xs font-bold text-white block">🎨 Identité & Thème</span>
                <span class="text-[11px] text-slate-400">Logo, filigrane & palette</span>
            </div>
            <span class="text-slate-500">→</span>
        </a>
        <a href="/sys-smtp" class="p-4 rounded-2xl bg-slate-900 hover:bg-slate-800/80 border border-slate-800 transition flex items-center justify-between gap-3">
            <div>
                <span class="text-xs font-bold text-white block">✉️ Serveur SMTP</span>
                <span class="text-[11px] text-slate-400">Config, modèles & historique</span>
            </div>
            <span class="text-slate-500">→</span>
        </a>
        <a href="/sys-modules" class="p-4 rounded-2xl bg-slate-900 hover:bg-slate-800/80 border border-slate-800 transition flex items-center justify-between gap-3">
            <div>
                <span class="text-xs font-bold text-white block">🧩 Modules & SDK</span>
                <span class="text-[11px] text-slate-400">Scan, Safe Mode & cycle de vie</span>
            </div>
            <span class="text-slate-500">→</span>
        </a>
        <a href="/sys-logs" class="p-4 rounded-2xl bg-slate-900 hover:bg-slate-800/80 border border-slate-800 transition flex items-center justify-between gap-3">
            <div>
                <span class="text-xs font-bold text-white block">📜 Journaux d'audit</span>
                <span class="text-[11px] text-slate-400">Sécurité & traçabilité club</span>
            </div>
            <span class="text-slate-500">→</span>
        </a>
    </div>

    <!-- =====================================================================
         1. CHANGEMENT DE SAISON / ROLLOVER (US 5.1)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-white">1. Bascule Annuelle de Changement de Saison (Rollover)</h2>
                <p class="text-xs text-slate-400">Recalcule automatiquement les catégories d'âge (U10, U14, etc.) et clôture les inscriptions.</p>
            </div>
            <button id="btn-open-sys-rollover-modal" type="button" class="px-4 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-2 self-start sm:self-auto">
                <span>🔄 Déclencher la bascule de saison</span>
            </button>
        </div>
    </section>

    <!-- Modale Rollover Système -->
    <div id="sys-rollover-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 text-slate-100 space-y-5 shadow-2xl">
            <div class="flex items-center gap-3 text-amber-400 border-b border-slate-800 pb-3">
                <span class="text-2xl">⚠️</span>
                <h3 class="text-lg font-bold">Bascule Annuelle de Saison</h3>
            </div>
            <p class="text-xs text-slate-300 leading-relaxed">
                Cette opération archivera tous les dossiers validés de la saison en cours et recalculera les catégories d'âge de l'ensemble des adhérents actifs (sauf mentions forcées).
            </p>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Nouvelle Saison Cible *</label>
                    <input type="text" id="sys-target-season" placeholder="2027-2028" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Date de Référence du Recalcul</label>
                    <input type="date" id="sys-ref-date" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-mono">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button type="button" id="btn-cancel-sys-rollover" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300">Annuler</button>
                <button type="button" id="btn-confirm-sys-rollover" class="px-5 py-2 rounded-xl text-xs font-bold bg-amber-600 hover:bg-amber-500 text-white">Confirmer le Rollover</button>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         2. MOTEUR DE MODULES & EXTENSIONS SDK (Lot 3)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-white">2. État des Modules & Extensions SDK</h2>
                <p class="text-xs text-slate-400">Détection automatique dans <code>/modules/</code>, cycle de vie complet et Safe Mode.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="/sys-modules" class="text-xs font-semibold text-emerald-400 hover:underline mr-2">Page dédiée →</a>
                <button id="btn-scan-modules" type="button" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold shadow-sm transition flex items-center gap-2">
                    <span>🔄 Scanner les modules</span>
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">Module</th>
                        <th class="p-3">Version & Auteur</th>
                        <th class="p-3">Compatibilité Core</th>
                        <th class="p-3">Statut</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="modules-tbody" class="divide-y divide-slate-800/80">
                    <tr>
                        <td colspan="5" class="p-4 text-center text-slate-500">Chargement des modules...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Section Superadmins Secondaires (Point 5 / US 1.1) -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-lg font-bold text-white">Comptes Superadmin Déclarés (`sys_superadmins`)</h2>
                <p class="text-xs text-slate-400">Gérez les accès techniques d'administration d'infrastructure.</p>
            </div>
            <button type="button" id="btn-toggle-add-admin" class="bg-slate-800 hover:bg-slate-700 text-white px-3.5 py-1.5 rounded-xl text-xs font-semibold transition">
                + Nouveau Superadmin
            </button>
        </div>

        <!-- Formulaire d'ajout d'un Superadmin -->
        <form id="form-create-superadmin" class="hidden p-5 rounded-xl bg-slate-950 border border-slate-800 space-y-4">
            <h3 class="text-sm font-bold text-emerald-400">Créer un Superadmin secondaire</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Identifiant *</label>
                    <input type="text" id="new-admin-username" name="username" required placeholder="admin_technique" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email *</label>
                    <input type="email" id="new-admin-email" name="email" required placeholder="admin@kanso-ya.local" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Mot de passe (8+ car.) *</label>
                    <input type="password" id="new-admin-password" name="password" required minlength="8" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="btn-cancel-add-admin" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs rounded-xl font-semibold">Annuler</button>
                <button type="submit" id="btn-submit-add-admin" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl transition">Créer le compte</button>
            </div>
        </form>

        <!-- Formulaire de modification de son propre mot de passe Superadmin (Point 2) -->
        <form id="form-update-sys-password" class="p-5 rounded-xl bg-slate-950 border border-slate-800 space-y-4">
            <h3 class="text-sm font-bold text-amber-400">Modifier mon mot de passe Superadmin</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Mot de passe actuel *</label>
                    <input type="password" id="sys-current-password" name="current_password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nouveau mot de passe (8+ car., Maj, Min, Chiffre, Symbole) *</label>
                    <input type="password" id="sys-new-password" name="new_password" required minlength="8" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
            </div>
            <div class="flex justify-end pt-1">
                <button type="submit" id="btn-submit-sys-password" class="px-5 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold rounded-xl transition">
                    Mettre à jour mon mot de passe
                </button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Identifiant</th>
                        <th class="p-3">Email Technique</th>
                        <th class="p-3">Date de création</th>
                        <th class="p-3 text-right">Statut</th>
                    </tr>
                </thead>
                <tbody id="superadmins-tbody" class="divide-y divide-slate-800 font-mono"></tbody>
            </table>
        </div>
    </section>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
