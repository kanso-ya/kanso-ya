/**
 * @file sys-console.ts
 * @description Pilotage complet de la Console Superadmin (Point 1) :
 * - Sauvegarde du Nom du site, Logo, Filigrane et Palette de couleurs multi-zones
 * - Configuration SMTP, envoi d'email de test et affichage de la traçabilité (email_logs)
 * - Rafraîchissement en direct des vrais logs système (sys_logs).
 */

import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';

interface SysLogItem {
    id: number;
    action: string;
    details?: string;
    ip_address: string;
    created_at: string;
}

interface EmailLogItem {
    id: number;
    recipient_email: string;
    subject: string;
    template_type: string;
    status: string;
    smtp_response: string;
    sent_at: string;
}

let allSysLogs: SysLogItem[] = [];

document.addEventListener('DOMContentLoaded', () => {
    loadSystemSettings();
    loadEmailLogs();
    loadSystemLogs();
    initBrandingForm();
    initSmtpForm();
    initSysLogsFilter();
    initSysRolloverControls();
});

async function loadSystemSettings(): Promise<void> {
    const res = await fetchApi<Record<string, string>>('/api/v1/settings');
    if (res.status !== 'success' || !res.data) return;

    const d = res.data;
    const setVal = (id: string, val?: string): void => {
        const el = document.getElementById(id) as HTMLInputElement | HTMLSelectElement | null;
        if (el && val !== undefined) el.value = val;
    };

    setVal('sys-site-name', d.site_name);
    setVal('sys-logo-url', d.site_logo_url);
    setVal('sys-watermark-url', d.site_watermark_url);
    setVal('sys-watermark-opacity', d.site_watermark_opacity);
    setVal('sys-color-primary', d.theme_primary_color);
    setVal('sys-color-sidebar', d.theme_sidebar_color);
    setVal('sys-color-header', d.theme_header_color);

    setVal('smtp-host', d.smtp_host);
    setVal('smtp-port', d.smtp_port);
    setVal('smtp-encryption', d.smtp_encryption);
    setVal('smtp-username', d.smtp_username);
    setVal('smtp-from-email', d.smtp_from_email);
    setVal('smtp-from-name', d.smtp_from_name);
}

function initBrandingForm(): void {
    const form = document.getElementById('branding-form') as HTMLFormElement | null;
    const btn = document.getElementById('btn-save-branding') as HTMLButtonElement | null;
    const logoInput = document.getElementById('upload-logo-input') as HTMLInputElement | null;
    const watermarkInput = document.getElementById('upload-watermark-input') as HTMLInputElement | null;

    // Préréglages de palettes en 1 clic
    document.querySelectorAll<HTMLButtonElement>('.preset-palette-btn').forEach((presetBtn) => {
        presetBtn.addEventListener('click', () => {
            const raw = presetBtn.getAttribute('data-preset');
            if (!raw) return;
            const palette = JSON.parse(raw);
            (document.getElementById('sys-color-primary') as HTMLInputElement).value = palette.primary;
            (document.getElementById('sys-color-sidebar') as HTMLInputElement).value = palette.sidebar;
            (document.getElementById('sys-color-header') as HTMLInputElement).value = palette.header;
            showToast('Palette appliquée aux sélecteurs. Cliquez sur Enregistrer pour la publier.', 'info');
        });
    });

    // Upload Logo
    logoInput?.addEventListener('change', async () => {
        const file = logoInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'logo');
        const res = await fetchApi<{ url: string }>('/api/v1/sys/branding-upload', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            (document.getElementById('sys-logo-url') as HTMLInputElement).value = res.data.url;
            showToast('Logo uploadé ! Rafraîchissement...', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    });

    // Upload Filigrane
    watermarkInput?.addEventListener('change', async () => {
        const file = watermarkInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        fd.append('type', 'watermark');
        const res = await fetchApi<{ url: string }>('/api/v1/sys/branding-upload', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            (document.getElementById('sys-watermark-url') as HTMLInputElement).value = res.data.url;
            showToast('Filigrane uploadé ! Rafraîchissement...', 'success');
            setTimeout(() => window.location.reload(), 500);
        }
    });

    form?.addEventListener('submit', async (e: Event) => {
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
        } finally {
            setButtonLoading(btn, false, 'Enregistrer l\'identité visuelle & les couleurs');
        }
    });
}

