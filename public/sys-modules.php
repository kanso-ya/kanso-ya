<?php

declare(strict_types=1);

$pageTitle     = 'Moteur de Modules & Extensions SDK';
$activeNav     = 'sys-console';
$currentSysNav = 'modules';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'settings.manage'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <?php require __DIR__ . '/../core/ui/templates/sys-nav.php'; ?>

    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Écosystème & Extensibilité</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Moteur de Modules & Safe Mode Automatique</h1>
            <p class="text-xs text-slate-400 mt-1">Scannez le répertoire <code>/modules/</code>, activez des extensions tierces et isolez automatiquement les modules défaillants.</p>
        </div>
        <button type="button" id="btn-scan-modules" data-testid="btn-scan-modules" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl text-xs font-bold transition flex items-center gap-2">
            🔄 Scanner les modules
        </button>
    </div>

    <!-- Bannière d'alerte Safe Mode si un module a crashé -->
    <div id="safe-mode-banner" class="hidden p-4 rounded-xl bg-amber-950/80 border border-amber-600 text-amber-200 text-xs flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="text-xl">🛡️</span>
            <div>
                <strong class="font-bold block text-amber-300">Safe Mode Activé : Un module défaillant a été isolé automatiquement</strong>
                <span id="safe-mode-details" class="text-amber-200/90">Le reste du CMS continue de fonctionner normalement sans interruption de service.</span>
            </div>
        </div>
        <button type="button" id="btn-dismiss-safe-mode" class="px-3 py-1 bg-amber-900/60 hover:bg-amber-800 text-amber-300 text-xs font-semibold rounded-lg transition">
            Ignorer
        </button>
    </div>

    <!-- Grille des Modules installés -->
    <div id="modules-list" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"></div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
