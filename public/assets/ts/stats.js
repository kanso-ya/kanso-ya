/**
 * @file stats.ts
 * @description Pilotage interactif des tableaux de bord analytiques et KPIs (US 5.2).
 * Assure l'actualisation dynamique des effectifs, taux de renouvellement et indicateurs financiers.
 */
import { fetchApi } from './api.js';
document.addEventListener('DOMContentLoaded', () => {
    initStatsTabs();
    initStatsDisciplineTabs();
    loadKpiStats();
    loadDemographicsStats();
});
function initStatsTabs() {
    const tabButtons = Array.from(document.querySelectorAll('.stats-tab-btn'));
    const panels = Array.from(document.querySelectorAll('.stats-tab-panel'));
    if (tabButtons.length === 0 || panels.length === 0)
        return;
    const activateTab = (targetId) => {
        tabButtons.forEach((btn) => {
            const isSelected = btn.getAttribute('data-tab-target') === targetId;
            btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            btn.className = `stats-tab-btn px-4 py-2 rounded-xl text-xs font-bold transition shrink-0 ${isSelected
                ? 'bg-brand-primary text-white shadow-xs'
                : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`;
        });
        panels.forEach((panel) => {
            const match = panel.getAttribute('data-tab-panel') === targetId;
            panel.classList.toggle('hidden', !match);
        });
    };
    tabButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-tab-target');
            if (targetId) {
                activateTab(targetId);
            }
        });
    });
    // Garantit qu'un seul panneau est visible à l'initialisation
    const initialBtn = tabButtons.find((b) => b.getAttribute('aria-selected') === 'true') || tabButtons[0];
    const initialTarget = initialBtn?.getAttribute('data-tab-target');
    if (initialTarget) {
        activateTab(initialTarget);
    }
}
function initStatsDisciplineTabs() {
    const discButtons = Array.from(document.querySelectorAll('.stats-disc-tab-btn'));
    const discCards = Array.from(document.querySelectorAll('.stats-disc-card'));
    if (discButtons.length === 0 || discCards.length === 0)
        return;
    const activateDiscipline = (discId) => {
        discButtons.forEach((btn) => {
            const isSelected = btn.getAttribute('data-discipline-id') === discId;
            btn.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            btn.className = `stats-disc-tab-btn px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 ${isSelected
                ? 'bg-brand-primary text-white shadow-xs'
                : 'bg-white border border-slate-200 text-slate-700 hover:bg-slate-50'}`;
            const badge = btn.querySelector('span:last-child');
            if (badge) {
                badge.className = `px-2 py-0.5 rounded-full text-[10px] font-mono ${isSelected ? 'bg-black/20 text-white' : 'bg-slate-100 text-slate-600'}`;
            }
        });
        discCards.forEach((card) => {
            const match = card.getAttribute('data-discipline-id') === discId;
            card.classList.toggle('hidden', !match);
        });
    };
    discButtons.forEach((btn) => {
        btn.addEventListener('click', () => {
            const discId = btn.getAttribute('data-discipline-id');
            if (discId) {
                activateDiscipline(discId);
            }
        });
    });
}
async function loadKpiStats() {
    try {
        const res = await fetchApi('/api/v1/stats/kpis');
        if (res.status !== 'success' || !res.data)
            return;
        const d = res.data;
        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el)
                el.textContent = String(val);
        };
        setTxt('kpi-total-members', d.total_members);
        setTxt('kpi-active-members', d.active_members);
        setTxt('kpi-pending-members', d.pending_members);
        setTxt('kpi-renewal-rate', `${d.renewal_rate}%`);
        if (d.finances) {
            setTxt('kpi-total-revenue', `${d.finances.total_validated_amount.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} €`);
            setTxt('kpi-pending-revenue', `${d.finances.total_pending_amount.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} €`);
        }
    }
    catch {
        // Mode dégradé silencieux basé sur le rendu SSR
    }
}
async function loadDemographicsStats() {
    try {
        const res = await fetchApi('/api/v1/stats/demographics');
        if (res.status !== 'success' || !res.data)
            return;
        const d = res.data;
        const setTxt = (id, val) => {
            const el = document.getElementById(id);
            if (el)
                el.textContent = String(val);
        };
        setTxt('kpi-minors-adults', `${d.minors_count} / ${d.adults_count}`);
        if (d.gender_ratio) {
            setTxt('kpi-gender-ratio', `${d.gender_ratio.male_pct}% / ${d.gender_ratio.female_pct}%`);
            const otherPct = d.gender_ratio.other_pct ?? 0;
            const unspecPct = d.gender_ratio.unspecified_pct ?? 0;
            setTxt('kpi-gender-details', otherPct > 0 || unspecPct > 0
                ? `Autre ${otherPct}% · Non renseigné ${unspecPct}%`
                : 'Données réelles déclarées');
        }
    }
    catch {
        // Mode dégradé silencieux basé sur le rendu SSR
    }
}