function initSmtpForm(): void {
    const form = document.getElementById('smtp-settings-form') as HTMLFormElement | null;
    const btn = document.getElementById('btn-save-smtp') as HTMLButtonElement | null;
    const testBtn = document.getElementById('btn-send-test-email') as HTMLButtonElement | null;

    form?.addEventListener('submit', async (e: Event) => {
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
        } finally {
            setButtonLoading(btn, false, 'Sauvegarder la configuration SMTP');
        }
    });

    testBtn?.addEventListener('click', async () => {
        const emailInput = document.getElementById('test-smtp-recipient') as HTMLInputElement | null;
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
        } finally {
            setButtonLoading(testBtn, false, '✉️ Envoyer un mail de test');
        }
    });
}

async function loadEmailLogs(): Promise<void> {
    const tbody = document.getElementById('email-logs-tbody');
    if (!tbody) return;

    const res = await fetchApi<EmailLogItem[]>('/api/v1/sys/email-logs');
    if (res.status !== 'success' || !Array.isArray(res.data)) return;

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

async function loadSystemLogs(): Promise<void> {
    const res = await fetchApi<SysLogItem[]>('/api/v1/sys/logs');
    if (res.status !== 'success' || !Array.isArray(res.data)) return;

    allSysLogs = res.data;
    renderSysLogs(allSysLogs);
}

function renderSysLogs(logs: SysLogItem[]): void {
    const tbody = document.getElementById('sys-logs-tbody');
    if (!tbody) return;

    tbody.innerHTML = '';
    logs.forEach((log) => {
        const tr = document.createElement('tr');
        const isAlert = log.action.includes('UNAUTHORIZED') || log.action.includes('FAIL');
        tr.className = 'hover:bg-slate-800/60 transition';
        tr.innerHTML = `
            <td class="p-3 text-slate-500">#${log.id}</td>
            <td class="p-3 font-bold ${isAlert ? 'text-red-400' : 'text-emerald-400'}">${log.action}</td>
            <td class="p-3 text-slate-300 font-sans text-xs">${log.details || '-'}</td>
            <td class="p-3 text-slate-400">${log.ip_address || '127.0.0.1'}</td>
            <td class="p-3 text-slate-500">${log.created_at || ''}</td>
        `;
        tbody.appendChild(tr);
    });
}

function initSysLogsFilter(): void {
    const input = document.getElementById('sys-logs-search') as HTMLInputElement | null;
    if (!input) return;

    input.addEventListener('input', () => {
        const q = input.value.toLowerCase().trim();
        renderSysLogs(
            allSysLogs.filter(
                (l) =>
                    l.action.toLowerCase().includes(q) ||
                    (l.details || '').toLowerCase().includes(q) ||
                    (l.ip_address || '').toLowerCase().includes(q)
            )
        );
    });
}

function initSysRolloverControls(): void {
    const btnOpen = document.getElementById('btn-open-sys-rollover-modal');
    const modal = document.getElementById('sys-rollover-modal');
    const btnCancel = document.getElementById('btn-cancel-sys-rollover');
    const btnConfirm = document.getElementById('btn-confirm-sys-rollover') as HTMLButtonElement | null;
    const targetSeason = document.getElementById('sys-target-season') as HTMLInputElement | null;
    const refDate = document.getElementById('sys-ref-date') as HTMLInputElement | null;

    if (!btnOpen || !modal) return;

    btnOpen.addEventListener('click', () => modal.classList.remove('hidden'));
    btnCancel?.addEventListener('click', () => modal.classList.add('hidden'));

    btnConfirm?.addEventListener('click', async () => {
        setButtonLoading(btnConfirm, true, 'Exécuter le Rollover');
        try {
            const res = await fetchApi<{
                target_season: string;
                promoted_count: number;
                forced_ignored_count: number;
            }>('/api/v1/tasks/rollover-season', {
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
        } finally {
            setButtonLoading(btnConfirm, false, 'Exécuter le Rollover');
        }
    });
}
