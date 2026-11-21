<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

http_response_code(404);

$pageTitle          = 'Page Introuvable (404)';
$activeNav          = '';
$requireAuth        = false;
$requiredPerms      = [];
$accessDeniedReason = null;

ob_start();
?>
<div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-10 text-center max-w-lg mx-auto my-12 space-y-4">
    <div class="w-14 h-14 rounded-full bg-slate-100 text-slate-700 flex items-center justify-center mx-auto text-2xl font-extrabold">404</div>
    <h1 class="text-xl font-extrabold text-slate-900">Page introuvable</h1>
    <p class="text-sm text-slate-600 leading-relaxed">
        La page demandée n'existe pas ou a été déplacée.
    </p>
    <div class="flex justify-center gap-3 pt-2">
        <a href="/" class="px-5 py-2.5 rounded-xl brand-primary-bg text-white text-xs font-bold shadow-xs transition">
            Retour à l'accueil
        </a>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../Templates/layout.php';
