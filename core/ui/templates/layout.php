<?php

declare(strict_types=1);

/**
 * Gabarit Maître MPA (Mode Production) :
 * - Aucun sélecteur de simulation QA visible à l'écran
 * - Démarrage par défaut en mode Visiteur non connecté tant qu'aucune connexion n'a eu lieu
 * - Logo à cheval sur le bandeau supérieur (Header) et la Sidebar
 * - Nom du site centré sur la barre supérieure.
 */

require_once __DIR__ . '/../../Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$pageTitle     = $pageTitle ?? 'Kanso-ya CMS';
$activeNav     = $activeNav ?? 'home';
$pageContent   = $pageContent ?? '';
$pageScripts   = $pageScripts ?? [];
$requireAuth   = $requireAuth ?? false;
$requiredPerms = $requiredPerms ?? [];

// En production : si aucune session n'est ouverte, l'utilisateur est Visiteur (0)
$currentUserId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : 1;
$currentRole   = isset($_SESSION['role_name']) ? (string) $_SESSION['role_name'] : 'Président';
$isSuperadmin  = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));
$isLoggedIn    = ($currentRole !== 'Visiteur' && $currentRole !== '' && $currentUserId > 0);

$sysConfig = [
    'site_name'              => 'Kanso-ya - Clubs et Associations',
    'site_logo_url'          => '',
    'site_watermark_url'     => '',
    'site_watermark_opacity' => '0.05',
    'theme_primary_color'    => '#059669',
    'theme_sidebar_color'    => '#0f172a',
    'theme_header_color'     => '#ffffff',
    'theme_accent_color'     => '#10b981'
];

$currentUserLabel = '';
try {
    $db = Database::getInstance();
    $rows = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (is_array($rows)) {
        $sysConfig = array_merge($sysConfig, $rows);
    }

    if ($isLoggedIn) {
        if ($isSuperadmin) {
            $currentUserLabel = 'Superadmin';
        } else {
            $stmtU = $db->prepare("SELECT first_name, last_name, email FROM users WHERE id = :id");
            $stmtU->execute(['id' => $currentUserId]);
            $uRow = $stmtU->fetch(PDO::FETCH_ASSOC);
            if ($uRow) {
                $fullName = trim(($uRow['first_name'] ?? '') . ' ' . ($uRow['last_name'] ?? ''));
                $currentUserLabel = $fullName !== '' ? $fullName : (string) $uRow['email'];
            }
        }
    }
} catch (Throwable) {
}

$userPerms = [];
if ($isSuperadmin) {
    $userPerms = ['system.admin', 'settings.manage', 'logs.view'];
} elseif ($currentRole === 'Président') {
    $userPerms = ['members.view', 'members.edit', 'roles.manage', 'finances.manage', 'articles.write', 'articles.publish', 'pages.manage', 'settings.manage', 'logs.view', 'stats.view'];
} elseif ($currentRole === 'Secrétaire') {
    $userPerms = ['members.view', 'members.edit', 'articles.write', 'articles.publish', 'stats.view'];
} elseif ($currentRole === 'Trésorier') {
    $userPerms = ['members.view', 'finances.manage'];
} elseif ($currentRole === 'Rédacteur') {
    $userPerms = ['articles.write'];
} elseif ($currentRole === 'Entraîneur') {
    $userPerms = ['members.view', 'articles.write', 'stats.view'];
} elseif ($currentRole === 'Adhérent') {
    $userPerms = ['profile.view'];
}

$accessDeniedReason = null;
if ($requireAuth && !$isLoggedIn) {
    $accessDeniedReason = 'Veuillez vous connecter avec vos identifiants pour accéder à cet espace.';
} elseif (!empty($requiredPerms) && !$isSuperadmin) {
    $hasPerm = false;
    foreach ($requiredPerms as $p) {
        if (in_array($p, $userPerms, true)) {
            $hasPerm = true;
            break;
        }
    }
    if (!$hasPerm) {
        $accessDeniedReason = "Accès restreint (403) : votre compte ({$currentRole}) ne dispose pas des permissions nécessaires.";
    }
}

