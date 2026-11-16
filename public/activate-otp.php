<?php

declare(strict_types=1);

$pageTitle   = 'Activation de votre Compte Adhérent (OTP)';
$activeNav   = 'home';
$requireAuth = false;

$prefilledOtp = htmlspecialchars(trim((string) ($_GET['otp'] ?? '')), ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="max-w-md mx-auto my-8 bg-white p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
    <div class="text-center space-y-2">
        <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold uppercase tracking-wider">
            Onboarding Sécurisé (Validité 48h)
        </span>
        <h1 class="text-2xl font-extrabold text-slate-900">Activez votre Espace Adhérent</h1>
        <p class="text-xs text-slate-500">
            Saisissez le jeton à usage unique (OTP) reçu suite à la validation de votre dossier par le bureau, puis choisissez votre mot de passe définitif.
        </p>
    </div>

    <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1 text-left">
        <span class="font-bold block">Règles de complexité requises pour le mot de passe :</span>
        <ul class="list-disc list-inside space-y-0.5 text-[11px] text-amber-800">
            <li>Minimum 8 caractères</li>
            <li>Au moins une lettre majuscule (A-Z) et une minuscule (a-z)</li>
            <li>Au moins un chiffre (0-9)</li>
            <li>Au moins un caractère spécial (!@#$%^&*...)</li>
        </ul>
    </div>

    <form id="otp-activation-form" class="space-y-4">
        <div>
            <label for="otp-code-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Jeton d'activation (OTP) *</label>
            <input type="text" id="otp-code-input" name="otp_code" value="<?= $prefilledOtp ?>" required placeholder="Collez votre code OTP ici..." class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-mono bg-slate-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
        </div>

        <div>
            <label for="otp-new-password" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Votre nouveau mot de passe *</label>
            <input type="password" id="otp-new-password" name="new_password" required minlength="8" placeholder="••••••••" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:outline-none">
        </div>

        <button type="submit" id="btn-submit-otp" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white py-3 rounded-xl text-sm font-bold shadow-sm transition">
            Activer mon compte & Enregistrer mon mot de passe
        </button>
    </form>
</div>

<script type="module">
import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

document.getElementById('otp-activation-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-otp');
    const otp_code = document.getElementById('otp-code-input').value.trim();
    const new_password = document.getElementById('otp-new-password').value;

    if (new_password.length < 8) {
        showToast('Le mot de passe doit comporter au moins 8 caractères.', 'error');
        return;
    }

    setButtonLoading(btn, true, 'Activer mon compte');
    try {
        const res = await fetchApi('/api/v1/auth/activate-otp', {
            method: 'POST',
            body: JSON.stringify({ otp_code, new_password })
        });
        if (res.status === 'success') {
            showToast('Compte activé avec succès ! Redirection vers la page de connexion...', 'success');
            setTimeout(() => {
                window.location.href = '/login';
            }, 1200);
        }
    } finally {
        setButtonLoading(btn, false, 'Activer mon compte & Enregistrer mon mot de passe');
    }
});
</script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';