/**
 * @file sys-login.ts
 * @description Logique d'authentification furtive pour le Superadmin (Shadow Login).
 */
import { fetchApi } from './api.js';
import { showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('sys-login-form');
    const submitBtn = document.getElementById('btn-sys-submit');
    if (form && submitBtn) {
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.textContent = 'Vérification...';
            const formData = new FormData(form);
            const payload = Object.fromEntries(formData.entries());
            const res = await fetchApi('/api/v1/sys/auth/login', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            submitBtn.disabled = false;
            submitBtn.textContent = 'Connexion Console';
            if (res.status === 'success') {
                showToast('Accès technique autorisé', 'success');
                setTimeout(() => window.location.href = '/dashboard.php', 800);
            }
        });
    }
});