$siteName      = (string) ($sysConfig['site_name'] ?: 'Kanso-ya - Clubs et Associations');
$logoUrl       = (string) ($sysConfig['site_logo_url'] ?? '');
$watermarkUrl  = (string) ($sysConfig['site_watermark_url'] ?? '');
$watermarkOpac = (float) ($sysConfig['site_watermark_opacity'] ?? 0.05);
$primaryColor  = (string) ($sysConfig['theme_primary_color'] ?: '#059669');
$sidebarColor  = $isSuperadmin ? '#090d16' : (string) ($sysConfig['theme_sidebar_color'] ?: '#0f172a');
$headerColor   = $isSuperadmin ? '#090d16' : (string) ($sysConfig['theme_header_color'] ?: '#ffffff');
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-sidebar: <?= htmlspecialchars($sidebarColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-header: <?= htmlspecialchars($headerColor, ENT_QUOTES, 'UTF-8') ?>;
        }
        .brand-sidebar-bg { background-color: var(--brand-sidebar); }
        .brand-header-bg { background-color: var(--brand-header); }
        .brand-primary-bg { background-color: var(--brand-primary); }
    </style>
    <script type="module" src="/assets/ts/main.js" defer></script>
    <?php if ($accessDeniedReason === null): ?>
        <?php foreach ($pageScripts as $scriptUrl): ?>
            <script type="module" src="<?= htmlspecialchars($scriptUrl, ENT_QUOTES, 'UTF-8') ?>" defer></script>
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body id="app-body" class="<?= $isSuperadmin ? 'bg-slate-950 text-slate-100' : 'bg-slate-100 text-slate-800' ?> font-sans antialiased h-screen overflow-hidden flex flex-col relative">

    <?php if ($watermarkUrl !== ''): ?>
        <div class="pointer-events-none fixed inset-0 z-0 bg-center bg-no-repeat bg-contain"
             style="background-image: url('<?= htmlspecialchars($watermarkUrl, ENT_QUOTES, 'UTF-8') ?>'); opacity: <?= $watermarkOpac ?>;"></div>
    <?php endif; ?>

    <!-- LOGO À CHEVAL SUR LE BANDEAU SUPÉRIEUR ET LA SIDEBAR -->
    <a href="/" id="overlapping-logo-badge" class="hidden lg:flex fixed top-2.5 left-4 w-56 h-[88px] z-40 rounded-2xl shadow-xl border-2 <?= $isSuperadmin ? 'bg-slate-900 border-red-700/80' : 'bg-slate-900 border-emerald-500/70' ?> flex-col items-center justify-center overflow-hidden hover:scale-[1.02] transition">
        <?php if ($logoUrl !== ''): ?>
            <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="max-h-16 max-w-[180px] object-contain">
        <?php else: ?>
            <span class="text-lg font-black tracking-widest text-white">KANSO-YA</span>
            <span class="text-[10px] uppercase tracking-wider text-emerald-400 font-semibold">Dojo & Arts Martiaux</span>
        <?php endif; ?>
    </a>

    <!-- 1. BANNIÈRE SUPÉRIEURE (HEADER FIXE) AVEC NOM DU SITE CENTRÉ -->
    <header id="app-header" class="h-16 shrink-0 brand-header-bg <?= $isSuperadmin ? 'border-slate-800 text-white' : 'border-slate-200 text-slate-800' ?> border-b flex items-center justify-between px-6 z-30 shadow-xs relative">
        <div class="flex items-center gap-3 lg:w-64">
            <button id="mobile-menu-btn" type="button" aria-label="Menu" class="lg:hidden p-2 rounded-lg bg-slate-100 text-slate-700 hover:bg-slate-200 transition">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"></path>
                </svg>
            </button>
        </div>

        <!-- NOM DU SITE DÉCALÉ AU CENTRE -->
        <div class="absolute left-1/2 -translate-x-1/2 flex items-center gap-3 text-center pointer-events-none">
            <span id="club-name-heading" class="text-lg sm:text-2xl font-extrabold tracking-tight <?= $isSuperadmin ? 'text-white' : 'text-slate-900' ?>">
                <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>
            </span>
        </div>

        <!-- DROITE : STATUT DU COMPTE CONNECTÉ (SANS SÉLECTEUR DE TRICHE QA) -->
        <div class="flex items-center gap-3">
            <?php if ($isLoggedIn): ?>
                <a href="/dashboard.php" class="flex items-center gap-2.5 px-3.5 py-1.5 rounded-xl border <?= $isSuperadmin ? 'bg-slate-900 border-red-800 text-red-300' : 'bg-slate-50 border-slate-200 text-slate-700 hover:bg-slate-100' ?> transition">
                    <span class="w-2 h-2 rounded-full bg-emerald-500"></span>
                    <span class="text-xs font-bold"><?= htmlspecialchars($currentUserLabel, ENT_QUOTES, 'UTF-8') ?></span>
                    <span id="active-role-pill" class="px-2 py-0.5 rounded-full text-[11px] font-extrabold <?= $isSuperadmin ? 'bg-red-950 text-red-300' : 'bg-emerald-100 text-emerald-800' ?>">
                        <?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </a>
            <?php else: ?>
                <span id="active-role-pill" class="hidden">Visiteur Public</span>
                <a href="/login.php" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold transition shadow-2xs">
                    Espace Membre
                </a>
            <?php endif; ?>
        </div>
    </header>

    <div id="sidebar-backdrop" class="fixed inset-0 bg-slate-900/50 z-20 hidden lg:hidden"></div>

    <!-- 2. CONTENEUR SOUS HEADER : SIDEBAR FIXE + MAIN WORKSPACE SCROLLABLE -->
    <div class="flex flex-1 overflow-hidden relative z-10">

        <aside id="sidebar" class="w-64 brand-sidebar-bg text-slate-300 flex-shrink-0 fixed lg:static inset-y-0 left-0 top-16 lg:top-0 z-20 h-[calc(100vh-4rem)] transform -translate-x-full lg:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between border-r border-slate-800">

            <div class="p-4 lg:pt-12 border-b border-slate-800 space-y-3 shrink-0">
                <div id="sidebar-auth-zone">
                    <?php if ($isLoggedIn): ?>
                        <button id="btn-logout" data-action="logout" type="button" class="w-full py-2.5 px-4 bg-red-600/90 hover:bg-red-600 text-white font-semibold text-xs uppercase tracking-wider rounded-xl shadow-sm transition flex items-center justify-center gap-2">
                            <span>Déconnexion</span>
                        </button>
                    <?php else: ?>
                        <a id="btn-sidebar-login" href="/login.php" class="w-full py-2.5 px-4 brand-primary-bg hover:opacity-95 text-white font-semibold text-xs uppercase tracking-wider rounded-xl shadow-sm transition text-center block">
                            Se connecter
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <nav id="sidebar-nav" class="flex-1 overflow-y-auto p-4 space-y-1 text-sm">
                <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-3 pb-1">Portail Club</div>
                <a href="/" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'home' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                    Accueil & Actualités
                </a>
                <a href="/register.php" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'register' ? 'brand-primary-bg text-white font-semibold' : 'text-emerald-400 hover:bg-slate-800' ?>">
                    Inscription en ligne
                </a>

                <?php if ($isSuperadmin): ?>
                    <div class="pt-4 space-y-1">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-red-400 px-3 pb-1">Console Technique</div>
                        <a href="/sys-console.php" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'sys-console' ? 'bg-red-700 text-white font-semibold' : 'hover:bg-slate-800 text-red-300' ?>">
                            Configuration & SMTP
                        </a>
                        <a href="/cms.php" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'cms' ? 'bg-red-700 text-white font-semibold' : 'hover:bg-slate-800 text-slate-300' ?>">
                            Articles & Pages
                        </a>
                    </div>
                <?php elseif ($isLoggedIn): ?>
                    <div class="pt-4 space-y-1">
                        <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-3 pb-1">Espace Personnel</div>
                        <a href="/dashboard.php" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'dashboard' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                            Mon profil
                        </a>

                        <?php if ($currentRole === 'Adhérent'): ?>
                            <a href="/members.php" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'members' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                Mes rattachements
                            </a>
                        <?php endif; ?>

                        <?php if ($currentRole !== 'Adhérent'): ?>
                            <div class="text-[11px] font-bold uppercase tracking-wider text-slate-500 px-3 pt-3 pb-1">Gestion Associative</div>

                            <?php if (in_array('stats.view', $userPerms, true)): ?>
                                <a href="/stats.php" data-perm="stats.view" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'stats' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Statistiques
                                </a>
                            <?php endif; ?>

                            <?php if (in_array('members.view', $userPerms, true)): ?>
                                <a href="/members.php" data-perm="members.view" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'members' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Adhérents
                                </a>
                            <?php endif; ?>

                            <?php if (in_array('finances.manage', $userPerms, true)): ?>
                                <a href="/registrations.php" data-perm="finances.manage" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'registrations' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Cotisations & Dossiers
                                </a>
                            <?php endif; ?>

                            <?php if (in_array('articles.write', $userPerms, true) || in_array('articles.publish', $userPerms, true) || in_array('pages.manage', $userPerms, true)): ?>
                                <a href="/cms.php" data-perm="articles.write" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'cms' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Articles & Pages
                                </a>
                            <?php endif; ?>

                            <?php if (in_array('roles.manage', $userPerms, true)): ?>
                                <a href="/disciplines.php" data-perm="roles.manage" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'disciplines' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Disciplines & Grades
                                </a>
                                <a href="/roles.php" data-perm="roles.manage" class="block px-3.5 py-2.5 rounded-xl transition <?= $activeNav === 'roles' ? 'brand-primary-bg text-white font-semibold' : 'hover:bg-slate-800 hover:text-white' ?>">
                                    Rôles & Permissions
                                </a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div id="module-hooks-zone" class="pt-2"></div>
            </nav>

            <footer class="p-4 border-t border-slate-800 bg-slate-950/50 text-xs text-slate-400 space-y-2 shrink-0">
                <div class="grid grid-cols-2 gap-1.5 font-medium">
                    <a href="/legal.php?slug=horaires" class="hover:text-emerald-400 transition">Horaires & Tarifs</a>
                    <a href="/legal.php?slug=mentions-legales" class="hover:text-emerald-400 transition">Mentions légales</a>
                    <a href="/legal.php?slug=rgpd" class="hover:text-emerald-400 transition">Politique RGPD</a>
                    <a href="/legal.php?slug=cgv" class="hover:text-emerald-400 transition">CGV</a>
                </div>
                <div class="text-[11px] text-slate-600 pt-1 border-t border-slate-800/80 flex justify-between">
                    <span><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
                    <span>2026-2027</span>
                </div>
            </footer>
        </aside>

        <main id="main-workspace" class="flex-1 overflow-y-auto p-6 relative">
            <div class="max-w-6xl mx-auto">
                <?php if ($accessDeniedReason !== null): ?>
                    <div class="bg-white rounded-2xl shadow-sm border border-red-200 p-10 text-center max-w-lg mx-auto my-12 space-y-4">
                        <div class="w-14 h-14 rounded-full bg-red-50 text-red-600 flex items-center justify-center mx-auto text-2xl font-bold">🔒</div>
                        <h1 class="text-xl font-extrabold text-slate-900">Accès Protégé</h1>
                        <p class="text-sm text-slate-600 leading-relaxed">
                            <?= htmlspecialchars($accessDeniedReason, ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="flex justify-center gap-3 pt-2">
                            <a href="/" class="px-4 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition">
                                Retour à l'accueil
                            </a>
                            <?php if (!$isLoggedIn): ?>
                                <a href="/login.php" class="px-5 py-2.5 rounded-xl brand-primary-bg text-white text-xs font-bold shadow-xs transition">
                                    Se connecter
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <?= $pageContent ?>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <?php require __DIR__ . '/../components/feedback.php'; ?>
</body>
</html>