/**
 * @file presence.js
 * @description Pilotage de la page /presences du module Présences & Suivi Tatami.
 * Gère l'ouverture idempotente des séances, le pointage tactile mobile/desktop
 * et la modale d'historique d'assiduité RGPD.
 */

import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

/** @type {number|null} */
let activeSessionId = null;

/** @type {Map<number, string>} */
const rosterSelections = new Map();

/**
 * Échappe les caractères HTML pour prévenir toute injection XSS.
 *
 * @param {string} str Chaîne brute.
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Charge les disciplines autorisées et les séances récentes.
 *
 * @returns {Promise<void>}
 */
async function loadSessionsAndDisciplines() {
    const res = await fetchApi('/api/v1/modules/presence/sessions');
    if (res.status !== 'success' || !res.data) return;

    const discSelect = document.getElementById('presence-discipline-select');
    const disciplines = Array.isArray(res.data.disciplines) ? res.data.disciplines : [];
    if (discSelect) {
        const currentVal = discSelect.value;
        discSelect.innerHTML = disciplines.length === 0
            ? '<option value="">Aucune discipline disponible</option>'
            : disciplines.map((d) => `<option value="${Number(d.id)}">${escapeHtml(d.name)}</option>`).join('');
        if (currentVal && disciplines.some((d) => String(d.id) === currentVal)) {
            discSelect.value = currentVal;
        }
    }

    const recentContainer = document.getElementById('presence-recent-sessions');
    const sessions = Array.isArray(res.data.sessions) ? res.data.sessions : [];
    if (recentContainer) {
        if (sessions.length === 0) {
            recentContainer.innerHTML = '<p class="py-3 text-slate-400 italic">Aucune séance enregistrée pour le moment.</p>';
        } else {
            recentContainer.innerHTML = sessions.map((s) => `
                <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <div>
                        <span class="font-bold text-slate-900">${escapeHtml(s.discipline_name)}</span>
                        <span class="mx-1.5 text-slate-300">·</span>
                        <span class="font-mono text-slate-700">${escapeHtml(s.session_date)} (${escapeHtml(s.time_slot)})</span>
                        <span class="ml-2 px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-semibold">${Number(s.present_count || 0)} P</span>
                        <span class="ml-1 px-2 py-0.5 rounded-full bg-red-50 text-red-600 font-semibold">${Number(s.absent_count || 0)} A</span>
                        <span class="ml-1 px-2 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold">${Number(s.excused_count || 0)} E</span>
                    </div>
                    <button type="button" data-open-session-id="${Number(s.id)}" class="px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold transition shrink-0">
                        Ouvrir
                    </button>
                </div>
            `).join('');

            recentContainer.querySelectorAll('[data-open-session-id]').forEach((btn) => {
                btn.addEventListener('click', () => {
                    const sid = Number(btn.getAttribute('data-open-session-id'));
                    if (sid > 0) loadSessionRoster(sid);
                });
            });
        }
    }
}

/**
 * Charge la feuille d'appel anonymisée RGPD d'une séance.
 *
 * @param {number} sessionId Identifiant de la séance.
 * @returns {Promise<void>}
 */
