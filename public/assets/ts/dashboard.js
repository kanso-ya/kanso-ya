/**
 * @file dashboard.ts
 * @description Logique de la page "Mon profil" à 2 onglets (Point 4)
 * et des paramètres métier du Président / Trésorier / Secrétaire (Points 1 & 3).
 */
import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';
import { generateQrCodeSvg } from './qrcode.js';
let activeTotpSecret = '';
let activeUserEmail = '';
let active2faMode = 'qr';
let totpIntervalId = null;
document.addEventListener('DOMContentLoaded', () => {
    initProfileTabs();
    loadMyProfile();
    initProfileForm();
    initTwoFactorControls();
    initPasswordForm();
    initClubBusinessSettingsForm();
    initRolloverControls();
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
    activeUserEmail = p.email || 'user@kanso-ya.local';
    activeTotpSecret = p.two_factor_secret || 'KANSO2FA79100';
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
        secretEl.textContent = activeTotpSecret;
    const manualSecretEl = document.getElementById('totp-secret-manual-display');
    if (manualSecretEl)
        manualSecretEl.textContent = activeTotpSecret;
    const emailLabel = document.getElementById('twofa-user-email-label');
    if (emailLabel)
        emailLabel.textContent = activeUserEmail;
    refreshTotpQrCode();
    startTotpTimer();
    update2faUiState(p.two_factor_enabled, p.two_factor_method || 'totp');
}
function refreshTotpQrCode() {
    const qrContainer = document.getElementById('totp-qrcode-container');
    if (!qrContainer || !activeTotpSecret)
        return;
    // Format URI OTP standard
    const epochStep = Math.floor(Date.now() / 30000);
    const otpUri = `otpauth://totp/Kanso-ya:${encodeURIComponent(activeUserEmail)}?secret=${encodeURIComponent(activeTotpSecret)}&issuer=Kanso-ya&period=30&epoch=${epochStep}`;
    qrContainer.innerHTML = generateQrCodeSvg(otpUri, 176);
}
function startTotpTimer() {
    if (totpIntervalId !== null) {
        window.clearInterval(totpIntervalId);
    }
    const timerEl = document.getElementById('totp-timer');
    totpIntervalId = window.setInterval(() => {
        const remaining = 30 - (Math.floor(Date.now() / 1000) % 30);
        if (timerEl) {
            timerEl.textContent = `${remaining}s`;
        }
        if (remaining === 30) {
            refreshTotpQrCode();
        }
    }, 1000);
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
        const firstNameInput = document.getElementById('prof-first-name');
        const lastNameInput = document.getElementById('prof-last-name');
        const emailInput = document.getElementById('prof-email');
        const firstName = firstNameInput.value.trim();
        const lastName = lastNameInput.value.trim();
        const email = emailInput.value.trim();
        if (!firstName || !lastName) {
            showToast('Veuillez renseigner votre prénom et votre nom.', 'error');
            return;
        }
        if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            showToast('Veuillez saisir une adresse e-mail de connexion valide.', 'error');
            return;
        }
        setButtonLoading(saveBtn, true, 'Enregistrer les informations de mon profil');
        const custom_fields = [];
        document.querySelectorAll('.custom-field-row').forEach((row) => {
            const label = row.querySelector('.cf-label')?.value.trim() || '';
            const value = row.querySelector('.cf-value')?.value.trim() || '';
            if (label)
                custom_fields.push({ label, value });
        });
        const payload = {
            first_name: firstName,
            last_name: lastName,
            email,
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
function update2faUiState(enabled, method = 'totp') {
    const badge = document.getElementById('badge-2fa-status');
    const btnEnable = document.getElementById('btn-enable-2fa');
    const btnDisable = document.getElementById('btn-disable-2fa');
    if (badge) {
        badge.className = enabled
            ? 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-emerald-100 text-emerald-800'
            : 'px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600';
        const suffix = method === 'email' ? ' (E-mail)' : '';
        badge.textContent = enabled ? `✓ 2FA Active${suffix}` : 'Désactivée';
    }
    btnEnable?.classList.toggle('hidden', enabled);
    btnDisable?.classList.toggle('hidden', !enabled);
}
function initTwoFactorControls() {
    const btnEnable = document.getElementById('btn-enable-2fa');
    const btnDisable = document.getElementById('btn-disable-2fa');
    const codeInput = document.getElementById('totp-code-input');
    const codeLabel = document.getElementById('label-2fa-code-input');
    const btnToggleSecret = document.getElementById('btn-toggle-secret');
    const secretWrapper = document.getElementById('totp-secret-wrapper');
    const btnCopySecret = document.getElementById('btn-copy-totp-secret');
    const btnSendEmailCode = document.getElementById('btn-send-2fa-email-code');
    const modeButtons = [
        { mode: 'qr', btn: document.getElementById('btn-2fa-mode-qr'), panel: document.getElementById('panel-2fa-qr') },
        { mode: 'manual', btn: document.getElementById('btn-2fa-mode-manual'), panel: document.getElementById('panel-2fa-manual') },
        { mode: 'email', btn: document.getElementById('btn-2fa-mode-email'), panel: document.getElementById('panel-2fa-email') }
    ];
    modeButtons.forEach(({ mode, btn }) => {
        btn?.addEventListener('click', () => {
            active2faMode = mode;
            modeButtons.forEach((item) => {
                const isSelected = item.mode === mode;
                item.btn?.setAttribute('aria-selected', isSelected ? 'true' : 'false');
                if (item.btn) {
                    item.btn.className = isSelected
                        ? 'p-3 rounded-xl border-2 border-brand-primary bg-emerald-50 text-left transition'
                        : 'p-3 rounded-xl border border-slate-200 bg-slate-50 hover:bg-white text-left transition';
                }
                item.panel?.classList.toggle('hidden', !isSelected);
            });
            if (codeLabel) {
                codeLabel.textContent = mode === 'email'
                    ? 'Code reçu par e-mail (6 chiffres) *'
                    : 'Code de vérification à 6 chiffres *';
            }
        });
    });
    btnToggleSecret?.addEventListener('click', () => {
        if (!secretWrapper)
            return;
        const isHidden = secretWrapper.classList.contains('hidden');
        if (isHidden) {
            secretWrapper.classList.remove('hidden');
            btnToggleSecret.textContent = 'Masquer la clé secrète textuelle';
        }
        else {
            secretWrapper.classList.add('hidden');
            btnToggleSecret.textContent = 'Afficher la clé secrète textuelle';
        }
    });
    btnCopySecret?.addEventListener('click', async () => {
        if (!activeTotpSecret)
            return;
        try {
            await navigator.clipboard.writeText(activeTotpSecret);
            showToast('Clé secrète copiée dans le presse-papiers !', 'success');
        }
        catch {
            showToast(`Clé secrète : ${activeTotpSecret}`, 'info');
        }
    });
    btnSendEmailCode?.addEventListener('click', async () => {
        setButtonLoading(btnSendEmailCode, true, '✉️ Envoyer un code de vérification par e-mail');
        try {
            const res = await fetchApi('/api/v1/users/me/2fa/send-email-code', {
                method: 'POST',
                body: JSON.stringify({ purpose: 'setup' })
            });
            if (res.status === 'success') {
                showToast(res.message || 'Un code à 6 chiffres a été envoyé à votre adresse e-mail.', 'success');
            }
        }
        finally {
            setButtonLoading(btnSendEmailCode, false, '✉️ Envoyer un code de vérification par e-mail');
        }
    });
    btnEnable?.addEventListener('click', async () => {
        const code = codeInput?.value.trim() || '';
        if (!/^\d{6}$/.test(code)) {
            showToast('Veuillez saisir un code de vérification valide à 6 chiffres.', 'error');
            return;
        }
        const apiMethod = active2faMode === 'email' ? 'email' : 'totp';
        const res = await fetchApi('/api/v1/users/me/2fa', {
            method: 'POST',
            body: JSON.stringify({ enabled: true, method: apiMethod, totp_code: code, code })
        });
        if (res.status === 'success') {
            showToast(res.message || 'Double authentification activée !', 'success');
            update2faUiState(true, apiMethod);
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
            showToast('Double authentification désactivée.', 'info');
            update2faUiState(false, 'totp');
        }
    });
}
function initPasswordForm() {
    const form = document.getElementById('form-change-password');
    const modal = document.getElementById('password-confirm-modal');
    const modalForm = document.getElementById('form-modal-confirm-password');
    const modalConfirmInput = document.getElementById('modal-confirm-new-password');
    const btnCancelModal = document.getElementById('btn-cancel-password-modal');
    const btnConfirmModal = document.getElementById('btn-confirm-password-change');
    if (!form)
        return;
    let pendingCurrentPassword = '';
    let pendingNewPassword = '';
    const closePwdModal = () => {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
        if (modalConfirmInput)
            modalConfirmInput.value = '';
    };
    btnCancelModal?.addEventListener('click', closePwdModal);
    form.addEventListener('submit', (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const currentPassword = (formData.get('current_password') || '').trim();
        const newPassword = (formData.get('new_password') || '').trim();
        if (currentPassword === '') {
            showToast('Veuillez renseigner votre mot de passe actuel.', 'error');
            return;
        }
        if (newPassword.length < 8) {
            showToast('Le nouveau mot de passe doit comporter au moins 8 caractères.', 'error');
            return;
        }
        if (!/[A-Z]/.test(newPassword) || !/[a-z]/.test(newPassword) || !/[0-9]/.test(newPassword) || !/[^A-Za-z0-9]/.test(newPassword)) {
            showToast('Le nouveau mot de passe doit contenir au moins une majuscule, une minuscule, un chiffre et un caractère spécial.', 'error');
            return;
        }
        pendingCurrentPassword = currentPassword;
        pendingNewPassword = newPassword;
        if (modal && modalConfirmInput) {
            modalConfirmInput.value = '';
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            modalConfirmInput.focus();
        }
    });
    modalForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const confirmValue = modalConfirmInput?.value || '';
        if (!confirmValue) {
            showToast('Veuillez confirmer votre nouveau mot de passe.', 'error');
            return;
        }
        if (confirmValue !== pendingNewPassword) {
            showToast('Les deux mots de passe saisis ne correspondent pas.', 'error');
            return;
        }
        setButtonLoading(btnConfirmModal, true, 'Confirmer la modification');
        try {
            const res = await fetchApi('/api/v1/users/me/password', {
                method: 'PATCH',
                body: JSON.stringify({
                    current_password: pendingCurrentPassword,
                    new_password: pendingNewPassword,
                    confirm_password: confirmValue
                })
            });
            if (res.status === 'success') {
                showToast('Mot de passe modifié avec succès !', 'success');
                form.reset();
                closePwdModal();
            }
        }
        finally {
            setButtonLoading(btnConfirmModal, false, 'Confirmer la modification');
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
function initRolloverControls() {
    const btnOpen = document.getElementById('btn-open-rollover-modal');
    const modal = document.getElementById('rollover-confirm-modal');
    const btnCancel = document.getElementById('btn-cancel-rollover');
    const btnConfirm = document.getElementById('btn-confirm-rollover');
    const targetSeasonInput = document.getElementById('rollover-target-season');
    const refDateInput = document.getElementById('rollover-reference-date');
    if (!btnOpen || !modal)
        return;
    btnOpen.addEventListener('click', () => {
        modal.classList.remove('hidden');
    });
    btnCancel?.addEventListener('click', () => {
        modal.classList.add('hidden');
    });
    btnConfirm?.addEventListener('click', async () => {
        setButtonLoading(btnConfirm, true, 'Confirmer le basculement');
        try {
            const payload = {
                target_season: targetSeasonInput?.value.trim() || '2027-2028',
                reference_date: refDateInput?.value.trim() || '2027-09-01'
            };
            const res = await fetchApi('/api/v1/tasks/rollover-season', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success' && res.data) {
                showToast(`Basculement vers ${res.data.target_season} réussi ! (${res.data.promoted_count} promu(s), ${res.data.forced_ignored_count} surclassé(s) préservé(s))`, 'success');
                modal.classList.add('hidden');
                await loadAuditLogs();
            }
        }
        finally {
            setButtonLoading(btnConfirm, false, 'Confirmer le basculement');
        }
    });
}
