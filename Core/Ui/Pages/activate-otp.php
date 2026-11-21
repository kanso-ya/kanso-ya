<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$isResetMode = (string) ($_GET['mode'] ?? '') === 'reset';
$pageTitle   = $isResetMode ? 'Réinitialisation de votre mot de passe' : 'Activation de votre compte adhérent';
$activeNav   = 'home';
$requireAuth = false;

$prefilledOtp = htmlspecialchars(trim((string) ($_GET['otp'] ?? '')), ENT_QUOTES, 'UTF-8');

ob_start();
?>
<div class="max-w-md mx-auto my-8 bg-white p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
    <div class="text-center space-y-2">
        <span class="px-3 py-1 rounded-full bg-emerald-50 text-brand-primary text-xs font-bold uppercase tracking-wider">
            <?= $isResetMode ? 'Réinitialisation sécurisée' : 'Activation sécurisée (Validité 48h)' ?>
        </span>
        <h1 class="text-2xl font-extrabold text-slate-900">
            <?= $isResetMode ? 'Choisissez un nouveau mot de passe' : 'Activez votre Espace Adhérent' ?>
        </h1>
        <p class="text-xs text-slate-500">
            <?= $isResetMode
                ? 'Saisissez le code de sécurité à usage unique reçu par e-mail, puis définissez et confirmez votre nouveau mot de passe.'
                : 'Saisissez le code de sécurité à usage unique reçu suite à la validation de votre dossier par le bureau, puis définissez et confirmez votre mot de passe.' ?>
        </p>
    </div>

    <div id="password-requirements-card" data-testid="pwd-requirements-card" class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1 text-left">
        <span class="font-bold block">Règles de sécurité requises pour le mot de passe :</span>
        <ul class="list-disc list-inside space-y-0.5 text-[11px] text-amber-800">
            <li>Minimum 8 caractères</li>
            <li>Au moins une lettre majuscule (A-Z) et une minuscule (a-z)</li>
            <li>Au moins un chiffre (0-9)</li>
            <li>Au moins un caractère spécial (!@#$%^&*...)</li>
        </ul>
    </div>

    <form id="otp-activation-form" data-testid="reset-password-form" class="space-y-4" novalidate>
        <div>
            <label for="otp-code-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Code de sécurité reçu par e-mail *</label>
            <input type="text" id="otp-code-input" data-testid="reset-otp-input" name="otp_code" value="<?= $prefilledOtp ?>" required placeholder="Collez votre code de sécurité ici..." class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-mono bg-slate-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 focus:outline-none">
        </div>

        <div>
            <label for="otp-new-password" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nouveau mot de passe *</label>
            <input type="password" id="otp-new-password" data-testid="reset-new-password" name="new_password" required minlength="8" placeholder="••••••••" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:outline-none">
        </div>

        <div>
            <label for="otp-confirm-password" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Confirmez votre nouveau mot de passe *</label>
            <input type="password" id="otp-confirm-password" data-testid="reset-confirm-password" name="confirm_password" required minlength="8" placeholder="Saisissez le mot de passe une seconde fois" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:outline-none">
        </div>

        <button type="submit" id="btn-submit-otp" data-testid="btn-confirm-reset-password" class="w-full bg-brand-primary text-white py-3 rounded-xl text-sm font-bold shadow-sm transition">
            <?= $isResetMode ? 'Enregistrer mon nouveau mot de passe' : 'Activer mon compte & Enregistrer mon mot de passe' ?>
        </button>
    </form>
</div>

<script type="module">
import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

document.getElementById('otp-activation-form')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const btn = document.getElementById('btn-submit-otp');
    const otpInput = document.getElementById('otp-code-input');
    const pwdInput = document.getElementById('otp-new-password');
    const confirmInput = document.getElementById('otp-confirm-password');

    const otp_code = otpInput.value.trim();
    const new_password = pwdInput.value;
    const confirm_password = confirmInput.value;

    if (!otp_code) {
        otpInput.setAttribute('aria-invalid', 'true');
        showToast('Veuillez saisir le code de sécurité reçu par e-mail.', 'error');
        return;
    }
    otpInput.removeAttribute('aria-invalid');

    if (new_password.length < 8) {
        pwdInput.setAttribute('aria-invalid', 'true');
        showToast('Le mot de passe doit comporter au moins 8 caractères.', 'error');
        return;
    }

    if (!/[A-Z]/.test(new_password) || !/[a-z]/.test(new_password) || !/[0-9]/.test(new_password) || !/[^A-Za-z0-9]/.test(new_password)) {
        pwdInput.setAttribute('aria-invalid', 'true');
        showToast('Le mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.', 'error');
        return;
    }
    pwdInput.removeAttribute('aria-invalid');

    if (!confirm_password || new_password !== confirm_password) {
        confirmInput.setAttribute('aria-invalid', 'true');
        showToast('Les deux saisies du mot de passe ne correspondent pas.', 'error');
        return;
    }
    confirmInput.removeAttribute('aria-invalid');

    const defaultLabel = btn.textContent.trim();
    setButtonLoading(btn, true, defaultLabel);
    try {
        const res = await fetchApi('/api/v1/auth/activate-otp', {
            method: 'POST',
            body: JSON.stringify({ otp_code, new_password, confirm_password })
        });
        if (res.status === 'success') {
            showToast('Compte activé et mot de passe enregistré avec succès ! Redirection vers la connexion...', 'success');
            setTimeout(() => {
                window.location.href = '/login';
            }, 1200);
        }
    } finally {
        setButtonLoading(btn, false, defaultLabel);
    }
});
</script>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';