async function loadSessionRoster(sessionId) {
    const res = await fetchApi(`/api/v1/modules/presence/sessions/${sessionId}/roster`);
    if (res.status !== 'success' || !res.data) return;

    activeSessionId = sessionId;
    rosterSelections.clear();

    const section = document.getElementById('presence-roll-section');
    const titleEl = document.getElementById('presence-roll-title');
    const metaEl = document.getElementById('presence-roll-meta');
    const listEl = document.getElementById('presence-roster-list');

    if (section) section.classList.remove('hidden');
    const sess = res.data.session || {};
    if (titleEl) titleEl.textContent = `Feuille d'appel — ${sess.discipline_name || 'Discipline'}`;
    if (metaEl) metaEl.textContent = `Séance du ${sess.session_date || ''} · Créneau ${sess.time_slot || ''}`;

    const roster = Array.isArray(res.data.roster) ? res.data.roster : [];
    if (!listEl) return;

    if (roster.length === 0) {
        listEl.innerHTML = '<p class="py-4 text-xs text-slate-400 italic">Aucun pratiquant inscrit dans cette discipline.</p>';
        return;
    }

    listEl.innerHTML = roster.map((m) => {
        const mid = Number(m.member_id);
        const currentStatus = m.attendance_status || '';
        if (currentStatus) {
            rosterSelections.set(mid, currentStatus);
        }

        return `
            <div class="py-3 flex flex-col sm:flex-row sm:items-center justify-between gap-3" data-roster-member-id="${mid}">
                <div class="flex items-center gap-3">
                    <div>
                        <div class="font-bold text-sm text-slate-900">${escapeHtml(m.first_name)} ${escapeHtml(m.last_name)}</div>
                        <button type="button" data-history-member-id="${mid}" data-testid="btn-member-history-${mid}" class="text-[11px] text-brand-primary hover:underline font-semibold">
                            Voir l'historique d'assiduité
                        </button>
                    </div>
                </div>
                <div class="flex items-center gap-1.5">
                    <button type="button" data-member-id="${mid}" data-status="present" data-active="${currentStatus === 'present' ? 'true' : 'false'}" data-testid="presence-btn-present-${mid}" class="presence-status-btn px-3 py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 text-xs font-bold">
                        Présent
                    </button>
                    <button type="button" data-member-id="${mid}" data-status="absent" data-active="${currentStatus === 'absent' ? 'true' : 'false'}" data-testid="presence-btn-absent-${mid}" class="presence-status-btn px-3 py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 text-xs font-bold">
                        Absent
                    </button>
                    <button type="button" data-member-id="${mid}" data-status="excused" data-active="${currentStatus === 'excused' ? 'true' : 'false'}" data-testid="presence-btn-excused-${mid}" class="presence-status-btn px-3 py-1.5 rounded-xl border border-slate-300 bg-white text-slate-700 text-xs font-bold">
                        Excusé
                    </button>
                </div>
            </div>
        `;
    }).join('');

    listEl.querySelectorAll('.presence-status-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const mid = Number(btn.getAttribute('data-member-id'));
            const st = String(btn.getAttribute('data-status') || '');
            if (!mid || !st) return;
            setMemberSelection(mid, st);
        });
    });

    listEl.querySelectorAll('[data-history-member-id]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const mid = Number(btn.getAttribute('data-history-member-id'));
            if (mid > 0) openMemberHistory(mid);
        });
    });
}

/**
 * Met à jour visuellement et en mémoire le statut sélectionné pour un pratiquant.
 *
 * @param {number} memberId Identifiant adhérent.
 * @param {string} status   'present' | 'absent' | 'excused'.
 */
function setMemberSelection(memberId, status) {
    rosterSelections.set(memberId, status);
    document.querySelectorAll(`.presence-status-btn[data-member-id="${memberId}"]`).forEach((btn) => {
        const isMatch = btn.getAttribute('data-status') === status;
        btn.setAttribute('data-active', isMatch ? 'true' : 'false');
    });
}

/**
 * Ouvre la modale d'historique d'assiduité pour un adhérent donné.
 *
 * @param {number} memberId Identifiant adhérent.
 * @returns {Promise<void>}
 */
