/**
 * @file login.ts
 * @description Logique d'authentification sur /login et parcours "Mot de passe oublié ?"
 * avec validation systématique par Toast et redirection anti-énumération vers /activate-otp.
 */

import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';

document.addEventListener('DOMContentLoaded', () => {
    const loginForm = document.getElementById('login-form') as HTMLFormElement | null;
    const forgotPanel = document.getElementById('forgot-password-panel');
    const forgotForm = document.getElementById('forgot-password-form') as HTMLFormElement | null;
    const linkForgot = document.getElementById('link-forgot-password');
    const btnBackLogin = document.getElementById('btn-back-to-login');
    const subtitleEl = document.getElementById('login-subtitle');

    if (loginForm) {
        loginForm.addEventListener('submit', async (e: Event) => {
            e.preventDefault();

            const emailInput = document.getElementById('login-identifier') as HTMLInputElement | null;
            const pwdInput = document.getElementById('login-password') as HTMLInputElement | null;
            const submitBtn = document.getElementById('btn-login-submit') as HTMLButtonElement | null;

            const email = emailInput?.value.trim() || '';
            const password = pwdInput?.value || '';

            if (!email) {
                emailInput?.setAttribute('aria-invalid', 'true');
                showToast('Veuillez saisir votre adresse e-mail de connexion.', 'error');
                return;
            }
            if (!password) {
                pwdInput?.setAttribute('aria-invalid', 'true');
                showToast('Veuillez saisir votre mot de passe.', 'error');
                return;
            }

            emailInput?.removeAttribute('aria-invalid');
            pwdInput?.removeAttribute('aria-invalid');

            setButtonLoading(submitBtn, true, 'Se connecter à mon compte');
            try {
                const res = await fetchApi('/api/v1/auth/login', {
                    method: 'POST',
                    body: JSON.stringify({ email, password })
                });

                if (res.status === 'success') {
                    showToast('Connexion réussie ! Redirection...', 'success');
                    const role = (res.data as { role?: string } | undefined)?.role;
                    const targetUrl = role === 'Superadmin' ? '/sys-console' : '/';
                    setTimeout(() => {
                        window.location.href = targetUrl;
                    }, 500);
                }
            } finally {
                setButtonLoading(submitBtn, false, 'Se connecter à mon compte');
            }
        });
    }

    linkForgot?.addEventListener('click', () => {
        loginForm?.classList.add('hidden');
        forgotPanel?.classList.remove('hidden');
        if (subtitleEl) subtitleEl.textContent = 'Récupération de votre accès';
        const emailVal = (document.getElementById('login-identifier') as HTMLInputElement | null)?.value.trim();
        const forgotInput = document.getElementById('forgot-email-input') as HTMLInputElement | null;
        if (emailVal && forgotInput && !forgotInput.value) {
            forgotInput.value = emailVal;
        }
    });

    btnBackLogin?.addEventListener('click', () => {
        forgotPanel?.classList.add('hidden');
        loginForm?.classList.remove('hidden');
        if (subtitleEl) subtitleEl.textContent = 'Connexion sécurisée à votre Espace Club';
    });

    forgotForm?.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        const emailInput = document.getElementById('forgot-email-input') as HTMLInputElement | null;
        const submitBtn = document.getElementById('btn-send-reset-code') as HTMLButtonElement | null;
        const email = emailInput?.value.trim() || '';

        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            emailInput?.setAttribute('aria-invalid', 'true');
            showToast('Veuillez saisir une adresse e-mail valide.', 'error');
            return;
        }
        emailInput?.removeAttribute('aria-invalid');

        setButtonLoading(submitBtn, true, 'Recevoir mon code de réinitialisation');
        try {
            const res = await fetchApi('/api/v1/auth/forgot-password', {
                method: 'POST',
                body: JSON.stringify({ email })
            });

            if (res.status === 'success') {
                showToast(
                    res.message || 'Si un compte est associé à cette adresse e-mail, un code de réinitialisation vient de vous être envoyé.',
                    'success'
                );
                setTimeout(() => {
                    window.location.href = '/activate-otp?mode=reset';
                }, 900);
            }
        } finally {
            setButtonLoading(submitBtn, false, 'Recevoir mon code de réinitialisation');
        }
    });
});