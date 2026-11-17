<?php

declare(strict_types=1);

$pageTitle     = 'Journaux Techniques & Audit Trail';
$activeNav     = 'sys-console';
$currentSysNav = 'logs';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'logs.view'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <?php require __DIR__ . '/../core/ui/templates/sys-nav.php'; ?>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Audit & Traçabilité Réelle</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Journaux Système, Sécurité & Application</h1>
            <p class="text-xs text-slate-400 mt-1">Consultez en direct toutes les actions sensibles, modifications de rôles, sauvegardes et rejets 403.</p>
        </div>
        <div class="flex items-center gap-3">
            <input type="search" id="sys-logs-search" placeholder="Filtrer par action, détail ou IP..." class="bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-xs text-white w-full sm:w-64">
        </div>
    </div>

    <!-- Journal sys_logs -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-bold text-white border-b border-slate-800 pb-3">1. Journal d'Audit Système & Sécurité (`sys_logs`)</h2>
        <div class="overflow-x-auto rounded-xl border border-slate-800 max-h-96">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="sticky top-0 bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Action</th>
                        <th class="p-3">Détails Techniques</th>
                        <th class="p-3">IP</th>
                        <th class="p-3">Horodatage</th>
                    </tr>
                </thead>
                <tbody id="sys-logs-tbody" class="divide-y divide-slate-800/80 font-mono"></tbody>
            </table>
        </div>
    </section>

    <!-- Journal app_logs (actions métier) -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <h2 class="text-lg font-bold text-white border-b border-slate-800 pb-3">2. Journal des Actions Métier Club (`app_logs`)</h2>
        <div class="overflow-x-auto rounded-xl border border-slate-800 max-h-72">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="sticky top-0 bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Utilisateur</th>
                        <th class="p-3">Action</th>
                        <th class="p-3">Détails</th>
                        <th class="p-3">Date</th>
                    </tr>
                </thead>
                <tbody id="app-logs-tbody" class="divide-y divide-slate-800/80 font-mono"></tbody>
            </table>
        </div>
    </section>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