async function openMemberHistory(memberId) {
    const res = await fetchApi(`/api/v1/modules/presence/members/${memberId}/history`);
    if (res.status !== 'success' || !res.data) return;

    const modal = document.getElementById('presence-history-modal');
    const nameEl = document.getElementById('presence-history-member-name');
    const rateEl = document.getElementById('presence-history-rate');
    const presEl = document.getElementById('presence-history-present');
    const absEl = document.getElementById('presence-history-absent');
    const excEl = document.getElementById('presence-history-excused');
    const recEl = document.getElementById('presence-history-records');

    const m = res.data.member || {};
    const sum = res.data.summary || {};
    const records = Array.isArray(res.data.records) ? res.data.records : [];

    if (nameEl) nameEl.textContent = `${m.first_name || ''} ${m.last_name || ''}`.trim();
    if (rateEl) rateEl.textContent = `${Number(sum.attendance_rate || 0)}%`;
    if (presEl) presEl.textContent = String(Number(sum.present || 0));
    if (absEl) absEl.textContent = String(Number(sum.absent || 0));
    if (excEl) excEl.textContent = String(Number(sum.excused || 0));

    if (recEl) {
        if (records.length === 0) {
            recEl.innerHTML = '<p class="py-3 text-slate-400 italic">Aucun pointage enregistré pour cet adhérent.</p>';
        } else {
            const labels = { present: 'Présent', absent: 'Absent', excused: 'Excusé' };
            recEl.innerHTML = records.map((r) => `
                <div class="py-2 flex items-center justify-between gap-2">
                    <div>
                        <span class="font-bold text-slate-800">${escapeHtml(r.discipline_name)}</span>
                        <span class="text-slate-400 ml-1">${escapeHtml(r.session_date)} (${escapeHtml(r.time_slot)})</span>
                    </div>
                    <span class="px-2 py-0.5 rounded-full font-bold ${r.status === 'present' ? 'bg-emerald-50 text-emerald-700' : (r.status === 'absent' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700')}">
                        ${escapeHtml(labels[r.status] || r.status)}
                    </span>
                </div>
            `).join('');
        }
    }

    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadSessionsAndDisciplines();

    const form = document.getElementById('presence-session-form');
    const openBtn = document.getElementById('btn-open-presence-session');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const disciplineId = Number(document.getElementById('presence-discipline-select')?.value || 0);
        const sessionDate = String(document.getElementById('presence-date-input')?.value || '').trim();
        const timeSlot = String(document.getElementById('presence-slot-input')?.value || '18:00-19:30').trim();

        setButtonLoading(openBtn, true, 'Ouvrir la feuille d\'appel');
        try {
            const res = await fetchApi('/api/v1/modules/presence/sessions', {
                method: 'POST',
                body: JSON.stringify({
                    discipline_id: disciplineId,
                    session_date: sessionDate,
                    time_slot: timeSlot
                })
            });
            if (res.status === 'success' && res.data?.session?.id) {
                await loadSessionRoster(Number(res.data.session.id));
                await loadSessionsAndDisciplines();
            }
        } finally {
            setButtonLoading(openBtn, false, 'Ouvrir la feuille d\'appel');
        }
    });

    document.getElementById('btn-mark-all-present')?.addEventListener('click', () => {
        document.querySelectorAll('[data-roster-member-id]').forEach((row) => {
            const mid = Number(row.getAttribute('data-roster-member-id'));
            if (mid > 0) setMemberSelection(mid, 'present');
        });
    });

    const saveBtn = document.getElementById('btn-save-presence-roll');
    saveBtn?.addEventListener('click', async () => {
        if (!activeSessionId) return;
        const entries = Array.from(rosterSelections.entries()).map(([member_id, status]) => ({
            member_id,
            status
        }));
        if (entries.length === 0) {
            showToast('Sélectionnez au moins un statut avant d\'enregistrer.', 'error');
            return;
        }

        setButtonLoading(saveBtn, true, 'Enregistrer l\'appel');
        try {
            const res = await fetchApi(`/api/v1/modules/presence/sessions/${activeSessionId}/mark`, {
                method: 'POST',
                body: JSON.stringify({ entries })
            });
            if (res.status === 'success') {
                showToast(res.message || 'Appel enregistré avec succès.', 'success');
                await loadSessionsAndDisciplines();
            }
        } finally {
            setButtonLoading(saveBtn, false, 'Enregistrer l\'appel');
        }
    });

    document.getElementById('btn-close-presence-history')?.addEventListener('click', () => {
        const modal = document.getElementById('presence-history-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });
});
