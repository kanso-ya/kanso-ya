<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

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
$primaryHover = '#047857';
$cleanHex = ltrim(trim($primaryColor), '#');
if (strlen($cleanHex) === 3) {
    $cleanHex = $cleanHex[0] . $cleanHex[0] . $cleanHex[1] . $cleanHex[1] . $cleanHex[2] . $cleanHex[2];
}
if (preg_match('/^[0-9a-fA-F]{6}$/', $cleanHex)) {
    $r = max(0, (int) round(hexdec(substr($cleanHex, 0, 2)) * 0.86));
    $g = max(0, (int) round(hexdec(substr($cleanHex, 2, 2)) * 0.86));
    $b = max(0, (int) round(hexdec(substr($cleanHex, 4, 2)) * 0.86));
    $primaryHover = sprintf('#%02x%02x%02x', $r, $g, $b);
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
    <style id="kanso-dynamic-theme" data-testid="kanso-dynamic-theme">
        :root {
            --brand-primary: <?= htmlspecialchars($primaryColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-primary-hover: <?= htmlspecialchars($primaryHover, ENT_QUOTES, 'UTF-8') ?>;
            --brand-sidebar: <?= htmlspecialchars($sidebarColor, ENT_QUOTES, 'UTF-8') ?>;
            --brand-accent: <?= htmlspecialchars($accentColor, ENT_QUOTES, 'UTF-8') ?>;
        }
        .brand-primary-bg, .bg-brand-primary { background-color: var(--brand-primary) !important; }
        .brand-primary-bg:hover, .bg-brand-primary:hover { background-color: var(--brand-primary-hover) !important; }
        .brand-primary-text, .text-brand-primary { color: var(--brand-primary) !important; }
        .brand-primary-border, .border-brand-primary { border-color: var(--brand-primary) !important; }
        .brand-sidebar-bg { background-color: var(--brand-sidebar) !important; }
        .focus\:ring-brand-primary:focus { --tw-ring-color: var(--brand-primary) !important; }
    </style>
    <script type="module" src="/assets/ts/login.js" defer></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center p-4 relative antialiased">
    <div id="form-login-card" class="w-full max-w-md bg-white rounded-3xl shadow-xl border border-slate-200/80 overflow-hidden">
        <!-- Bandeau supérieur aux couleurs de la marque -->
        <div class="brand-sidebar-bg p-8 text-center text-white relative">
            <div class="inline-flex items-center justify-center mb-3">
                <?php if ($logoUrl !== ''): ?>
                    <div id="login-club-logo-frame" data-testid="login-club-logo" class="inline-flex items-center justify-center rounded-2xl overflow-hidden shadow-lg border-2 border-white/20 bg-slate-900/40 h-20 w-auto max-w-[220px]">
                        <img src="<?= htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8') ?>" alt="Logo <?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?>" class="block h-full w-auto max-w-[220px] object-cover rounded-2xl">
                    </div>
                <?php else: ?>
                    <div id="login-club-brand" data-testid="login-club-brand" class="px-5 py-3 rounded-2xl bg-brand-primary shadow-lg border border-white/20">
                        <span class="text-base font-black tracking-wide text-white"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
            </div>
            <h1 class="text-xl font-extrabold tracking-tight"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></h1>
            <p id="login-subtitle" class="text-xs text-slate-300 mt-1">Connexion sécurisée à votre Espace Club</p>
        </div>

        <!-- Formulaire de connexion -->
        <div class="p-8 space-y-6">
            <form id="login-form" class="space-y-4" novalidate>
                <div>
                    <label for="login-identifier" class="block text-xs font-semibold uppercase text-slate-600 mb-1.5">Adresse Email</label>
                    <input type="email" id="login-identifier" name="email" required autocomplete="username" placeholder="prenom.nom@domaine.fr"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary transition">
                </div>

                <div>
                    <div class="flex items-center justify-between mb-1.5">
                        <label for="login-password" class="block text-xs font-semibold uppercase text-slate-600">Mot de passe</label>
                        <button type="button" id="link-forgot-password" data-testid="link-forgot-password"
                                class="text-xs font-semibold text-brand-primary hover:underline transition">
                            Mot de passe oublié ?
                        </button>
                    </div>
                    <input type="password" id="login-password" name="password" required autocomplete="current-password" placeholder="••••••••"
                           class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary transition">
                </div>

                <button type="submit" id="btn-login-submit"
                        class="w-full py-3.5 px-4 bg-brand-primary text-white font-bold text-sm rounded-xl shadow-md transition flex items-center justify-center gap-2">
                    <span>Se connecter à mon compte</span>
                </button>
            </form>

            <!-- Parcours Mot de passe oublié -->
            <div id="forgot-password-panel" class="hidden space-y-4">
                <div class="p-3.5 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600 leading-relaxed">
                    Saisissez votre adresse e-mail de connexion. Si un compte actif y est associé, vous recevrez un code de sécurité à usage unique pour choisir un nouveau mot de passe.
                </div>

                <form id="forgot-password-form" data-testid="forgot-password-form" class="space-y-4" novalidate>
                    <div>
                        <label for="forgot-email-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1.5">Adresse Email de votre compte *</label>
                        <input type="email" id="forgot-email-input" data-testid="forgot-email-input" name="email" required placeholder="prenom.nom@domaine.fr"
                               class="w-full px-4 py-3 bg-slate-50 border border-slate-300 rounded-xl text-sm text-slate-800 placeholder-slate-400 focus:bg-white focus:outline-none focus:ring-2 focus:ring-brand-primary transition">
                    </div>

                    <button type="submit" id="btn-send-reset-code" data-testid="btn-send-reset-code"
                            class="w-full py-3.5 px-4 bg-brand-primary text-white font-bold text-sm rounded-xl shadow-md transition flex items-center justify-center gap-2">
                        <span>Recevoir mon code de réinitialisation</span>
                    </button>

                    <button type="button" id="btn-back-to-login"
                            class="w-full py-2 text-xs font-semibold text-slate-500 hover:text-slate-800 transition">
                        ← Revenir à la connexion
                    </button>
                </form>
            </div>

            <div class="pt-4 border-t border-slate-100 flex items-center justify-between text-xs text-slate-500">
                <a href="/" class="hover:text-slate-800 flex items-center gap-1 transition">
                    <span>← Retour au site public</span>
                </a>
                <a href="/register" class="font-semibold text-brand-primary hover:underline transition">
                    Inscription en ligne
                </a>
            </div>
        </div>
    </div>

    <div id="toast-container" data-testid="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>
</body>
</html>
