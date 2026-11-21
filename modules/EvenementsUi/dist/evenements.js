/**
 * @file evenements.js
 * @description Pilotage de la page /evenements (calendrier, création gestionnaire,
 * inscription/désinscription en 1 clic avec jauge et export CSV des participants).
 */

import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

/** @type {string} */
let lastCsvContent = '';
/** @type {string} */
let lastCsvFilename = 'participants.csv';

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

const TYPE_LABELS = {
    stage: 'Stage',
    grade: 'Passage de grade',
    competition: 'Compétition',
    autre: 'Événement'
};

/**
 * Charge le calendrier complet et met à jour l'interface.
 *
 * @returns {Promise<void>}
 */
async function loadEventsCalendar() {
    const res = await fetchApi('/api/v1/modules/evenements/events');
    if (res.status !== 'success' || !res.data) return;

    const { can_manage: canManage, is_authenticated: isAuth, disciplines, eligible_members: eligible, events } = res.data;

    const createSec = document.getElementById('events-create-section');
    if (createSec) {
        createSec.classList.toggle('hidden', !canManage);
    }

    const discSelect = document.getElementById('event-discipline-select');
    if (discSelect && Array.isArray(disciplines)) {
        discSelect.innerHTML = '<option value="">Toutes disciplines</option>'
            + disciplines.map((d) => `<option value="${Number(d.id)}">${escapeHtml(d.name)}</option>`).join('');
    }

    const grid = document.getElementById('events-list-grid');
    if (!grid) return;

    const list = Array.isArray(events) ? events : [];
    const members = Array.isArray(eligible) ? eligible : [];

    if (list.length === 0) {
        grid.innerHTML = '<p class="text-xs text-slate-400 italic col-span-full">Aucun événement programmé pour le moment.</p>';
        return;
    }

    grid.innerHTML = list.map((ev) => {
        const eid = Number(ev.id);
        const typeLabel = TYPE_LABELS[ev.event_type] || 'Événement';
        const isClosed = ev.status === 'closed';
        const isDraft = ev.status === 'draft';
        const isFull = Boolean(ev.is_full);
        const capText = ev.max_capacity && ev.max_capacity > 0
            ? `${Number(ev.registered_count || 0)} / ${Number(ev.max_capacity)} inscrits`
            : `${Number(ev.registered_count || 0)} inscrit(s)`;
        const registeredIds = Array.isArray(ev.registered_member_ids) ? ev.registered_member_ids.map(Number) : [];

        const defaultMemberId = members.length > 0 ? Number(members[0].id) : 0;
        const isDefaultRegistered = defaultMemberId > 0 && registeredIds.includes(defaultMemberId);

        let registrationBlock = '';
        if (!isAuth) {
            registrationBlock = `<a href="/login" class="block text-center w-full px-3 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition">Connectez-vous pour vous inscrire</a>`;
        } else if (members.length === 0) {
            registrationBlock = `<p class="text-[11px] text-slate-400 italic">Aucune fiche adhérent rattachée à votre compte.</p>`;
        } else {
            const memberOptions = members.map((m) => `<option value="${Number(m.id)}">${escapeHtml(m.first_name)} ${escapeHtml(m.last_name)}</option>`).join('');
            registrationBlock = `
                <div class="space-y-2">
                    <select data-event-member-select="${eid}" data-testid="event-member-select-${eid}" class="w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs bg-slate-50">
                        ${memberOptions}
                    </select>
                    <div class="flex items-center gap-2" data-event-reg-actions="${eid}">
                        ${renderActionButton(eid, defaultMemberId, isDefaultRegistered, isFull, isClosed || isDraft)}
                    </div>
                </div>
            `;
        }

        const managerActions = canManage ? `
            <div class="pt-2 border-t border-slate-100 flex items-center justify-between gap-2">
                <button type="button" data-view-participants="${eid}" data-testid="btn-event-participants-${eid}" class="px-2.5 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-[11px] font-bold transition">
                    👥 Inscrits (${Number(ev.registered_count || 0)})
                </button>
                <button type="button" data-toggle-status="${eid}" data-current-status="${escapeHtml(ev.status)}" data-testid="btn-toggle-event-status-${eid}" class="px-2.5 py-1.5 rounded-lg ${isClosed ? 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' : 'bg-amber-50 text-amber-700 hover:bg-amber-100'} text-[11px] font-bold transition">
                    ${isClosed ? 'Rouvrir' : 'Clôturer'}
                </button>
            </div>
        ` : '';

        return `
            <article data-testid="event-card-${eid}" class="bg-white rounded-2xl border border-slate-200 p-5 shadow-xs flex flex-col justify-between gap-4 ${isFull ? 'event-card-full' : ''}">
                <div class="space-y-2">
                    <div class="flex items-center justify-between gap-2">
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-bold uppercase bg-emerald-50 text-emerald-700 border border-emerald-200">${escapeHtml(typeLabel)}</span>
                        <span class="text-[11px] font-mono text-slate-500">${escapeHtml(ev.event_date)}</span>
                    </div>
                    <h2 class="text-base font-extrabold text-slate-900 leading-snug">${escapeHtml(ev.title)}</h2>
                    <div class="text-xs text-slate-500 space-y-0.5">
                        <div>📍 ${escapeHtml(ev.location || 'Dojo du club')}</div>
                        ${ev.discipline_name ? `<div>🥋 ${escapeHtml(ev.discipline_name)}</div>` : ''}
                        ${ev.description ? `<p class="pt-1 text-slate-600">${escapeHtml(ev.description)}</p>` : ''}
                    </div>
                </div>
                <div class="space-y-3 pt-3 border-t border-slate-100">
                    <div class="flex items-center justify-between text-xs">
                        <span class="font-bold ${isFull ? 'text-red-600' : 'text-slate-700'}">${escapeHtml(capText)}</span>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-bold ${isClosed ? 'bg-slate-200 text-slate-700' : (isFull ? 'bg-red-50 text-red-700' : 'bg-blue-50 text-blue-700')}">
                            ${isClosed ? 'Clôturé' : (isFull ? 'Complet' : 'Ouvert')}
                        </span>
                    </div>
                    ${registrationBlock}
                    ${managerActions}
                </div>
            </article>
        `;
    }).join('');

    // Listeners sélecteur membre -> met à jour le bouton Inscrire / Désinscrire
    list.forEach((ev) => {
        const eid = Number(ev.id);
        const selectEl = grid.querySelector(`[data-event-member-select="${eid}"]`);
        const actionsBox = grid.querySelector(`[data-event-reg-actions="${eid}"]`);
        const registeredIds = Array.isArray(ev.registered_member_ids) ? ev.registered_member_ids.map(Number) : [];
        const isClosed = ev.status !== 'published';
        const isFull = Boolean(ev.is_full);

        const wireRegButtons = () => {
            if (!actionsBox) return;
            actionsBox.querySelectorAll('[data-reg-event-id]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const mid = Number(selectEl?.value || 0);
                    if (!mid) return;
                    await handleRegister(eid, mid, btn);
                });
            });
            actionsBox.querySelectorAll('[data-unreg-event-id]').forEach((btn) => {
                btn.addEventListener('click', async () => {
                    const mid = Number(selectEl?.value || 0);
                    if (!mid) return;
                    await handleUnregister(eid, mid, btn);
                });
            });
        };

        wireRegButtons();

        selectEl?.addEventListener('change', () => {
            if (!actionsBox) return;
            const mid = Number(selectEl.value || 0);
            const isReg = registeredIds.includes(mid);
            actionsBox.innerHTML = renderActionButton(eid, mid, isReg, isFull, isClosed);
            wireRegButtons();
        });
    });

    // Listeners gestionnaires : participants & clôture
    grid.querySelectorAll('[data-view-participants]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const eid = Number(btn.getAttribute('data-view-participants'));
            if (eid > 0) openParticipantsModal(eid);
        });
    });

    grid.querySelectorAll('[data-toggle-status]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const eid = Number(btn.getAttribute('data-toggle-status'));
            const cur = String(btn.getAttribute('data-current-status') || 'published');
            const nextStatus = cur === 'closed' ? 'published' : 'closed';
            const r = await fetchApi(`/api/v1/modules/evenements/events/${eid}`, {
                method: 'PATCH',
                body: JSON.stringify({ status: nextStatus })
            });
            if (r.status === 'success') {
                showToast(nextStatus === 'closed' ? 'Événement clôturé.' : 'Événement rouvert.', 'success');
                await loadEventsCalendar();
            }
        });
    });
}

