/**
 * @file dashboard.ts
 * @description Logique de la page "Mon profil" à 2 onglets (Point 4)
 * et des paramètres métier du Président / Trésorier / Secrétaire (Points 1 & 3).
 */
import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    initProfileTabs();
    loadMyProfile();
    initProfileForm();
    initTwoFactorControls();
    initPasswordForm();
    initClubBusinessSettingsForm();
    window.addEventListener('kanso:auth-ready', async (e) => {
        const ctx = e.detail;
        if (ctx.role === 'Président') {
            await loadAuditLogs();
        }
    });
});
function initProfileTabs() {
    const btnInfo = document.getElementById('tab-btn-profile-info');
    const btnSec = document.getElementById('tab-btn-profile-security');
    const panelInfo = document.getElementById('tab-panel-profile-info');
    const panelSec = document.getElementById('tab-panel-profile-security');
    if (!btnInfo || !btnSec || !panelInfo || !panelSec)
        return;
    btnInfo.addEventListener('click', () => {
        btnInfo.className = 'px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition';
        btnSec.className = 'px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition';
        panelInfo.classList.remove('hidden');
        panelSec.classList.add('hidden');
    });
    btnSec.addEventListener('click', () => {
        btnSec.className = 'px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition';
        btnInfo.className = 'px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition';
        panelSec.classList.remove('hidden');
        panelInfo.classList.add('hidden');
    });
}
async function loadMyProfile() {
    const res = await fetchApi('/api/v1/users/me/profile');
    if (res.status !== 'success' || !res.data)
        return;
    const p = res.data;
    document.getElementById('prof-first-name').value = p.first_name || '';
    document.getElementById('prof-last-name').value = p.last_name || '';
    document.getElementById('prof-email').value = p.email || '';
    document.getElementById('prof-phone').value = p.phone || '';
    document.getElementById('prof-address').value = p.address || '';
    const container = document.getElementById('custom-fields-container');
    if (container) {
        container.innerHTML = '';
        (p.custom_fields || []).forEach((cf) => appendCustomFieldRow(cf.label, cf.value));
    }
    const secretEl = document.getElementById('totp-secret-display');
    if (secretEl)
        secretEl.textContent = p.two_factor_secret || 'KANSO2FA79100';
    update2faUiState(p.two_factor_enabled);
}
function appendCustomFieldRow(label = '', value = '') {
    const container = document.getElementById('custom-fields-container');
    if (!container)
        return;
    const row = document.createElement('div');
    row.className = 'custom-field-row grid grid-cols-12 gap-2 items-center bg-slate-50 p-2.5 rounded-xl border border-slate-200';
    row.innerHTML = `
        <input type="text" value="${label}" placeholder="Nom du champ (ex: Licence)" class="cf-label col-span-5 bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs font-semibold">
        <input type="text" value="${value}" placeholder="Valeur" class="cf-value col-span-6 bg-white border border-slate-300 rounded-lg px-3 py-1.5 text-xs">
        <button type="button" class="btn-remove-cf col-span-1 text-red-600 hover:bg-red-50 py-1.5 rounded-lg text-xs font-bold" title="Supprimer ce champ">✕</button>
    `;
    row.querySelector('.btn-remove-cf')?.addEventListener('click', () => row.remove());
    container.appendChild(row);
}
function initProfileForm() {
    const addBtn = document.getElementById('btn-add-custom-field');
    const form = document.getElementById('form-my-profile');
    const saveBtn = document.getElementById('btn-save-profile');
    addBtn?.addEventListener('click', () => appendCustomFieldRow());
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(saveBtn, true, 'Enregistrer les informations de mon profil');
        const custom_fields = [];
        document.querySelectorAll('.custom-field-row').forEach((row) => {
            const label = row.querySelector('.cf-label')?.value.trim() || '';
            const value = row.querySelector('.cf-value')?.value.trim() || '';
            if (label)
                custom_fields.push({ label, value });
        });
        const payload = {
            first_name: document.getElementById('prof-first-name').value.trim(),
            last_name: document.getElementById('prof-last-name').value.trim(),
            email: document.getElementById('prof-email').value.trim(),
            phone: document.getElementById('prof-phone').value.trim(),
            address: document.getElementById('prof-address').value.trim(),
            custom_fields
        };
        try {
            const res = await fetchApi('/api/v1/users/me/profile', {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Profil mis à jour avec succès !', 'success');
            }
        }
        finally {
            setButtonLoading(saveBtn, false, 'Enregistrer les informations de mon profil');
        }
    });
}
function update2faUiState(enabled) {
    const badge = document.getElementById('badge-2fa-status');
    const btnEnable = document.getElementById('btn-enable-2fa');
    const btnDisable = document.getElementById('btn-disable-2fa');
    if (badge) {
        badge.className = enabled
            ? 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800'
            : 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600';
        badge.textContent = enabled ? '✓ 2FA Active' : 'Désactivée';
    }
    btnEnable?.classList.toggle('hidden', enabled);
    btnDisable?.classList.toggle('hidden', !enabled);
}
function initTwoFactorControls() {
    const btnEnable = document.getElementById('btn-enable-2fa');
    const btnDisable = document.getElementById('btn-disable-2fa');
    const codeInput = document.getElementById('totp-code-input');
    btnEnable?.addEventListener('click', async () => {
        const code = codeInput?.value.trim() || '';
        const res = await fetchApi('/api/v1/users/me/2fa', {
            method: 'POST',
            body: JSON.stringify({ enabled: true, totp_code: code })
        });
        if (res.status === 'success') {
            showToast('Double authentification (2FA) activée !', 'success');
            update2faUiState(true);
            if (codeInput)
                codeInput.value = '';
        }
    });
    btnDisable?.addEventListener('click', async () => {
        const res = await fetchApi('/api/v1/users/me/2fa', {
            method: 'POST',
            body: JSON.stringify({ enabled: false })
        });
        if (res.status === 'success') {
            showToast('Double authentification (2FA) désactivée.', 'info');
            update2faUiState(false);
        }
    });
}
function initPasswordForm() {
    const form = document.getElementById('form-change-password');
    const btn = document.getElementById('btn-submit-password');
    if (!form)
        return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Mettre à jour mon mot de passe');
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            const res = await fetchApi('/api/v1/users/me/password', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Mot de passe modifié avec succès !', 'success');
                form.reset();
            }
        }
        finally {
            setButtonLoading(btn, false, 'Mettre à jour mon mot de passe');
        }
    });
}
function initClubBusinessSettingsForm() {
    const form = document.getElementById('form-club-business-settings');
    const btn = document.getElementById('btn-save-club-settings');
    if (!form)
        return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Enregistrer les paramètres du club');
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            const res = await fetchApi('/api/v1/settings', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Paramètres métier du club enregistrés !', 'success');
            }
        }
        finally {
            setButtonLoading(btn, false, 'Enregistrer les paramètres du club');
        }
    });
}
async function loadAuditLogs() {
    const tbody = document.getElementById('audit-logs-tbody');
    if (!tbody)
        return;
    const res = await fetchApi('/api/v1/app-logs');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    tbody.innerHTML = '';
    res.data.slice(0, 15).forEach((log) => {
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td class="p-3 font-mono">#${log.id}</td>
            <td class="p-3 font-bold text-slate-900">${log.action}</td>
            <td class="p-3 text-slate-600">${log.target_type ? `${log.target_type} #${log.target_id || ''}` : '-'}</td>
            <td class="p-3 text-slate-400">${log.created_at || ''}</td>
        `;
        tbody.appendChild(tr);
    });
}
