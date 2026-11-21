/**
 * @file settings.ts
 * @description Administration du club pour le Bureau & Président :
 * - Paramétrage des cotisations, remises fratrie, et exigences médicales
 * - Gestion des modules et extensions SDK (scan, activation/désactivation)
 * - Basculement annuel de saison (Moulinette Rollover)
 * - Journal d'audit applicatif
 */

import { fetchApi } from './api.js';
import { AuthContext, setButtonLoading, showToast } from './main.js';

interface ModuleItem {
    id: number;
    slug: string;
    folder_name: string;
    name: string;
    version: string;
    description: string;
    author: string;
    status: 'active' | 'inactive' | 'crashed';
    error_message?: string;
    has_admin_ui: boolean;
}

document.addEventListener('DOMContentLoaded', () => {
    initSettingsTabs();
    initClubSettingsForm();
    initConfirmTextPreviewEditor();
    initModulesManagement();
    initRolloverControls();
    initInstitutionalPagesEditor();

    window.addEventListener('kanso:auth-ready', async (e: Event) => {
        const ctx = (e as CustomEvent<AuthContext>).detail;
        if (ctx.role === 'Président') {
            await loadModulesList();
            await loadAuditLogs();
        }
    });
});

function initConfirmTextPreviewEditor(): void {
    const toggleBtn = document.getElementById('btn-toggle-confirm-text-edit');
    const previewBox = document.getElementById('confirm-text-preview-box');
    const previewContent = document.getElementById('confirm-text-preview-content');
    const editorWrapper = document.getElementById('confirm-text-editor-wrapper');
    const textarea = document.getElementById('setting-confirm-text') as HTMLTextAreaElement | null;

    if (!toggleBtn || !editorWrapper || !textarea) return;

    const escapeForPreview = (text: string): string => {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/\n/g, '<br>');
    };

    const syncPreview = (): void => {
        if (!previewContent) return;
        const val = textarea.value.trim();
        previewContent.innerHTML = val
            ? escapeForPreview(val)
            : '<span class="italic text-slate-400">Aucun texte personnalisé défini.</span>';
    };

    const setEditorOpen = (open: boolean): void => {
        editorWrapper.classList.toggle('hidden', !open);
        toggleBtn.textContent = open ? '👁️ Masquer l\'éditeur' : '✏️ Modifier le texte';
        if (open) {
            textarea.focus();
        }
    };

    toggleBtn.addEventListener('click', () => {
        const isCurrentlyHidden = editorWrapper.classList.contains('hidden');
        setEditorOpen(isCurrentlyHidden);
    });

    previewBox?.addEventListener('click', () => {
        if (editorWrapper.classList.contains('hidden')) {
            setEditorOpen(true);
        }
    });

    textarea.addEventListener('input', syncPreview);
}

function initSettingsTabs(): void {
    const tabs = [
        { btn: document.getElementById('tab-btn-club-general'), panel: document.getElementById('tab-panel-club-general') },
        { btn: document.getElementById('tab-btn-club-modules'), panel: document.getElementById('tab-panel-club-modules') },
        { btn: document.getElementById('tab-btn-club-rollover'), panel: document.getElementById('tab-panel-club-rollover') },
        { btn: document.getElementById('tab-btn-club-logs'), panel: document.getElementById('tab-panel-club-logs') },
        { btn: document.getElementById('tab-btn-club-pages'), panel: document.getElementById('tab-panel-club-pages') }
    ];

    tabs.forEach(({ btn, panel }) => {
        if (!btn || !panel) return;
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
            } else if (panel.id === 'tab-panel-club-logs') {
                loadAuditLogs();
            } else if (panel.id === 'tab-panel-club-pages') {
                loadInstitutionalPage();
            }
        });
    });
}

