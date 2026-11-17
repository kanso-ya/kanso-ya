<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$siteName     = 'Kanso-ya - Clubs et Associations';
$logoUrl      = '';
$primaryColor = '#059669';
$sidebarColor = '#0f172a';
$accentColor  = '#10b981';

try {
    $db = \Core\Api\Database\Database::getInstance();
    $rows = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
    if (is_array($rows)) {
        $siteName     = (string) ($rows['site_name'] ?? $siteName);
        $logoUrl      = (string) ($rows['site_logo_url'] ?? '');
        $primaryColor = (string) ($rows['theme_primary_color'] ?? $primaryColor);
        $sidebarColor = (string) ($rows['theme_sidebar_color'] ?? $sidebarColor);
        $accentColor  = (string) ($rows['theme_accent_color'] ?? $accentColor);
    }
} catch (Throwable) {
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8') ?>">
    <title>Connexion à l'Espace Club — <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        :root {
            --brand-primary: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-sidebar: <?= htmlspecialchars($sidebarColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>;
        }
        .brand-primary-bg { background-color: var(--brand-primary); }
        .brand-primary-text { color: var(--brand-primary); }
        .brand-sidebar-bg { background-color: var(--brand-sidebar); }
    </style>
    <script type="module" src="/assets/ts/login.js" defer></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4 relative antialiased">
    <div class="w-full max-w-md bg-white rounded-3xl shadow-xl border border-slate-200/80 overflow-hidden">
        <!-- Bandeau supérieur aux couleurs de la marque -->
        <div class="brand-sidebar-bg p-8 text-center text-white relative">
            <div class="inline-flex items-center justify-center mb-3">
                <?php if ($logoUrl !== ''): ?>
                    <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo" class="max-h-16 max-w-[200px] object-contain">
                <?php else: ?>
                    <div class="w-16 h-16 rounded-2xl brand-primary-bg flex items-center justify-center shadow-lg border border-white/20">
                        <span class="text-xl font-black tracking-widest text-white">KY</span>
                    </div>
                <?php endif; ?>
            </div>
            <h1 class="text-xl font-extrabold tracking-tight"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p class="text-xs text-slate-300 mt-1">Connexion sécurisée à votre Espace Club</p>
        </div>

        <!-- Formulaire de connexion -->
        <div class="p-8 space-y-6">
            <form id="login-form" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1.5">Adresse Email</label>
                    <input type="text" id="login-identifier" name="email" required autocomplete="username" placeholder="prenom.nom@domaine.fr"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label class="block text-xs font-semibold uppercase text-slate-600">Mot de passe</label>
                    </div>
                    <input type="password" id="login-password" name="password" required autocomplete="current-password" placeholder="••••••••"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-emerald-500 transition">
                </div>

                <button type="submit"
                        class="w-full py-3.5 px-4 brand-primary-bg hover:opacity-95 text-white font-bold text-sm rounded-xl shadow-md transition flex items-center justify-center gap-2">
                    <span>Se connecter à mon compte</span>
                </button>
            </form>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <a href="/" class="hover:text-slate-800 flex items-center gap-1 transition">
                    <span>← Retour au site public</span>
                </a>
                <a href="/register" class="font-semibold brand-primary-text hover:underline transition">
                    Inscription en ligne
                </a>
            </div>
        </div>
    </div>

    <div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>
</body>
</html>
