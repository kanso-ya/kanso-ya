/**
 * @file stats.ts
 * @description Pilotage interactif des tableaux de bord analytiques et KPIs (US 5.2).
 * Assure l'actualisation dynamique des effectifs, taux de renouvellement et indicateurs financiers.
 */
import { fetchApi } from './api.js';
document.addEventListener('DOMContentLoaded', () => {
    loadKpiStats();
    loadDemographicsStats();
});
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
        }
    }
    catch {
        // Mode dégradé silencieux basé sur le rendu SSR
    }
}
