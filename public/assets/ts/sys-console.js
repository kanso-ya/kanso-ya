/**
 * @file sys-console.ts
 * @description Pilotage complet de la Console Superadmin (Point 1) :
 * - Sauvegarde du Nom du site, Logo, Filigrane et Palette de couleurs multi-zones
 * - Configuration SMTP, envoi d'email de test et affichage de la traçabilité (email_logs)
 * - Rafraîchissement en direct des vrais logs système (sys_logs).
 */
import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';
let allSysLogs = [];
let allAppLogs = [];
let allModules = [];
let allSuperadmins = [];
let allEmailTemplates = [];
document.addEventListener('DOMContentLoaded', () => {
    loadSystemSettings();
    loadSystemMetrics();
    loadEmailLogs();
    loadSystemLogs();
    loadAppLogs();
    loadModules();
    loadSuperadmins();
    initBackupButton();
    initSuperadminForm();
    initSuperadminPasswordForm();
    initBrandingForm();
    initSmtpForm();
    initEmailTemplatesEditor();
    initSysLogsFilter();
    initSysRolloverControls();
    initModuleControls();
});
async function loadSystemSettings() {
    const res = await fetchApi('/api/v1/settings');
    if (res.status !== 'success' || !res.data)
        return;
    const d = res.data;
    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el && val !== undefined)
            el.value = val;
    };
    setVal('sys-site-name', d.site_name);
    setVal('sys-logo-url', d.site_logo_url);
    setVal('sys-watermark-url', d.site_watermark_url);
    setVal('sys-watermark-opacity', d.site_watermark_opacity);
    setVal('sys-color-primary', d.theme_primary_color);
    setVal('sys-color-accent', d.theme_accent_color || d.theme_primary_color);
    setVal('sys-color-sidebar', d.theme_sidebar_color);
    setVal('sys-color-header', d.theme_header_color);
    setVal('smtp-host', d.smtp_host);
    setVal('smtp-port', d.smtp_port);
    setVal('smtp-encryption', d.smtp_encryption);
    setVal('smtp-username', d.smtp_username);
    setVal('smtp-from-email', d.smtp_from_email);
    setVal('smtp-from-name', d.smtp_from_name);
}
function initBrandingForm() {
    const form = document.getElementById('branding-form');
    const btn = document.getElementById('btn-save-branding');
    const logoInput = document.getElementById('upload-logo-input');
    const watermarkInput = document.getElementById('upload-watermark-input');
    // Préréglages de palettes en 1 clic
    document.querySelectorAll('.preset-palette-btn').forEach((presetBtn) => {
        presetBtn.addEventListener('click', () => {
            const raw = presetBtn.getAttribute('data-preset');
            if (!raw)
                return;
            const palette = JSON.parse(raw);
            document.getElementById('sys-color-primary').value = palette.primary;
            const accentEl = document.getElementById('sys-color-accent');
            if (accentEl)
                accentEl.value = palette.accent || palette.primary;
            document.getElementById('sys-color-sidebar').value = palette.sidebar;
            document.getElementById('sys-color-header').value = palette.header;
            showToast('Palette appliquée aux sélecteurs. Cliquez sur Enregistrer pour la publier.', 'info');
        });
    });
    // Upload Logo
    logoInput?.addEventListener('change', async () => {
        const file = logoInput.files?.[0];
        if (!file)
            return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'logo');
        const res = await fetchApi('/api/v1/sys/branding-upload', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            document.getElementById('sys-logo-url').value = res.data.url;
            showToast('Logo uploadé ! Rafraîchissement...', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    });
    // Upload Filigrane
    watermarkInput?.addEventListener('change', async () => {
        const file = watermarkInput.files?.[0];
        if (!file)
            return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'watermark');
        const res = await fetchApi('/api/v1/sys/branding-upload', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            document.getElementById('sys-watermark-url').value = res.data.url;
            showToast('Filigrane uploadé ! Rafraîchissement...', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    });
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Enregistrer l\'identité visuelle & les couleurs');
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            const res = await fetchApi('/api/v1/settings', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Identité visuelle et couleurs mises à jour !', 'success');
                await loadSystemLogs();
                setTimeout(() => window.location.reload(), 500);
            }
        }
        finally {
            setButtonLoading(btn, false, 'Enregistrer l\'identité visuelle & les couleurs');
        }
    });
}
function initSmtpForm() {
    const form = document.getElementById('smtp-settings-form');
    const btn = document.getElementById('btn-save-smtp');
    const testBtn = document.getElementById('btn-send-test-email');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Sauvegarder la configuration SMTP');
        try {
            const payload = Object.fromEntries(new FormData(form).entries());
            const res = await fetchApi('/api/v1/settings', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Paramètres SMTP enregistrés avec succès.', 'success');
                await loadSystemLogs();
            }
        }
        finally {
            setButtonLoading(btn, false, 'Sauvegarder la configuration SMTP');
        }
    });
    testBtn?.addEventListener('click', async () => {
        const emailInput = document.getElementById('test-smtp-recipient');
        const recipient = emailInput?.value.trim() || 'test.admin@kanso-ya.fr';
        setButtonLoading(testBtn, true, '✉️ Envoyer un mail de test');
        try {
            const res = await fetchApi('/api/v1/sys/test-email', {
                method: 'POST',
                body: JSON.stringify({
                    recipient_email: recipient,
                    subject: 'Test de connexion SMTP Kanso-ya',
                    template_type: 'SMTP_DIAGNOSTIC'
                })
            });
            if (res.status === 'success') {
                showToast(`Email de test expédié et tracé vers ${recipient}`, 'success');
                await loadEmailLogs();
            }
        }
        finally {
            setButtonLoading(testBtn, false, '✉️ Envoyer un mail de test');
        }
    });
}
async function loadEmailLogs() {
    const tbody = document.getElementById('email-logs-tbody');
    if (!tbody)
        return;
    const res = await fetchApi('/api/v1/sys/email-logs');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    tbody.innerHTML = '';
    res.data.forEach((mail) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-800/50';
        tr.innerHTML = `
            <td class="p-2.5 text-emerald-400 font-semibold">${mail.recipient_email}</td>
            <td class="p-2.5 text-slate-200">${mail.subject}</td>
            <td class="p-2.5"><span class="px-2 py-0.5 rounded bg-slate-800 text-slate-300">${mail.template_type}</span></td>
            <td class="p-2.5 text-slate-400">${mail.smtp_response || '250 OK'}</td>
            <td class="p-2.5 text-slate-500">${mail.sent_at}</td>
        `;
        tbody.appendChild(tr);
    });
}
async function loadSystemLogs() {
    const res = await fetchApi('/api/v1/sys/logs');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    allSysLogs = res.data;
    renderSysLogs(allSysLogs);
}
function renderSysLogs(logs) {
    const tbody = document.getElementById('sys-logs-tbody');
    if (!tbody)
        return;
    tbody.innerHTML = '';
    logs.forEach((log) => {
        const tr = document.createElement('tr');
        const isAlert = log.action.includes('UNAUTHORIZED') || log.action.includes('FAIL');
        tr.className = 'hover:bg-slate-800/60 transition';
        tr.innerHTML = `
            <td class="p-3 text-slate-500">#${log.id}</td>
            <td class="p-3 font-bold ${isAlert ? 'text-red-400' : 'text-emerald-400'}">${escapeHtml(log.action)}</td>
            <td class="p-3 text-slate-300 font-sans text-xs">${escapeHtml(log.details || '-')}</td>
            <td class="p-3 text-slate-400">${escapeHtml(log.ip_address || '127.0.0.1')}</td>
            <td class="p-3 text-slate-500">${escapeHtml(log.created_at || '')}</td>
        `;
        tbody.appendChild(tr);
    });
}
async function loadAppLogs() {
    const tbody = document.getElementById('app-logs-tbody');
    if (!tbody)
        return;
    try {
        const res = await fetchApi('/api/v1/app-logs');
        if (res.status === 'success' && Array.isArray(res.data)) {
            allAppLogs = res.data;
            renderAppLogs(allAppLogs);
        }
        else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-slate-500">Aucun journal d'activité applicative.</td></tr>`;
        }
    }
    catch {
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-400">Erreur lors de la récupération des journaux.</td></tr>`;
    }
}
function renderAppLogs(logs) {
    const tbody = document.getElementById('app-logs-tbody');
    if (!tbody)
        return;
    if (logs.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-slate-500">Aucun journal trouvé.</td></tr>`;
        return;
    }
    tbody.innerHTML = logs.map(l => `
        <tr class="hover:bg-slate-800/60 transition">
            <td class="p-3 text-slate-500">#${l.id}</td>
            <td class="p-3 text-slate-300 font-sans">${escapeHtml(l.user_name || `Utilisateur #${l.user_id}`)}</td>
            <td class="p-3 font-bold text-emerald-400">${escapeHtml(l.action)}</td>
            <td class="p-3 text-slate-300 font-sans text-xs">${escapeHtml(l.details || '-')}</td>
            <td class="p-3 text-slate-500 font-mono text-[11px]">${escapeHtml(l.created_at || '')}</td>
        </tr>
    `).join('');
}
function initSysLogsFilter() {
    const input = document.getElementById('sys-logs-search');
    if (!input)
        return;
    input.addEventListener('input', () => {
        const q = input.value.toLowerCase().trim();
        renderSysLogs(allSysLogs.filter((l) => l.action.toLowerCase().includes(q) ||
            (l.details || '').toLowerCase().includes(q) ||
            (l.ip_address || '').toLowerCase().includes(q)));
        renderAppLogs(allAppLogs.filter((l) => l.action.toLowerCase().includes(q) ||
            (l.details || '').toLowerCase().includes(q) ||
            (l.user_name || '').toLowerCase().includes(q)));
    });
}
function initSysRolloverControls() {
    const btnOpen = document.getElementById('btn-open-sys-rollover-modal');
    const modal = document.getElementById('sys-rollover-modal');
    const btnCancel = document.getElementById('btn-cancel-sys-rollover');
    const btnConfirm = document.getElementById('btn-confirm-sys-rollover');
    const targetSeason = document.getElementById('sys-target-season');
    const refDate = document.getElementById('sys-ref-date');
    if (!btnOpen || !modal)
        return;
    btnOpen.addEventListener('click', () => modal.classList.remove('hidden'));
    btnCancel?.addEventListener('click', () => modal.classList.add('hidden'));
    btnConfirm?.addEventListener('click', async () => {
        setButtonLoading(btnConfirm, true, 'Exécuter le Rollover');
        try {
            const res = await fetchApi('/api/v1/tasks/rollover-season', {
                method: 'POST',
                body: JSON.stringify({
                    target_season: targetSeason?.value.trim() || '2027-2028',
                    reference_date: refDate?.value.trim() || '2027-09-01'
                })
            });
            if (res.status === 'success') {
                showToast(`Rollover vers ${res.data?.target_season} terminé avec succès !`, 'success');
                modal.classList.add('hidden');
                await loadSystemLogs();
            }
        }
        finally {
            setButtonLoading(btnConfirm, false, 'Exécuter le Rollover');
        }
    });
}
async function loadModules() {
    const tbody = document.getElementById('modules-tbody');
    if (!tbody)
        return;
    try {
        const res = await fetchApi('/api/v1/sys/modules');
        if (res.status === 'success' && Array.isArray(res.data)) {
            allModules = res.data;
            renderModules(allModules);
        }
        else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-400">Erreur lors de la récupération des modules.</td></tr>`;
        }
    }
    catch (e) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-400">Échec de communication réseau.</td></tr>`;
    }
}
function renderModules(modules) {
    const tbody = document.getElementById('modules-tbody');
    if (!tbody)
        return;
    if (modules.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-6 text-center text-slate-500">Aucun module détecté dans <code>/modules/</code>.</td></tr>`;
        return;
    }
    tbody.innerHTML = '';
    modules.forEach((mod) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-800/60 transition';
        tr.id = `module-row-${mod.folder_name}`;
        const isActive = Boolean(mod.is_active) || mod.status === 'active';
        const isCrashed = mod.status === 'crashed';
        const isIncompatible = mod.status === 'incompatible' || mod.is_compatible === false;
        let statusBadge = '';
        if (isCrashed) {
            statusBadge = `<span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-red-950 text-red-400 border border-red-800 flex items-center gap-1 w-max">
                <span class="w-1.5 h-1.5 rounded-full bg-red-400 animate-pulse"></span>
                <span>Crashé (Safe Mode)</span>
            </span>`;
        }
        else if (isIncompatible) {
            statusBadge = `<span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-amber-950 text-amber-400 border border-amber-800 w-max block">
                Incompatible
            </span>`;
        }
        else if (isActive) {
            statusBadge = `<span class="px-2.5 py-1 rounded-full text-[11px] font-bold bg-emerald-950 text-emerald-400 border border-emerald-800 flex items-center gap-1 w-max">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-400"></span>
                <span>Actif</span>
            </span>`;
        }
        else {
            statusBadge = `<span class="px-2.5 py-1 rounded-full text-[11px] font-medium bg-slate-800 text-slate-400 w-max block">
                Inactif
            </span>`;
        }
        const compatInfo = isIncompatible
            ? `<span class="text-red-400 font-mono text-[11px]" title="${mod.compatibility_error || ''}">⚠️ ${mod.compatibility_error || 'Version non supportée'}</span>`
            : `<span class="text-emerald-400 font-mono text-[11px]">✓ Compatible Core</span>`;
        const errorBanner = (isCrashed && mod.last_error)
            ? `<div class="mt-1 text-[11px] text-red-300 font-mono bg-red-900/40 p-1.5 rounded border border-red-800/60 max-w-md break-words">${mod.last_error}</div>`
            : '';
        tr.innerHTML = `
            <td class="p-3">
                <div class="font-bold text-white text-sm">${mod.name}</div>
                <div class="text-[11px] text-slate-400 font-mono">Dossier: ${mod.folder_name}</div>
                ${mod.description ? `<div class="text-slate-400 text-xs mt-0.5">${mod.description}</div>` : ''}
                ${errorBanner}
            </td>
            <td class="p-3 text-slate-300">
                <div class="font-mono text-xs">v${mod.version}</div>
                <div class="text-[11px] text-slate-500">${mod.author || 'Auteur non précisé'}</div>
            </td>
            <td class="p-3">${compatInfo}</td>
            <td class="p-3">${statusBadge}</td>
            <td class="p-3 text-right">
                <div class="flex items-center justify-end gap-2">
                    ${!isIncompatible ? `
                        <button type="button" data-action="toggle-module" data-folder="${mod.folder_name}" data-id="${mod.id}" data-active="${isActive ? '1' : '0'}"
                            class="px-3 py-1.5 rounded-lg text-xs font-semibold transition ${isActive ? 'bg-amber-600 hover:bg-amber-500 text-white' : 'bg-emerald-600 hover:bg-emerald-500 text-white'}">
                            ${isActive ? 'Désactiver' : 'Activer'}
                        </button>
                    ` : `
                        <button type="button" disabled class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 text-slate-600 cursor-not-allowed">
                            Incompatible
                        </button>
                    `}
                    <button type="button" data-action="uninstall-module" data-folder="${mod.folder_name}" data-id="${mod.id}" data-name="${mod.name}"
                        class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-red-950 hover:bg-red-900 text-red-300 border border-red-800 transition">
                        Désinstaller
                    </button>
                </div>
            </td>
        `;
        tbody.appendChild(tr);
    });
    const safeBanner = document.getElementById('safe-mode-banner');
    const safeDetails = document.getElementById('safe-mode-details');
    const crashedMod = modules.find(m => m.status === 'crashed');
    if (safeBanner) {
        if (crashedMod) {
            safeBanner.classList.remove('hidden');
            if (safeDetails) {
                safeDetails.textContent = crashedMod.last_error || `Le module ${crashedMod.name} a été isolé automatiquement sans impacter le CMS.`;
            }
        }
        else {
            safeBanner.classList.add('hidden');
        }
    }
}
function initModuleControls() {
    const btnScan = document.getElementById('btn-scan-modules');
    btnScan?.addEventListener('click', async () => {
        setButtonLoading(btnScan, true, '🔄 Scanner les modules');
        try {
            const res = await fetchApi('/api/v1/sys/modules/scan', { method: 'POST' });
            if (res.status === 'success' && Array.isArray(res.data)) {
                allModules = res.data;
                renderModules(allModules);
                showToast(`Scan terminé : ${allModules.length} module(s) détecté(s)`, 'success');
                await loadSystemLogs();
            }
            else {
                showToast(res.message || 'Échec du scan des modules', 'error');
            }
        }
        finally {
            setButtonLoading(btnScan, false, '🔄 Scanner les modules');
        }
    });
    const tbody = document.getElementById('modules-tbody');
    tbody?.addEventListener('click', async (e) => {
        const target = e.target.closest('button');
        if (!target)
            return;
        const action = target.getAttribute('data-action');
        const folder = target.getAttribute('data-folder');
        const modId = target.getAttribute('data-id');
        const modName = target.getAttribute('data-name') || folder;
        if (action === 'toggle-module' && folder) {
            const currentActive = target.getAttribute('data-active') === '1';
            const willEnable = !currentActive;
            setButtonLoading(target, true, willEnable ? 'Activer' : 'Désactiver');
            try {
                const res = await fetchApi(`/api/v1/sys/modules/${folder}/toggle`, {
                    method: 'PATCH',
                    body: JSON.stringify({ enabled: willEnable })
                });
                if (res.status === 'success') {
                    showToast(res.message || (willEnable ? 'Module activé !' : 'Module désactivé.'), 'success');
                }
                else {
                    showToast(res.message || 'Erreur lors de l\'activation/désactivation', 'error');
                }
                await loadModules();
                await loadSystemLogs();
            }
            finally {
                setButtonLoading(target, false, willEnable ? 'Activer' : 'Désactiver');
            }
        }
        if (action === 'uninstall-module' && folder) {
            if (!confirm(`Confirmez-vous la désinstallation complète du module « ${modName} » ? Ses tables de données seront supprimées.`)) {
                return;
            }
            setButtonLoading(target, true, 'Désinstaller');
            try {
                const res = await fetchApi(`/api/v1/sys/modules/${folder}/uninstall`, {
                    method: 'POST'
                });
                if (res.status === 'success') {
                    showToast(res.message || 'Module désinstallé avec succès.', 'success');
                }
                else {
                    showToast(res.message || 'Échec de la désinstallation', 'error');
                }
                await loadModules();
                await loadSystemLogs();
            }
            finally {
                setButtonLoading(target, false, 'Désinstaller');
            }
        }
    });
}
function escapeHtml(str) {
    return str.replace(/[&<>"']/g, (m) => {
        const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
        return map[m] || m;
    });
}
async function loadSuperadmins() {
    const tbody = document.getElementById('superadmins-tbody');
    if (!tbody)
        return;
    try {
        const res = await fetchApi('/api/v1/sys/superadmins');
        if (res.status === 'success' && Array.isArray(res.data)) {
            allSuperadmins = res.data;
            renderSuperadminsTable(allSuperadmins);
        }
        else {
            tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-slate-500">Aucun compte Superadmin technique trouvé.</td></tr>`;
        }
    }
    catch {
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-red-400">Erreur lors de la récupération des Superadmins.</td></tr>`;
    }
}
function renderSuperadminsTable(admins) {
    const tbody = document.getElementById('superadmins-tbody');
    if (!tbody)
        return;
    if (admins.length === 0) {
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-slate-500">Aucun Superadmin enregistré.</td></tr>`;
        return;
    }
    tbody.innerHTML = admins.map(a => {
        const isLocked = a.locked_until && new Date(a.locked_until).getTime() > Date.now();
        const statusBadge = isLocked
            ? `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-red-950 text-red-400 border border-red-800">🔒 Verrouillé jusqu'à ${a.locked_until}</span>`
            : `<span class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-emerald-950 text-emerald-400 border border-emerald-800">✓ Actif (${a.failed_login_attempts} échec(s))</span>`;
        return `
            <tr class="hover:bg-slate-800/40 transition">
                <td class="p-3 font-mono text-slate-400">#${a.id}</td>
                <td class="p-3 font-bold text-white">${escapeHtml(a.username)}</td>
                <td class="p-3 text-slate-300">${escapeHtml(a.email)}</td>
                <td class="p-3">${statusBadge}</td>
                <td class="p-3 text-slate-400 text-[11px]">${escapeHtml(a.created_at)}</td>
            </tr>
        `;
    }).join('');
}
function initSuperadminForm() {
    const form = document.getElementById('form-create-superadmin');
    const btn = (document.getElementById('btn-submit-add-admin') || document.getElementById('btn-submit-superadmin'));
    const btnToggle = document.getElementById('btn-toggle-add-admin');
    const btnCancel = document.getElementById('btn-cancel-add-admin');
    if (!form)
        return;
    btnToggle?.addEventListener('click', () => {
        form.classList.toggle('hidden');
    });
    btnCancel?.addEventListener('click', () => {
        form.classList.add('hidden');
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Création...');
        try {
            const formData = new FormData(form);
            const payload = {
                username: (formData.get('username') || '').trim(),
                email: (formData.get('email') || '').trim(),
                password: (formData.get('password') || '')
            };
            const res = await fetchApi('/api/v1/sys/superadmins', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast(res.message || 'Superadmin créé avec succès !', 'success');
                form.reset();
                form.classList.add('hidden');
                await loadSuperadmins();
                await loadSystemLogs();
            }
            else {
                showToast(res.message || 'Erreur lors de la création du compte', 'error');
            }
        }
        finally {
            setButtonLoading(btn, false, 'Créer le compte');
        }
    });
}
function initSuperadminPasswordForm() {
    const form = document.getElementById('form-update-sys-password');
    const btn = document.getElementById('btn-submit-sys-password');
    if (!form)
        return;
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(btn, true, 'Mise à jour...');
        try {
            const formData = new FormData(form);
            const payload = {
                current_password: (formData.get('current_password') || ''),
                new_password: (formData.get('new_password') || '')
            };
            const res = await fetchApi('/api/v1/sys/password', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast(res.message || 'Mot de passe Superadmin mis à jour avec succès !', 'success');
                form.reset();
                await loadSystemLogs();
            }
            else {
                showToast(res.message || 'Erreur lors de la modification du mot de passe', 'error');
            }
        }
        finally {
            setButtonLoading(btn, false, 'Mettre à jour mon mot de passe');
        }
    });
}
async function loadSystemMetrics() {
    const elUsers = document.getElementById('metric-users-count');
    const elMembers = document.getElementById('metric-members-count');
    const elDbSize = document.getElementById('metric-db-size');
    const elArticles = document.getElementById('metric-articles-count');
    if (!elUsers && !elMembers && !elDbSize && !elArticles)
        return;
    try {
        const res = await fetchApi('/api/v1/sys/metrics');
        if (res.status === 'success' && res.data) {
            if (elUsers)
                elUsers.textContent = String(res.data.users_count);
            if (elMembers)
                elMembers.textContent = String(res.data.members_count);
            if (elArticles)
                elArticles.textContent = String(res.data.articles_count);
            if (elDbSize)
                elDbSize.textContent = res.data.db_size;
        }
    }
    catch {
        // Silencieux si l'appel échoue sur une page où ce n'est pas critique
    }
}
function initBackupButton() {
    const btn = document.getElementById('btn-backup-db');
    if (!btn)
        return;
    btn.addEventListener('click', async () => {
        setButtonLoading(btn, true, '💾 Sauvegarder la base de données');
        try {
            const res = await fetchApi('/api/v1/sys/backup', {
                method: 'POST'
            });
            if (res.status === 'success' && res.data) {
                showToast(`Sauvegarde SQLite réussie : ${res.data.filename} (${res.data.size})`, 'success');
                await loadSystemLogs();
            }
            else {
                showToast(res.message || 'Erreur lors de la sauvegarde de la base de données', 'error');
            }
        }
        finally {
            setButtonLoading(btn, false, '💾 Sauvegarder la base de données');
        }
    });
}
async function initEmailTemplatesEditor() {
    const select = document.getElementById('select-email-template');
    const form = document.getElementById('form-edit-email-template');
    const btn = document.getElementById('btn-save-email-template');
    const inputSubject = document.getElementById('tpl-subject');
    const textareaBody = document.getElementById('tpl-body-html');
    const hintVariables = document.getElementById('tpl-variables-hint');
    if (!select || !form)
        return;
    try {
        const res = await fetchApi('/api/v1/sys/email-templates');
        if (res.status === 'success' && Array.isArray(res.data) && res.data.length > 0) {
            allEmailTemplates = res.data;
            select.innerHTML = allEmailTemplates.map(t => `<option value="${escapeHtml(t.template_key)}">${escapeHtml(t.title)} (${escapeHtml(t.template_key)})</option>`).join('');
            const displayTemplate = (key) => {
                const tpl = allEmailTemplates.find(t => t.template_key === key);
                if (!tpl)
                    return;
                if (inputSubject)
                    inputSubject.value = tpl.subject;
                if (textareaBody)
                    textareaBody.value = tpl.body_html;
                if (hintVariables)
                    hintVariables.textContent = tpl.variables_available;
            };
            select.addEventListener('change', () => {
                displayTemplate(select.value);
            });
            // Affichage du premier template par défaut
            displayTemplate(select.value);
        }
    }
    catch {
        showToast('Impossible de charger les modèles d\'emails', 'error');
    }
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const selectedKey = select.value;
        if (!selectedKey)
            return;
        setButtonLoading(btn, true, '💾 Enregistrer le modèle d\'email');
        try {
            const subject = inputSubject?.value.trim() || '';
            const bodyHtml = textareaBody?.value || '';
            const res = await fetchApi(`/api/v1/sys/email-templates/${selectedKey}`, {
                method: 'PUT',
                body: JSON.stringify({
                    subject,
                    body_html: bodyHtml
                })
            });
            if (res.status === 'success') {
                showToast(`Modèle d'email « ${selectedKey} » mis à jour avec succès !`, 'success');
                // Mettre à jour dans allEmailTemplates
                const item = allEmailTemplates.find(t => t.template_key === selectedKey);
                if (item) {
                    item.subject = subject;
                    item.body_html = bodyHtml;
                }
                await loadSystemLogs();
            }
            else {
                showToast(res.message || 'Erreur lors de la sauvegarde du modèle', 'error');
            }
        }
        finally {
            setButtonLoading(btn, false, '💾 Enregistrer le modèle d\'email');
        }
    });
}
