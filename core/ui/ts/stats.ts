/**
 * @file stats.ts
 * @description Pilotage interactif des tableaux de bord analytiques et KPIs (US 5.2).
 * Assure l'actualisation dynamique des effectifs, taux de renouvellement et indicateurs financiers.
 */

import { fetchApi } from './api.js';

interface FinancialKpiData {
    total_validated_amount: number;
    total_pending_amount: number;
    total_registrations: number;
    by_payment_method: Array<{ payment_method: string; count: number; total: number }>;
}

interface KpiResponseData {
    role: string;
    total_members: number;
    active_members: number;
    pending_members: number;
    radiated_members: number;
    renewal_rate: number;
    finances: FinancialKpiData | null;
}

interface DemographicsData {
    total_members: number;
    minors_count: number;
    adults_count: number;
    minors_pct: number;
    adults_pct: number;
    gender_ratio: {
        male: number;
        female: number;
        male_pct: number;
        female_pct: number;
    };
    discipline_occupancy: Array<{
        discipline_id: number;
        name: string;
        enrolled_count: number;
        capacity: number;
        occupancy_rate: number;
    }>;
}

document.addEventListener('DOMContentLoaded', () => {
    loadKpiStats();
    loadDemographicsStats();
});

async function loadKpiStats(): Promise<void> {
    try {
        const res = await fetchApi<KpiResponseData>('/api/v1/stats/kpis');
        if (res.status !== 'success' || !res.data) return;

        const d = res.data;
        const setTxt = (id: string, val: string | number): void => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };

        setTxt('kpi-total-members', d.total_members);
        setTxt('kpi-active-members', d.active_members);
        setTxt('kpi-pending-members', d.pending_members);
        setTxt('kpi-renewal-rate', `${d.renewal_rate}%`);

        if (d.finances) {
            setTxt('kpi-total-revenue', `${d.finances.total_validated_amount.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} €`);
            setTxt('kpi-pending-revenue', `${d.finances.total_pending_amount.toLocaleString('fr-FR', { minimumFractionDigits: 2 })} €`);
        }
    } catch {
        // Mode dégradé silencieux basé sur le rendu SSR
    }
}

async function loadDemographicsStats(): Promise<void> {
    try {
        const res = await fetchApi<DemographicsData>('/api/v1/stats/demographics');
        if (res.status !== 'success' || !res.data) return;

        const d = res.data;
        const setTxt = (id: string, val: string | number): void => {
            const el = document.getElementById(id);
            if (el) el.textContent = String(val);
        };

        setTxt('kpi-minors-adults', `${d.minors_count} / ${d.adults_count}`);
        if (d.gender_ratio) {
            setTxt('kpi-gender-ratio', `${d.gender_ratio.male_pct}% / ${d.gender_ratio.female_pct}%`);
        }
    } catch {
        // Mode dégradé silencieux basé sur le rendu SSR
    }
}