function initClubSettingsForm(): void {
    const form = document.getElementById('form-club-settings') as HTMLFormElement | null;
    const btn = document.getElementById('btn-save-settings') as HTMLButtonElement | null;
    if (!form) return;

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        const payload = Object.fromEntries(new FormData(form).entries());

        const basePrice = parseFloat(String(payload.membership_base_price ?? ''));
        if (Number.isNaN(basePrice) || basePrice < 0) {
            showToast('Veuillez saisir un montant de cotisation de base valide (0 € ou plus).', 'error');
            return;
        }
        const capacity = parseInt(String(payload.default_course_capacity ?? '30'), 10);
        if (Number.isNaN(capacity) || capacity < 1 || capacity > 500) {
            showToast('La capacité théorique par cours doit être comprise entre 1 et 500.', 'error');
            return;
        }
        const discountVal = parseFloat(String(payload.discount_value ?? '0'));
        if (Number.isNaN(discountVal) || discountVal < 0) {
            showToast('La valeur de la remise ne peut pas être négative.', 'error');
            return;
        }

        setButtonLoading(btn, true, 'Enregistrer les paramètres du club');
        try {
            const res = await fetchApi('/api/v1/settings', {
                method: 'PATCH',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Paramètres du club enregistrés avec succès !', 'success');
            }
        } finally {
            setButtonLoading(btn, false, 'Enregistrer les paramètres du club');
        }
    });
}

async function loadModulesList(): Promise<void> {
    const container = document.getElementById('modules-list-container');
    if (!container) return;

    const res = await fetchApi<ModuleItem[]>('/api/v1/sys/modules');
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
        } else if (isCrashed) {
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
            const btnToggle = e.currentTarget as HTMLButtonElement;
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
            } finally {
                btnToggle.disabled = false;
            }
        });

        container.appendChild(card);
    });
}

function initModulesManagement(): void {
    document.getElementById('btn-scan-modules')?.addEventListener('click', async () => {
        const res = await fetchApi('/api/v1/sys/modules/scan', { method: 'POST' });
        if (res.status === 'success') {
            showToast('Scan des modules actualisé !', 'success');
            await loadModulesList();
        }
    });
}

function initRolloverControls(): void {
    const modal = document.getElementById('rollover-confirm-modal');
    const openBtn = document.getElementById('btn-open-rollover-modal');
    const cancelBtn = document.getElementById('btn-cancel-rollover');
    const confirmBtn = document.getElementById('btn-confirm-rollover') as HTMLButtonElement | null;

    if (!modal || !openBtn || !confirmBtn) return;

    openBtn.addEventListener('click', () => modal.classList.remove('hidden'));
    cancelBtn?.addEventListener('click', () => modal.classList.add('hidden'));

    confirmBtn.addEventListener('click', async () => {
        const targetSeason = (document.getElementById('rollover-target-season') as HTMLInputElement)?.value.trim() || '';
        const refDate = (document.getElementById('rollover-reference-date') as HTMLInputElement)?.value.trim() || '';

        if (!targetSeason) {
            showToast('Veuillez indiquer la saison cible (ex: 2027-2028).', 'error');
            return;
        }
        if (!refDate) {
            showToast('Veuillez choisir une date de référence pour le calcul d\'âge.', 'error');
            return;
        }

        setButtonLoading(confirmBtn, true, 'Basculement en cours...');
        try {
            const res = await fetchApi<{
                promoted_count?: number;
                forced_ignored_count?: number;
                closed_registrations_count?: number;
                target_season: string;
            }>('/api/v1/tasks/rollover-season', {
                method: 'POST',
                body: JSON.stringify({
                    target_season: targetSeason,
                    reference_date: refDate
                })
            });

            if (res.status === 'success' && res.data) {
                modal.classList.add('hidden');
                const promoted = res.data.promoted_count ?? 0;
                const preserved = res.data.forced_ignored_count ?? 0;
                showToast(
                    `Basculement vers ${res.data.target_season} réussi ! ${promoted} changement(s) de catégorie (${preserved} surclassement(s) préservé(s)).`,
                    'success'
                );
                await loadAuditLogs();
            }
        } finally {
            setButtonLoading(confirmBtn, false, 'Confirmer le basculement');
        }
    });
}