/**
 * Construit le bouton d'inscription ou de désinscription pour une carte événement.
 *
 * @param {number}  eventId      Identifiant de l'événement.
 * @param {number}  memberId     Identifiant de l'adhérent sélectionné.
 * @param {boolean} isRegistered Déjà inscrit ou non.
 * @param {boolean} isFull       Événement complet ou non.
 * @param {boolean} isClosed     Événement clôturé ou non.
 * @returns {string}
 */
function renderActionButton(eventId, memberId, isRegistered, isFull, isClosed) {
    if (isRegistered) {
        return `<button type="button" data-unreg-event-id="${eventId}" data-member-id="${memberId}" data-testid="btn-unregister-event-${eventId}" class="w-full px-3 py-2 rounded-xl bg-red-50 hover:bg-red-100 text-red-700 border border-red-200 text-xs font-bold transition">✕ Se désinscrire</button>`;
    }
    const disabled = isFull || isClosed;
    return `<button type="button" data-reg-event-id="${eventId}" data-member-id="${memberId}" data-testid="btn-register-event-${eventId}" ${disabled ? 'disabled' : ''} class="w-full px-3 py-2 rounded-xl ${disabled ? 'bg-slate-200 text-slate-500 cursor-not-allowed' : 'bg-brand-primary hover:opacity-90 text-white'} text-xs font-bold transition">${isClosed ? 'Inscriptions closes' : (isFull ? 'Complet' : '✓ S\'inscrire en 1 clic')}</button>`;
}

