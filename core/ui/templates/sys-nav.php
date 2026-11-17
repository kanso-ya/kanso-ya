<?php

declare(strict_types=1);

/**
 * Sous-navigation horizontale unifiée pour la Console Superadmin (Point 27).
 */
$currentSysNav = $currentSysNav ?? 'console';

$sysTabs = [
    'console'  => ['label' => '💻 1. Vue d\'ensemble & Sauvegarde', 'url' => '/sys-console'],
    'branding' => ['label' => '🎨 2. Identité & Couleurs',         'url' => '/sys-branding'],
    'smtp'     => ['label' => '✉️ 3. Messagerie & Templates SMTP', 'url' => '/sys-smtp'],
    'modules'  => ['label' => '🧩 4. Moteur de Modules',           'url' => '/sys-modules'],
    'logs'     => ['label' => '📜 5. Journaux & Audit',            'url' => '/sys-logs'],
];
?>
<nav class="flex items-center gap-2 overflow-x-auto pb-2 border-b border-slate-800 text-xs font-bold" aria-label="Sous-navigation Administration">
    <?php foreach ($sysTabs as $key => $tab): ?>
        <?php $isActive = ($currentSysNav === $key); ?>
        <a href="<?= $tab['url'] ?>"
           class="px-4 py-2 rounded-xl transition whitespace-nowrap <?= $isActive ? 'bg-emerald-600 text-white shadow-xs font-extrabold' : 'bg-slate-900 hover:bg-slate-800 text-slate-300 border border-slate-800' ?>"
           <?= $isActive ? 'aria-current="page"' : '' ?>>
            <?= $tab['label'] ?>
        </a>
    <?php endforeach; ?>
</nav>