function formatClubLogAction(rawAction: string): string {
    const map: Record<string, string> = {
        REGISTRATION_VALIDATED: 'Dossier d\'inscription validé',
        MEMBER_RADIATED: 'Adhérent radié',
        MEMBER_CREATED: 'Nouvel adhérent créé',
        MEMBER_UPDATED: 'Fiche adhérent modifiée',
        SEASON_ROLLOVER: 'Basculement annuel de saison',
        CLUB_SETTINGS_UPDATED: 'Paramètres du club mis à jour',
        '2FA_ENABLED': 'Double authentification activée',
        '2FA_DISABLED': 'Double authentification désactivée',
        PASSWORD_RESET_REQUESTED: 'Demande de réinitialisation de mot de passe',
        OTP_RESENT: 'Lien d\'activation renvoyé',
        ARTICLE_PUBLISHED: 'Article publié'
    };
    return map[rawAction] || rawAction;
}

async function loadAuditLogs(): Promise<void> {
    const tbody = document.getElementById('audit-logs-tbody');
    if (!tbody) return;

    const res = await fetchApi<any[]>('/api/v1/app-logs');
    if (res.status !== 'success' || !Array.isArray(res.data)) return;

    tbody.innerHTML = '';
    res.data.slice(0, 20).forEach((log) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50 transition';
        tr.innerHTML = `
            <td class="p-3 font-mono text-slate-500">#${log.id}</td>
            <td class="p-3 font-bold text-slate-900">${formatClubLogAction(String(log.action || ''))}</td>
            <td class="p-3 text-slate-600">${log.details || (log.target_type ? `${log.target_type} #${log.target_id || ''}` : '-')}</td>
            <td class="p-3 text-slate-400 font-mono">${log.created_at || ''}</td>
        `;
        tbody.appendChild(tr);
    });
}

interface PageData {
    id?: number;
    slug?: string;
    title: string;
    content: string;
    status: 'draft' | 'published';
}

async function loadInstitutionalPage(): Promise<void> {
    const select = document.getElementById('select-institutional-page') as HTMLSelectElement | null;
    const titleInput = document.getElementById('page-title-input') as HTMLInputElement | null;
    const statusSelect = document.getElementById('page-status-select') as HTMLSelectElement | null;
    const contentInput = document.getElementById('page-content-input') as HTMLTextAreaElement | null;

    if (!select || !titleInput || !statusSelect || !contentInput) return;

    const slug = select.value;
    try {
        const res = await fetchApi<PageData>(`/api/v1/pages/${slug}`);
        if (res.status === 'success' && res.data) {
            titleInput.value = res.data.title || '';
            statusSelect.value = res.data.status || 'published';
            contentInput.value = res.data.content || '';
        } else {
            titleInput.value = slug;
            statusSelect.value = 'published';
            contentInput.value = '';
        }
    } catch {
        showToast('Erreur lors du chargement de la page', 'error');
    }
}

function initInstitutionalPagesEditor(): void {
    const select = document.getElementById('select-institutional-page') as HTMLSelectElement | null;
    const form = document.getElementById('form-institutional-page') as HTMLFormElement | null;
    const btn = document.getElementById('btn-save-institutional-page') as HTMLButtonElement | null;

    select?.addEventListener('change', () => {
        loadInstitutionalPage();
    });

    form?.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        if (!select) return;

        const slug = select.value;
        const titleInput = document.getElementById('page-title-input') as HTMLInputElement | null;
        const statusSelect = document.getElementById('page-status-select') as HTMLSelectElement | null;
        const contentInput = document.getElementById('page-content-input') as HTMLTextAreaElement | null;

        if (!titleInput || !statusSelect || !contentInput) return;

        const cleanTitle = titleInput.value.trim();
        const cleanContent = contentInput.value.trim();
        if (!cleanTitle) {
            showToast('Veuillez saisir un titre pour cette page institutionnelle.', 'error');
            return;
        }
        if (!cleanContent) {
            showToast('Le contenu de la page ne peut pas être vide.', 'error');
            return;
        }

        setButtonLoading(btn, true, 'Enregistrement...');
        try {
            const payload = {
                title: titleInput.value.trim(),
                status: statusSelect.value,
                content: contentInput.value
            };

            const res = await fetchApi<PageData>(`/api/v1/pages/${slug}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });

            if (res.status === 'success') {
                showToast(`Page « ${payload.title} » enregistrée avec succès !`, 'success');
            } else {
                showToast(res.message || 'Erreur lors de l\'enregistrement', 'error');
            }
        } finally {
            setButtonLoading(btn, false, 'Enregistrer la page institutionnelle');
        }
    });
}
