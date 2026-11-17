/**
 * @file settings.ts
 * @description Administration du club pour le Bureau & Président :
 * - Paramétrage des cotisations, remises fratrie, et exigences médicales
 * - Gestion des modules et extensions SDK (scan, activation/désactivation)
 * - Basculement annuel de saison (Moulinette Rollover)
 * - Journal d'audit applicatif
 */
import { fetchApi } from './api.js';
import { setButtonLoading, showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    initSettingsTabs();
    initClubSettingsForm();
    initModulesManagement();
    initRolloverControls();
    initInstitutionalPagesEditor();
    window.addEventListener('kanso:auth-ready', async (e) => {
        const ctx = e.detail;
        if (ctx.role === 'Président') {
            await loadModulesList();
            await loadAuditLogs();
        }
    });
});
function initSettingsTabs() {
    const tabs = [
        { btn: document.getElementById('tab-btn-club-general'), panel: document.getElementById('tab-panel-club-general') },
        { btn: document.getElementById('tab-btn-club-modules'), panel: document.getElementById('tab-panel-club-modules') },
        { btn: document.getElementById('tab-btn-club-rollover'), panel: document.getElementById('tab-panel-club-rollover') },
        { btn: document.getElementById('tab-btn-club-logs'), panel: document.getElementById('tab-panel-club-logs') },
        { btn: document.getElementById('tab-btn-club-pages'), panel: document.getElementById('tab-panel-club-pages') }
    ];
    tabs.forEach(({ btn, panel }) => {
        if (!btn || !panel)
            return;
        btn.addEventListener('click', () => {
            tabs.forEach((t) => {
                if (t.btn && t.panel) {
                    t.btn.className = 'px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition';
                    t.panel.classList.add('hidden');
                }
            });
            btn.className = 'px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition';
            panel.classList.remove('hidden');
            if (panel.id === 'tab-panel-club-modules') {
                loadModulesList();
            }
            else if (panel.id === 'tab-panel-club-logs') {
                loadAuditLogs();
            }
            else if (panel.id === 'tab-panel-club-pages') {
                loadInstitutionalPage();
            }
        });
    });
}
function initClubSettingsForm() {
    const form = document.getElementById('form-club-settings');
    const btn = document.getElementById('btn-save-settings');
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
                showToast('Paramètres du club enregistrés avec succès !', 'success');
            }
        }
        finally {
            setButtonLoading(btn, false, 'Enregistrer les paramètres du club');
        }
    });
}
async function loadModulesList() {
    const container = document.getElementById('modules-list-container');
    if (!container)
        return;
    const res = await fetchApi('/api/v1/sys/modules');
    if (res.status !== 'success' || !Array.isArray(res.data)) {
        container.innerHTML = `<div class="p-4 text-center text-red-500 text-xs">Impossible de charger les modules.</div>`;
        return;
    }
    if (res.data.length === 0) {
        container.innerHTML = `<div class="p-6 text-center text-slate-400 text-xs italic col-span-2">Aucun module trouvé dans /modules/.</div>`;
        return;
    }
    container.innerHTML = '';
    res.data.forEach((mod) => {
        const card = document.createElement('div');
        const isActive = mod.status === 'active';
        const isCrashed = mod.status === 'crashed';
        let badgeClass = 'bg-slate-100 text-slate-600';
        let badgeText = 'Inactif';
        if (isActive) {
            badgeClass = 'bg-emerald-100 text-emerald-800 border border-emerald-200';
            badgeText = '✓ Actif';
        }
        else if (isCrashed) {
            badgeClass = 'bg-red-100 text-red-800 border border-red-200';
            badgeText = '⚠️ Crashed (Safe Mode)';
        }
        card.className = `p-5 rounded-2xl border ${isActive ? 'border-emerald-300 bg-white shadow-xs' : 'border-slate-200 bg-slate-50/50'} space-y-3`;
        card.innerHTML = `
            <div class="flex items-start justify-between gap-3">
                <div>
                    <span class="text-[11px] font-bold px-2 py-0.5 rounded-full ${badgeClass}">${badgeText}</span>
                    <h3 class="text-base font-extrabold text-slate-900 mt-1">${mod.name}</h3>
                    <p class="text-xs text-slate-500 font-mono">v${mod.version} • par ${mod.author || 'Inconnu'}</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn-toggle-module px-3 py-1.5 rounded-xl text-xs font-bold transition ${isActive ? 'bg-red-50 hover:bg-red-100 text-red-700 border border-red-200' : 'bg-emerald-600 hover:bg-emerald-700 text-white shadow-xs'}" data-slug="${mod.folder_name || mod.slug}" data-action="${isActive ? 'disable' : 'enable'}">
                        ${isActive ? 'Désactiver' : 'Activer'}
                    </button>
                </div>
            </div>
            <p class="text-xs text-slate-600 leading-relaxed">${mod.description || 'Module sans description.'}</p>
            ${isCrashed && mod.error_message ? `<div class="p-2.5 rounded-lg bg-red-50 border border-red-200 text-[11px] text-red-800 font-mono break-all">${mod.error_message}</div>` : ''}
        `;
        card.querySelector('.btn-toggle-module')?.addEventListener('click', async (e) => {
            const btnToggle = e.currentTarget;
            const slug = btnToggle.getAttribute('data-slug') || '';
            const action = btnToggle.getAttribute('data-action');
            const enable = action === 'enable';
            btnToggle.disabled = true;
            try {
                const toggleRes = await fetchApi(`/api/v1/sys/modules/${encodeURIComponent(slug)}/toggle`, {
                    method: 'PATCH',
                    body: JSON.stringify({ enabled: enable })
                });
                if (toggleRes.status === 'success') {
                    showToast(enable ? `Module ${slug} activé !` : `Module ${slug} désactivé.`, 'success');
                    await loadModulesList();
                    // Recharger la page après un court instant pour actualiser le menu sidebar
                    setTimeout(() => window.location.reload(), 400);
                }
            }
            finally {
                btnToggle.disabled = false;
            }
        });
        container.appendChild(card);
    });
}
function initModulesManagement() {
    document.getElementById('btn-scan-modules')?.addEventListener('click', async () => {
        const res = await fetchApi('/api/v1/sys/modules/scan', { method: 'POST' });
        if (res.status === 'success') {
            showToast('Scan des modules actualisé !', 'success');
            await loadModulesList();
        }
    });
}
function initRolloverControls() {
    const modal = document.getElementById('rollover-confirm-modal');
    const openBtn = document.getElementById('btn-open-rollover-modal');
    const cancelBtn = document.getElementById('btn-cancel-rollover');
    const confirmBtn = document.getElementById('btn-confirm-rollover');
    if (!modal || !openBtn || !confirmBtn)
        return;
    openBtn.addEventListener('click', () => modal.classList.remove('hidden'));
    cancelBtn?.addEventListener('click', () => modal.classList.add('hidden'));
    confirmBtn.addEventListener('click', async () => {
        const targetSeason = document.getElementById('rollover-target-season')?.value.trim() || '2027-2028';
        const refDate = document.getElementById('rollover-reference-date')?.value.trim() || '2027-09-01';
        setButtonLoading(confirmBtn, true, 'Basculement en cours...');
        try {
            const res = await fetchApi('/api/v1/rollover', {
                method: 'POST',
                body: JSON.stringify({
                    target_season: targetSeason,
                    reference_date: refDate
                })
            });
            if (res.status === 'success' && res.data) {
                modal.classList.add('hidden');
                showToast(`Basculement réussi ! ${res.data.active_members_processed} adhérents traités (${res.data.forced_categories_preserved} surclassements préservés).`, 'success');
                await loadAuditLogs();
            }
        }
        finally {
            setButtonLoading(confirmBtn, false, 'Confirmer le basculement');
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
    res.data.slice(0, 20).forEach((log) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50 transition';
        tr.innerHTML = `
            <td class="p-3 font-mono text-slate-500">#${log.id}</td>
            <td class="p-3 font-bold text-slate-900">${log.action}</td>
            <td class="p-3 text-slate-600">${log.target_type ? `${log.target_type} #${log.target_id || ''}` : '-'}</td>
            <td class="p-3 text-slate-400 font-mono">${log.created_at || ''}</td>
        `;
        tbody.appendChild(tr);
    });
}
async function loadInstitutionalPage() {
    const select = document.getElementById('select-institutional-page');
    const titleInput = document.getElementById('page-title-input');
    const statusSelect = document.getElementById('page-status-select');
    const contentInput = document.getElementById('page-content-input');
    if (!select || !titleInput || !statusSelect || !contentInput)
        return;
    const slug = select.value;
    try {
        const res = await fetchApi(`/api/v1/pages/${slug}`);
        if (res.status === 'success' && res.data) {
            titleInput.value = res.data.title || '';
            statusSelect.value = res.data.status || 'published';
            contentInput.value = res.data.content || '';
        }
        else {
            titleInput.value = slug;
            statusSelect.value = 'published';
            contentInput.value = '';
        }
    }
    catch {
        showToast('Erreur lors du chargement de la page', 'error');
    }
}
function initInstitutionalPagesEditor() {
    const select = document.getElementById('select-institutional-page');
    const form = document.getElementById('form-institutional-page');
    const btn = document.getElementById('btn-save-institutional-page');
    select?.addEventListener('change', () => {
        loadInstitutionalPage();
    });
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!select)
            return;
        const slug = select.value;
        const titleInput = document.getElementById('page-title-input');
        const statusSelect = document.getElementById('page-status-select');
        const contentInput = document.getElementById('page-content-input');
        if (!titleInput || !statusSelect || !contentInput)
            return;
        setButtonLoading(btn, true, 'Enregistrement...');
        try {
            const payload = {
                title: titleInput.value.trim(),
                status: statusSelect.value,
                content: contentInput.value
            };
            const res = await fetchApi(`/api/v1/pages/${slug}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast(`Page « ${payload.title} » enregistrée avec succès !`, 'success');
            }
            else {
                showToast(res.message || 'Erreur lors de l\'enregistrement', 'error');
            }
        }
        finally {
            setButtonLoading(btn, false, 'Enregistrer la page institutionnelle');
        }
    });
}