/**
 * Enregistre une inscription via POST /api/v1/modules/evenements/events/{id}/register.
 *
 * @param {number}            eventId  Identifiant de l'événement.
 * @param {number}            memberId Identifiant de l'adhérent.
 * @param {HTMLButtonElement} btn      Bouton déclencheur.
 * @returns {Promise<void>}
 */
async function handleRegister(eventId, memberId, btn) {
    setButtonLoading(btn, true, '✓ S\'inscrire en 1 clic');
    try {
        const res = await fetchApi(`/api/v1/modules/evenements/events/${eventId}/register`, {
            method: 'POST',
            body: JSON.stringify({ member_id: memberId })
        });
        if (res.status === 'success') {
            showToast(res.message || 'Inscription confirmée !', 'success');
            await loadEventsCalendar();
        }
    } finally {
        setButtonLoading(btn, false, '✓ S\'inscrire en 1 clic');
    }
}

/**
 * Annule une inscription via DELETE /api/v1/modules/evenements/events/{id}/register/{memberId}.
 *
 * @param {number}            eventId  Identifiant de l'événement.
 * @param {number}            memberId Identifiant de l'adhérent.
 * @param {HTMLButtonElement} btn      Bouton déclencheur.
 * @returns {Promise<void>}
 */
async function handleUnregister(eventId, memberId, btn) {
    setButtonLoading(btn, true, '✕ Se désinscrire');
    try {
        const res = await fetchApi(`/api/v1/modules/evenements/events/${eventId}/register/${memberId}`, {
            method: 'DELETE'
        });
        if (res.status === 'success') {
            showToast(res.message || 'Désinscription effectuée.', 'success');
            await loadEventsCalendar();
        }
    } finally {
        setButtonLoading(btn, false, '✕ Se désinscrire');
    }
}

/**
 * Ouvre la modale des participants d'un événement (gestionnaires uniquement).
 *
 * @param {number} eventId Identifiant de l'événement.
 * @returns {Promise<void>}
 */
async function openParticipantsModal(eventId) {
    const res = await fetchApi(`/api/v1/modules/evenements/events/${eventId}/participants`);
    if (res.status !== 'success' || !res.data) return;

    const modal = document.getElementById('event-participants-modal');
    const titleEl = document.getElementById('participants-modal-title');
    const listEl = document.getElementById('participants-list-container');

    const ev = res.data.event || {};
    const participants = Array.isArray(res.data.participants) ? res.data.participants : [];
    lastCsvContent = String(res.data.csv_content || '');
    lastCsvFilename = `participants-evenement-${eventId}.csv`;

    if (titleEl) titleEl.textContent = `${ev.title || 'Événement'} (${participants.length})`;
    if (listEl) {
        if (participants.length === 0) {
            listEl.innerHTML = '<p class="py-3 text-slate-400 italic">Aucun participant inscrit pour le moment.</p>';
        } else {
            listEl.innerHTML = participants.map((p) => `
                <div class="py-2 flex items-center justify-between gap-2">
                    <span class="font-bold text-slate-800">${escapeHtml(p.first_name)} ${escapeHtml(p.last_name)}</span>
                    <span class="text-slate-400 font-mono text-[11px]">${escapeHtml(p.registered_at)}</span>
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
    loadEventsCalendar();

    const form = document.getElementById('events-create-form');
    const submitBtn = document.getElementById('btn-create-event');
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(submitBtn, true, 'Publier l\'événement');
        try {
            const payload = {
                title: String(document.getElementById('event-title-input')?.value || '').trim(),
                event_type: String(document.getElementById('event-type-select')?.value || 'stage'),
                event_date: String(document.getElementById('event-date-input')?.value || '').trim(),
                location: String(document.getElementById('event-location-input')?.value || '').trim(),
                max_capacity: String(document.getElementById('event-capacity-input')?.value || '').trim(),
                discipline_id: String(document.getElementById('event-discipline-select')?.value || '').trim() || null,
                description: String(document.getElementById('event-desc-input')?.value || '').trim(),
                status: 'published'
            };

            const res = await fetchApi('/api/v1/modules/evenements/events', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Événement publié avec succès !', 'success');
                form.reset();
                await loadEventsCalendar();
            }
        } finally {
            setButtonLoading(submitBtn, false, 'Publier l\'événement');
        }
    });

    document.getElementById('btn-close-participants-modal')?.addEventListener('click', () => {
        const modal = document.getElementById('event-participants-modal');
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    });

    document.getElementById('btn-export-participants-csv')?.addEventListener('click', () => {
        if (!lastCsvContent) return;
        const blob = new Blob([lastCsvContent], { type: 'text/csv;charset=utf-8;' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = lastCsvFilename;
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
    });
});
