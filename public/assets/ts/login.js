/**
 * @file login.ts
 * @description Logique d'authentification sur /login avec redirection vers /dashboard.php en cas de succès.
 */
import { fetchApi } from './api.js';
import { showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('login-form');
    if (!form)
        return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        const res = await fetchApi('/api/v1/auth/login', {
            method: 'POST',
            body: JSON.stringify(payload)
        });
        if (res.status === 'success') {
            showToast('Connexion réussie ! Redirection...', 'success');
            const role = res.data?.role;
            const targetUrl = role === 'Superadmin' ? '/sys-console' : '/';
            setTimeout(() => {
                window.location.href = targetUrl;
            }, 500);
        }
    });
});
