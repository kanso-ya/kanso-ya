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
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
    <title>Console Système - Kanso-ya - Clubs et Associations</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script type="module" src="/assets/ts/sys-login.js" defer></script>
</head>
<body class="bg-slate-950 text-slate-100 h-screen flex items-center justify-center">
    <div class="bg-slate-900 border border-red-900/50 p-8 rounded-lg shadow-2xl w-full max-w-sm">
        <div class="flex items-center justify-center mb-4">
            <span class="px-3 py-1 bg-red-600/20 text-red-400 border border-red-500/30 rounded text-xs font-semibold uppercase tracking-wider">Zone Superadmin</span>
        </div>
        <h1 class="text-xl font-bold text-center mb-6">Authentification Système</h1>
        <form id="sys-login-form" class="space-y-4">
            <div>
                <label class="block text-sm font-medium text-slate-400 mb-1">Identifiant Système</label>
                <input type="text" name="username" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded text-white focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-400 mb-1">Mot de passe</label>
                <input type="password" name="password" required class="w-full px-4 py-2 bg-slate-800 border border-slate-700 rounded text-white focus:outline-none focus:ring-2 focus:ring-red-500">
            </div>
            <button type="submit" id="btn-sys-submit" class="w-full bg-red-600 text-white font-bold py-2 rounded hover:bg-red-700 transition">Connexion Console</button>
        </form>
    </div>
    <div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2"></div>
</body>
</html>