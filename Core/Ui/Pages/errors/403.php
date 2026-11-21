<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

http_response_code(403);

$pageTitle          = 'Accès Refusé (403)';
$activeNav          = '';
$requireAuth        = false;
$requiredPerms      = [];
$accessDeniedReason = null;

$errorReason = isset($forbiddenMessage) && is_string($forbiddenMessage) && $forbiddenMessage !== ''
    ? $forbiddenMessage
    : 'Accès refusé (403) : vous ne disposez pas des permissions nécessaires pour accéder à cette ressource.';
$isAuthenticated = !empty($_SESSION['user_id']) || !empty($_SESSION['is_superadmin']) || isset($_SESSION['sys_admin_id']);

ob_start();
?>
<div class="bg-white rounded-2xl shadow-sm border border-red-200 p-10 text-center max-w-lg mx-auto my-12 space-y-4">
    <div class="w-14 h-14 rounded-full bg-red-50 text-red-600 flex items-center justify-center mx-auto text-2xl font-bold">403</div>
    <h1 class="text-xl font-extrabold text-slate-900">Accès Refusé</h1>
    <p class="text-sm text-slate-600 leading-relaxed">
        <?= htmlspecialchars($errorReason, ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div class="flex justify-center gap-3 pt-2">
        <a href="/" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">
            Retour à l'accueil
        </a>
        <?php if (!$isAuthenticated): ?>
            <a href="/login" class="px-5 py-2.5 rounded-xl brand-primary-bg text-white text-xs font-bold shadow-xs transition">
                Se connecter
            </a>
        <?php endif; ?>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../../Templates/layout.php';
