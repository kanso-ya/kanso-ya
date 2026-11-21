/**
 * @file members.ts
 * @description Annuaire des Adhérents & Rattachements avec gestion Multi-Disciplines,
 * Grades (Catégories d'âge / Cours) et Niveaux techniques (Ceintures / Gants) :
 * - Affichage direct des badges de Cours (Grade) et Niveau (Ceinture) sur chaque ligne adhérent
 * - Filtre par Discipline, par Statut et Recherche instantanée 0ms
 * - Modale d'édition permettant d'ajouter/supprimer/modifier les disciplines, grades et niveaux d'un adhérent.
 */
import { fetchApi } from './api.js';
import { currentAuthContext, openConfirmModal, setButtonLoading, showToast } from './main.js';
let allMembers = [];
let disciplinesCatalog = [];
let activeStatusFilter = 'all';
let activeDisciplineFilter = 'all';
let activeGradeFilter = null;
let activeLevelFilter = null;
let activeAgeFilter = '';
let activeHealthFilter = '';
let activeSearchQuery = '';
let activeSortColumn = 'name';
let activeSortDirection = 'asc';
const selectedMemberIds = new Set();
document.addEventListener('DOMContentLoaded', async () => {
    // Lecture des paramètres URL (ex: /members?discipline=1&grade=2)
    const urlParams = new URLSearchParams(window.location.search);
    const discParam = urlParams.get('discipline');
    const gradeParam = urlParams.get('grade');
    const levelParam = urlParams.get('level');
    if (discParam) {
        activeDisciplineFilter = discParam;
    }
    if (gradeParam) {
        activeGradeFilter = parseInt(gradeParam, 10);
    }
    if (levelParam) {
        activeLevelFilter = parseInt(levelParam, 10);
    }
    await loadDisciplinesCatalog();
    await loadMembersTable();
    initStatusTabs();
    initDisciplineFilter();
    initAdvancedFilters();
    initTableSorting();
    initBulkActions();
    initExportCsv();
    initEditMemberModal();
    initMedicalUploadModal();
    if (discParam) {
        const sel = document.getElementById('member-discipline-filter');
        if (sel)
            sel.value = discParam;
    }
    window.addEventListener('kanso:members-updated', () => loadMembersTable());
    window.addEventListener('kanso:auth-ready', (e) => {
        const ctx = e.detail;
        if (ctx.role === 'Adhérent') {
            document.getElementById('edit-status-wrapper')?.classList.add('hidden');
            document.getElementById('edit-practices-section')?.classList.add('hidden');
        }
        renderMembersRows();
    });
});
function computeAge(birthDateStr) {
    if (!birthDateStr)
        return 25;
    const dob = new Date(birthDateStr);
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
        age--;
    }
    return age;
}
async function loadDisciplinesCatalog() {
    const res = await fetchApi('/api/v1/disciplines');
    if (res.status === 'success' && Array.isArray(res.data)) {
        disciplinesCatalog = res.data;
        const filterSelect = document.getElementById('member-discipline-filter');
        if (filterSelect) {
            filterSelect.innerHTML = `<option value="all">Toutes les disciplines</option>`;
            disciplinesCatalog.forEach((d) => {
                const opt = document.createElement('option');
                opt.value = String(d.id);
                opt.textContent = `🥋 ${d.name}`;
                filterSelect.appendChild(opt);
            });
        }
    }
}
async function loadMembersTable() {
    const response = await fetchApi('/api/v1/members');
    if (response.status !== 'success' || !Array.isArray(response.data))
        return;
    allMembers = response.data;
    updateKpiCounters(allMembers);
    renderDisciplineTabs();
    renderMembersRows();
}
function updateKpiCounters(members) {
    const setTxt = (id, v) => {
        const el = document.getElementById(id);
        if (el)
            el.textContent = String(v);
    };
    setTxt('kpi-total', members.length);
    setTxt('kpi-active', members.filter((m) => m.status === 'active').length);
    setTxt('kpi-pending', members.filter((m) => m.status === 'pending').length);
    setTxt('kpi-radiated', members.filter((m) => m.status === 'radiated').length);
}
function renderMembersRows() {
    const tbody = document.querySelector('#members-table tbody');
    if (!tbody)
        return;
    const role = currentAuthContext.role;
    const perms = currentAuthContext.permissions || [];
    const canEdit = perms.includes('members.edit') || role === 'Superadmin' || role === 'Adhérent';
    const canDeleteOrRadiate = perms.includes('members.edit') || role === 'Superadmin';
    const canResendOtp = ['Président', 'Secrétaire', 'Trésorier', 'Superadmin'].includes(role);
    const isTeacher = (role === 'Entraîneur');
    const isAdherent = (role === 'Adhérent');
    let filtered = allMembers.filter((m) => {
        const matchStatus = activeStatusFilter === 'all' || m.status === activeStatusFilter;
        const matchDisc = activeDisciplineFilter === 'all'
            || (m.practices || []).some((p) => String(p.discipline_id) === activeDisciplineFilter);
        // Filtre Âge
        const age = computeAge(m.birth_date);
        const matchAge = activeAgeFilter === ''
            || (activeAgeFilter === 'minor' && age < 18)
            || (activeAgeFilter === 'adult' && age >= 18);
        // Filtre Santé
        const hasSigned = !emptyHealthAttestation(m);
        const matchHealth = activeHealthFilter === ''
            || (activeHealthFilter === 'signed' && hasSigned)
            || (activeHealthFilter === 'pending' && !hasSigned);
        // Filtre Grade / Cours spécifique
        const matchGrade = activeGradeFilter === null
            || (m.practices || []).some((p) => Number(p.grade_id) === activeGradeFilter);
        // Filtre Niveau / Ceinture spécifique
        const matchLevel = activeLevelFilter === null
            || (m.practices || []).some((p) => Number(p.level_id) === activeLevelFilter);
        return matchStatus && matchDisc && matchGrade && matchLevel && matchAge && matchHealth;
    });
    // Tri multi-colonnes interactif (Point 26)
    filtered.sort((a, b) => {
        let valA = '';
        let valB = '';
        if (activeSortColumn === 'name') {
            valA = `${a.last_name} ${a.first_name}`.toLowerCase();
            valB = `${b.last_name} ${b.first_name}`.toLowerCase();
        }
        else if (activeSortColumn === 'birth_date') {
            valA = a.birth_date;
            valB = b.birth_date;
        }
        else if (activeSortColumn === 'discipline') {
            valA = a.practices?.[0]?.discipline_name || '';
            valB = b.practices?.[0]?.discipline_name || '';
        }
        else if (activeSortColumn === 'status') {
            valA = a.status;
            valB = b.status;
        }
        if (valA < valB)
            return activeSortDirection === 'asc' ? -1 : 1;
        if (valA > valB)
            return activeSortDirection === 'asc' ? 1 : -1;
        return 0;
    });
    tbody.innerHTML = '';
    filtered.forEach((member) => {
        const memberTr = document.createElement('tr');
        memberTr.className = 'member-row border-b border-slate-100 hover:bg-slate-50/90 cursor-pointer transition';
        const initials = `${(member.first_name[0] || 'A').toUpperCase()}${(member.last_name[0] || 'D').toUpperCase()}`;
        const age = computeAge(member.birth_date);
        const ageBadge = age < 18
            ? `<span class="px-2 py-0.5 rounded bg-amber-50 text-amber-800 text-[11px] font-bold">Mineur (${age} ans)</span>`
            : `<span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 text-[11px] font-semibold">Majeur (${age} ans)</span>`;
        let statusBadge = '<span class="px-2.5 py-0.5 text-[11px] rounded-full bg-amber-50 text-amber-700 font-semibold">En attente</span>';
        if (member.status === 'active') {
            statusBadge = '<span class="px-2.5 py-0.5 text-[11px] rounded-full bg-emerald-50 text-emerald-700 font-semibold">Actif</span>';
        }
        else if (member.status === 'radiated') {
            statusBadge = '<span class="px-2.5 py-0.5 text-[11px] rounded-full bg-red-50 text-red-600 font-semibold">Radié</span>';
        }
        const isHealthOk = !emptyHealthAttestation(member);
        const healthBadge = isHealthOk
            ? `<span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-emerald-50 text-emerald-700 border border-emerald-200 block mt-1">✓ Cerfa validé</span>`
            : `<span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-50 text-amber-700 border border-amber-200 block mt-1">Certif. requis</span>`;
        // Affichage des Disciplines, Cours (Grade d'âge) et Niveaux (Ceinture)
        const practices = member.practices || [];
        const practicesHtml = practices.length > 0
            ? practices.map((p) => `
                <div class="inline-flex flex-wrap items-center gap-1 px-2 py-0.5 rounded-md bg-slate-50 border border-slate-200 text-[11px] mr-1 mb-1">
                    <strong class="text-slate-900">${p.discipline_name}</strong>
                    <span class="text-slate-400">•</span>
                    <span class="font-semibold text-blue-700">${p.grade_name || 'Tous âges'}</span>
                    <span class="text-slate-400">•</span>
                    <span class="inline-flex items-center gap-1 text-emerald-800 font-medium">
                        <span class="w-2 h-2 rounded-full border border-slate-300 inline-block" style="background-color: ${p.color_hex || '#10b981'};"></span>
                        ${p.niveau_name || 'Débutant'}
                    </span>
                </div>
            `).join('')
            : `<span class="text-[11px] text-slate-400 italic">Aucun cours affecté</span>`;
        const tutors = member.tutors || [];
        const tutorSummary = isTeacher
            ? `<span class="text-[10px] text-slate-400 block mt-0.5">🔒 RGPD</span>`
            : (tutors.length > 0
                ? `<span class="text-[10px] font-semibold text-emerald-700 underline block mt-0.5">${tutors.length} Resp. ▾</span>`
                : `<span class="text-[10px] text-slate-400 block mt-0.5">Autonome</span>`);
        const buttons = [];
        if (canResendOtp) {
            buttons.push(`<button type="button" class="btn-resend-otp w-8 h-7 flex items-center justify-center rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold transition" title="Renvoyer le lien d'activation par e-mail (48h)" aria-label="Renvoyer le lien d'activation">✉️<span class="sr-only">Renvoyer</span></button>`);
        }
        buttons.push(`<button type="button" class="btn-medical-member w-8 h-7 flex items-center justify-center rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Certificat médical & Santé" aria-label="Certificat médical & Santé">📄<span class="sr-only">Santé</span></button>`);
        if (canEdit) {
            buttons.push(`<button type="button" class="btn-edit-member w-8 h-7 flex items-center justify-center rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition" title="Modifier la fiche adhérent & les cours" aria-label="Modifier la fiche adhérent">✏️<span class="sr-only">Modifier</span></button>`);
        }
        if (canDeleteOrRadiate) {
            buttons.push(`<button type="button" class="btn-radiate-member w-8 h-7 flex items-center justify-center rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold transition" title="Radier cet adhérent (Archivage réversible)" aria-label="Radier cet adhérent">🚫<span class="sr-only">Radier</span></button>`);
            buttons.push(`<button type="button" class="btn-delete-member w-8 h-7 flex items-center justify-center rounded-lg bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold transition" title="Supprimer définitivement la fiche" aria-label="Supprimer définitivement">🗑️<span class="sr-only">Supprimer</span></button>`);
        }
        const addressLine = (!isTeacher && member.address)
            ? `<div class="text-[10px] text-slate-400 truncate max-w-[135px]" title="${member.address}">📍 ${member.address}</div>`
            : '';
        const isChecked = selectedMemberIds.has(member.id);
        memberTr.innerHTML = `
            <td class="p-2.5 text-center ${isAdherent ? 'hidden' : ''}">
                <input type="checkbox" class="member-select-cb member-row-checkbox rounded text-emerald-600 focus:ring-emerald-500" data-id="${member.id}" ${isChecked ? 'checked' : ''}>
            </td>
            <td class="p-2.5">
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-slate-900 text-white font-bold text-[11px] flex items-center justify-center shrink-0">
                        ${initials}
                    </div>
                    <div class="min-w-0">
                        <div class="font-bold text-slate-900 leading-tight">${member.first_name} ${member.last_name}</div>
                        ${addressLine}
                    </div>
                </div>
            </td>
            <td class="p-2.5 whitespace-nowrap">
                <div class="text-[11px] font-medium text-slate-700">${member.birth_date}</div>
                <div class="mt-0.5">${ageBadge}</div>
            </td>
            <td class="p-2.5 max-w-[230px]">${practicesHtml}</td>
            <td class="p-2.5 text-[11px]">
                <strong class="text-slate-800 block truncate max-w-[125px]" title="${member.emergency_contact_name || 'Parent référent'}">${member.emergency_contact_name || 'Parent référent'}</strong>
                <span class="text-slate-500 font-mono whitespace-nowrap">${member.emergency_contact_phone || '06 11 22 33 44'}</span>
            </td>
            <td class="p-2.5">
                ${statusBadge}
                ${healthBadge}
                ${tutorSummary}
            </td>
            <td class="p-2.5 text-right action-buttons">
                <div class="grid grid-cols-3 gap-1 w-[104px] ml-auto" data-testid="member-actions-grid">${buttons.join('')}</div>
            </td>
        `;
        // Sous-ligne Accordéon : Détails & Partage RGPD entre co-tuteurs
        const accordionTr = document.createElement('tr');
        accordionTr.className = 'tutor-accordion hidden bg-slate-50/90 border-b border-slate-200';
        const accordionTd = document.createElement('td');
        accordionTd.colSpan = 7;
        accordionTd.className = 'p-4 pl-10';
        const box = document.createElement('div');
        box.className = 'p-5 bg-white rounded-2xl border border-slate-200 space-y-4 shadow-2xs';
        box.innerHTML = `
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 pb-3 border-b border-slate-100">
                <div>
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-600 block">Dossier Détaillé de l'Adhérent</span>
                    <strong class="text-sm text-slate-900">${member.first_name} ${member.last_name} (Né(e) le ${member.birth_date})</strong>
                </div>
                <div class="text-xs text-slate-600">
                    📍 Adresse : <strong>${isTeacher ? 'Masquée (RGPD)' : (member.address || 'Non renseignée')}</strong> •
                    🚨 Urgence : <strong>${member.emergency_contact_name || 'Renseigné'} (${member.emergency_contact_phone || '06 11 22 33 44'})</strong>
                </div>
            </div>
            <div class="text-xs font-bold uppercase tracking-wider text-slate-500">
                Responsables Légaux (Co-Tuteurs) & Consentement de Partage RGPD
            </div>
        `;
        if (isTeacher) {
            box.innerHTML += `<div class="text-xs text-slate-500 italic">Conformément au RGPD, les coordonnées privées des parents ne sont pas accessibles au profil Enseignant.</div>`;
        }
        else if (tutors.length > 0) {
            tutors.forEach((t) => {
                const row = document.createElement('div');
                row.className = 'p-3.5 rounded-xl bg-slate-50 border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs';
                const fullName = `${t.first_name || ''} ${t.last_name || ''}`.trim() || `Tuteur #${t.id}`;
                row.innerHTML = `
                    <div class="space-y-0.5">
                        <div class="font-bold text-slate-900">
                            ${fullName} <span class="text-slate-500 font-normal">(${t.relationship || 'Responsable légal'} — Tuteur #${t.id})</span>
                        </div>
                        <div class="text-slate-600">
                            ✉️ Email : <strong>${t.email}</strong> • 📞 Tél : <strong>${t.phone || 'Données masquées (RGPD)'}</strong>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button type="button" class="px-3.5 py-2 rounded-xl font-bold transition ${t.info_shared ? 'bg-emerald-600 text-white' : 'bg-white border border-slate-300 text-slate-700 hover:bg-slate-100'}">
                            ${t.info_shared
                    ? '🔓 Coordonnées partagées avec le co-tuteur (Cliquer pour isoler)'
                    : '🔒 Coordonnées masquées au co-tuteur (Cliquer pour partager)'}
                        </button>
                    </div>
                `;
                row.querySelector('button')?.addEventListener('click', async (ev) => {
                    ev.stopPropagation();
                    const res = await fetchApi(`/api/v1/members/${member.id}/share-tutor-info`, {
                        method: 'PATCH',
                        body: JSON.stringify({ tutor_id: t.id, info_shared: !t.info_shared })
                    });
                    if (res.status === 'success') {
                        showToast('Paramètre de partage RGPD mis à jour.', 'success');
                        await loadMembersTable();
                    }
                });
                box.appendChild(row);
            });
        }
        else {
            box.innerHTML += `<div class="text-xs text-slate-500">Adhérent majeur autonome (aucun tuteur légal rattaché).</div>`;
        }
        accordionTd.appendChild(box);
        accordionTr.appendChild(accordionTd);
        memberTr.addEventListener('click', (e) => {
            if (e.target.closest('.action-buttons'))
                return;
            accordionTr.classList.toggle('hidden');
        });
        memberTr.querySelector('.btn-resend-otp')?.addEventListener('click', async (e) => {
            e.stopPropagation();
            const btn = e.currentTarget;
            setButtonLoading(btn, true, '✉️');
            try {
                const res = await fetchApi(`/api/v1/members/${member.id}/resend-otp`, {
                    method: 'POST'
                });
                if (res.status === 'success' && res.data) {
                    showToast(`Nouveau lien d'activation envoyé à ${res.data.recipient_email} !`, 'success');
                }
            }
            finally {
                setButtonLoading(btn, false, '✉️');
            }
        });
        memberTr.querySelector('.btn-medical-member')?.addEventListener('click', (e) => {
            e.stopPropagation();
            openMedicalModal(member);
        });
        memberTr.querySelector('.btn-edit-member')?.addEventListener('click', (e) => {
            e.stopPropagation();
            openEditMemberModal(member);
        });
        memberTr.querySelector('.btn-radiate-member')?.addEventListener('click', (e) => {
            e.stopPropagation();
            openConfirmModal('Radier cet adhérent', `Confirmez-vous la radiation de ${member.first_name} ${member.last_name} ?`, async () => {
                const res = await fetchApi(`/api/v1/members/${member.id}/radiate`, { method: 'POST' });
                if (res.status === 'success') {
                    showToast('Adhérent placé en statut Radié.', 'info');
                    await loadMembersTable();
                }
            });
        });
        memberTr.querySelector('.btn-delete-member')?.addEventListener('click', (e) => {
            e.stopPropagation();
            openConfirmModal('Supprimer définitivement la fiche', `Attention : voulez-vous supprimer définitivement ${member.first_name} ${member.last_name} ?`, async () => {
                const res = await fetchApi(`/api/v1/members/${member.id}`, { method: 'DELETE' });
                if (res.status === 'success') {
                    showToast('Fiche adhérent supprimée définitivement.', 'success');
                    await loadMembersTable();
                }
            });
        });
        memberTr.querySelector('.member-select-cb')?.addEventListener('click', (e) => {
            e.stopPropagation();
            const cb = e.currentTarget;
            if (cb.checked) {
                selectedMemberIds.add(member.id);
            }
            else {
                selectedMemberIds.delete(member.id);
            }
            updateBulkUi();
        });
        tbody.appendChild(memberTr);
        tbody.appendChild(accordionTr);
    });
    updateBulkUi();
}
function emptyHealthAttestation(m) {
    return !m.health_attestation_signed || Number(m.health_attestation_signed) === 0;
}
function updateBulkUi() {
    const btn = document.getElementById('btn-bulk-resend-otp');
    const countSpan = document.getElementById('bulk-selected-count');
    const count = selectedMemberIds.size;
    if (countSpan)
        countSpan.textContent = String(count);
    if (btn) {
        if (count > 0) {
            btn.classList.remove('hidden');
        }
        else {
            btn.classList.add('hidden');
        }
    }
}
function initAdvancedFilters() {
    const searchInput = document.getElementById('member-search');
    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase().trim();
        document.querySelectorAll('#members-table tbody tr.member-row').forEach((row) => {
            const text = row.textContent?.toLowerCase() || '';
            const match = text.includes(query);
            row.style.display = match ? '' : 'none';
            const accordion = row.nextElementSibling;
            if (accordion?.classList.contains('tutor-accordion') && !match) {
                accordion.style.display = 'none';
            }
        });
    });
    const ageSelect = document.getElementById('filter-member-age');
    ageSelect?.addEventListener('change', () => {
        activeAgeFilter = ageSelect.value;
        renderMembersRows();
    });
    const healthSelect = document.getElementById('filter-member-health');
    healthSelect?.addEventListener('change', () => {
        activeHealthFilter = healthSelect.value;
        renderMembersRows();
    });
}
function renderDisciplineTabs() {
    const container = document.getElementById('discipline-tabs-container');
    if (!container)
        return;
    container.innerHTML = '';
    // Onglet "Toutes les disciplines"
    const totalCount = allMembers.length;
    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = `px-3.5 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 ${activeDisciplineFilter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`;
    allBtn.innerHTML = `🥋 Toutes <span class="discipline-count-badge px-2 py-0.5 rounded-full text-[10px] ${activeDisciplineFilter === 'all' ? 'bg-slate-700 text-white' : 'bg-white text-slate-700'}">${totalCount}</span>`;
    allBtn.addEventListener('click', () => {
        activeDisciplineFilter = 'all';
        const sel = document.getElementById('member-discipline-filter');
        if (sel)
            sel.value = 'all';
        renderDisciplineTabs();
        renderMembersRows();
    });
    container.appendChild(allBtn);
    disciplinesCatalog.forEach((d) => {
        const discCount = allMembers.filter((m) => (m.practices || []).some((p) => Number(p.discipline_id) === Number(d.id))).length;
        const btn = document.createElement('button');
        btn.type = 'button';
        const isSel = String(d.id) === activeDisciplineFilter;
        btn.className = `px-3.5 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 ${isSel ? 'bg-emerald-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`;
        btn.innerHTML = `🥋 ${d.name} <span class="discipline-count-badge px-2 py-0.5 rounded-full text-[10px] ${isSel ? 'bg-emerald-800 text-white' : 'bg-white text-slate-700 font-mono'}">${discCount}</span>`;
        btn.addEventListener('click', () => {
            activeDisciplineFilter = String(d.id);
            const sel = document.getElementById('member-discipline-filter');
            if (sel)
                sel.value = String(d.id);
            renderDisciplineTabs();
            renderMembersRows();
        });
        container.appendChild(btn);
    });
}
function initTableSorting() {
    document.querySelectorAll('#members-table th[data-sort]').forEach((th) => {
        th.addEventListener('click', () => {
            const col = th.getAttribute('data-sort') || 'name';
            if (activeSortColumn === col) {
                activeSortDirection = activeSortDirection === 'asc' ? 'desc' : 'asc';
            }
            else {
                activeSortColumn = col;
                activeSortDirection = 'asc';
            }
            // Met à jour les icônes de tri
            document.querySelectorAll('#members-table th[data-sort] .sort-icon').forEach((icon) => {
                icon.textContent = '↕';
                icon.className = 'sort-icon text-[10px] text-slate-400';
            });
            const activeIcon = th.querySelector('.sort-icon');
            if (activeIcon) {
                activeIcon.textContent = activeSortDirection === 'asc' ? '▲' : '▼';
                activeIcon.className = 'sort-icon text-[10px] text-emerald-600 font-bold';
            }
            renderMembersRows();
        });
    });
}
function initBulkActions() {
    const selectAllCb = document.getElementById('select-all-members');
    selectAllCb?.addEventListener('change', () => {
        const checked = selectAllCb.checked;
        document.querySelectorAll('.member-select-cb').forEach((cb) => {
            cb.checked = checked;
            const id = parseInt(cb.getAttribute('data-id') || '0', 10);
            if (checked && id > 0) {
                selectedMemberIds.add(id);
            }
            else {
                selectedMemberIds.delete(id);
            }
        });
        updateBulkUi();
    });
    document.getElementById('btn-bulk-resend-otp')?.addEventListener('click', async () => {
        if (selectedMemberIds.size === 0)
            return;
        const btn = document.getElementById('btn-bulk-resend-otp');
        setButtonLoading(btn, true, 'Envoi en cours...');
        try {
            const res = await fetchApi('/api/v1/members/bulk-resend-otp', {
                method: 'POST',
                body: JSON.stringify({ member_ids: Array.from(selectedMemberIds) })
            });
            if (res.status === 'success' && res.data) {
                showToast(`${res.data.sent_count} lien(s) d'activation renvoyé(s) avec succès !`, 'success');
                selectedMemberIds.clear();
                updateBulkUi();
            }
        }
        finally {
            setButtonLoading(btn, false, '📧 Renvoyer l\'invitation');
        }
    });
}
function initExportCsv() {
    document.getElementById('btn-export-csv')?.addEventListener('click', async () => {
        const params = new URLSearchParams({
            status: activeStatusFilter,
            age_group: activeAgeFilter,
            health_status: activeHealthFilter
        });
        const res = await fetchApi(`/api/v1/members/export?${params.toString()}`);
        if (res.status === 'success' && res.data) {
            const blob = new Blob([res.data.csv], { type: 'text/csv;charset=utf-8;' });
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `kanso_adherents_${new Date().toISOString().slice(0, 10)}.csv`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            showToast(`Export de ${res.data.count} adhérent(s) téléchargé avec succès !`, 'success');
        }
    });
}
function initStatusTabs() {
    const container = document.getElementById('member-status-filters');
    if (!container)
        return;
    const buttons = container.querySelectorAll('button[data-status]');
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            buttons.forEach((b) => {
                b.className = 'px-3 py-1.5 rounded-lg text-slate-600 hover:text-slate-900 transition';
            });
            btn.className = 'px-3 py-1.5 rounded-lg bg-white text-slate-900 shadow-2xs transition';
            activeStatusFilter = btn.getAttribute('data-status') || 'all';
            renderMembersRows();
        });
    });
}
function initDisciplineFilter() {
    const select = document.getElementById('member-discipline-filter');
    select?.addEventListener('change', () => {
        activeDisciplineFilter = select.value;
        renderMembersRows();
    });
}
/**
 * Ajoute une ligne d'affectation (Discipline + Grade d'âge + Niveau technique) dans la modale d'édition d'un adhérent.
 */
function appendEditPracticeRow(discId = 1, gradeId = null, levelId = null) {
    const container = document.getElementById('edit-practices-container');
    if (!container)
        return;
    const birthVal = document.getElementById('edit-birth-date')?.value || '2012-01-01';
    const memberAge = computeAge(birthVal);
    const row = document.createElement('div');
    row.className = 'edit-practice-row grid grid-cols-1 sm:grid-cols-12 gap-2 items-center p-2.5 rounded-xl bg-slate-50 border border-slate-200';
    const discOpts = disciplinesCatalog.map((d) => `<option value="${d.id}" ${Number(d.id) === Number(discId) ? 'selected' : ''}>${d.name}</option>`).join('');
    row.innerHTML = `
        <div class="sm:col-span-4">
            <label class="block text-[10px] font-bold uppercase text-slate-500">Discipline</label>
            <select class="edit-prac-disc w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-bold">${discOpts}</select>
        </div>
        <div class="sm:col-span-4">
            <label class="block text-[10px] font-bold uppercase text-blue-700">Grade / Cours d'âge</label>
            <select class="edit-prac-grade w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-semibold"></select>
        </div>
        <div class="sm:col-span-3">
            <label class="block text-[10px] font-bold uppercase text-emerald-700">Niveau (Ceinture)</label>
            <select class="edit-prac-level w-full bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-semibold"></select>
        </div>
        <div class="sm:col-span-1 flex justify-end pt-3">
            <button type="button" class="btn-del-edit-prac text-red-600 hover:bg-red-50 px-2 py-1 rounded font-bold text-xs" title="Retirer ce cours">✕</button>
        </div>
    `;
    const dSel = row.querySelector('.edit-prac-disc');
    const gSel = row.querySelector('.edit-prac-grade');
    const lSel = row.querySelector('.edit-prac-level');
    const fillSubSelects = (targetDiscId, targetGradeId, targetLevelId) => {
        const disc = disciplinesCatalog.find((d) => Number(d.id) === Number(targetDiscId));
        const all = disc?.levels || [];
        const grades = all.filter((l) => (l.level_type || 'grade') === 'grade');
        const niveaux = all.filter((l) => l.level_type === 'niveau');
        let resolvedGradeId = targetGradeId;
        if (!resolvedGradeId && grades.length > 0) {
            const match = grades.find((g) => (g.age_min === null || memberAge >= g.age_min) && (g.age_max === null || memberAge <= g.age_max));
            resolvedGradeId = match ? match.id : grades[0].id;
        }
        gSel.innerHTML = grades.map((g) => `<option value="${g.id}" ${Number(g.id) === Number(resolvedGradeId) ? 'selected' : ''}>${g.name}</option>`).join('');
        lSel.innerHTML = niveaux.map((n) => `<option value="${n.id}" ${Number(n.id) === Number(targetLevelId || niveaux[0]?.id) ? 'selected' : ''}>${n.name}</option>`).join('');
    };
    fillSubSelects(discId, gradeId, levelId);
    dSel.addEventListener('change', () => {
        fillSubSelects(parseInt(dSel.value, 10), null, null);
    });
    row.querySelector('.btn-del-edit-prac')?.addEventListener('click', () => row.remove());
    container.appendChild(row);
}
function openEditMemberModal(member) {
    const modal = document.getElementById('modal-edit-member');
    const pracContainer = document.getElementById('edit-practices-container');
    if (!modal || !pracContainer)
        return;
    document.getElementById('edit-member-id').value = String(member.id);
    document.getElementById('edit-first-name').value = member.first_name;
    document.getElementById('edit-last-name').value = member.last_name;
    document.getElementById('edit-birth-date').value = member.birth_date;
    const editGenderEl = document.getElementById('edit-member-gender');
    if (editGenderEl)
        editGenderEl.value = member.gender || '';
    document.getElementById('edit-phone').value = member.phone || '';
    document.getElementById('edit-address').value = member.address || '';
    document.getElementById('edit-emerg-name').value = member.emergency_contact_name || '';
    document.getElementById('edit-emerg-phone').value = member.emergency_contact_phone || '';
    document.getElementById('edit-status').value = member.status;
    pracContainer.innerHTML = '';
    const practices = member.practices || [];
    if (practices.length === 0) {
        appendEditPracticeRow(1, null, null);
    }
    else {
        practices.forEach((p) => {
            appendEditPracticeRow(p.discipline_id, p.grade_id, p.level_id);
        });
    }
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function initEditMemberModal() {
    const modal = document.getElementById('modal-edit-member');
    const closeBtn = document.getElementById('btn-close-edit-modal');
    const addPracBtn = document.getElementById('btn-edit-add-practice');
    const form = document.getElementById('form-edit-member');
    const submitBtn = document.getElementById('btn-submit-edit-member');
    if (!modal || !form)
        return;
    addPracBtn?.addEventListener('click', () => {
        appendEditPracticeRow(2, null, null);
    });
    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const firstName = document.getElementById('edit-first-name').value.trim();
        const lastName = document.getElementById('edit-last-name').value.trim();
        const birthDate = document.getElementById('edit-birth-date').value.trim();
        if (!firstName || !lastName || !birthDate) {
            showToast('Veuillez renseigner le prénom, le nom et la date de naissance de l\'adhérent.', 'error');
            return;
        }
        setButtonLoading(submitBtn, true, 'Enregistrer les modifications');
        const id = document.getElementById('edit-member-id').value;
        const practices = [];
        document.querySelectorAll('.edit-practice-row').forEach((row) => {
            const did = parseInt(row.querySelector('.edit-prac-disc')?.value || '1', 10);
            const gidVal = row.querySelector('.edit-prac-grade')?.value || '';
            const lidVal = row.querySelector('.edit-prac-level')?.value || '';
            practices.push({
                discipline_id: did,
                grade_id: gidVal ? parseInt(gidVal, 10) : null,
                level_id: lidVal ? parseInt(lidVal, 10) : null
            });
        });
        const payload = {
            first_name: firstName,
            last_name: lastName,
            birth_date: birthDate,
            gender: document.getElementById('edit-member-gender')?.value || '',
            phone: document.getElementById('edit-phone').value.trim(),
            address: document.getElementById('edit-address').value.trim(),
            emergency_contact_name: document.getElementById('edit-emerg-name').value.trim(),
            emergency_contact_phone: document.getElementById('edit-emerg-phone').value.trim(),
            status: document.getElementById('edit-status').value,
            practices
        };
        try {
            const res = await fetchApi(`/api/v1/members/${id}`, {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Fiche adhérent et cours/grades mis à jour !', 'success');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                await loadMembersTable();
            }
        }
        finally {
            setButtonLoading(submitBtn, false, 'Enregistrer les modifications');
        }
    });
}
function openMedicalModal(member) {
    const modal = document.getElementById('modal-medical-upload');
    const label = document.getElementById('medical-member-label');
    const idInput = document.getElementById('medical-member-id');
    if (!modal || !label || !idInput)
        return;
    idInput.value = String(member.id);
    label.textContent = `Adhérent concerné : ${member.first_name} ${member.last_name} (#${member.id})`;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function initMedicalUploadModal() {
    const modal = document.getElementById('modal-medical-upload');
    const closeBtn = document.getElementById('btn-close-medical-modal');
    const form = document.getElementById('form-medical-upload');
    const submitBtn = document.getElementById('btn-submit-medical');
    if (!modal || !form)
        return;
    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const id = document.getElementById('medical-member-id').value;
        const fileInput = document.getElementById('medical-file-input');
        const file = fileInput.files?.[0];
        if (!file) {
            showToast('Veuillez sélectionner un fichier PDF ou image pour le certificat médical.', 'error');
            return;
        }
        setButtonLoading(submitBtn, true, 'Téléverser');
        try {
            const formData = new FormData();
            formData.append('file', file);
            const res = await fetchApi(`/api/v1/members/${id}/medical-file`, {
                method: 'POST',
                body: formData
            });
            if (res.status === 'success') {
                showToast('Certificat médical enregistré en zone privée !', 'success');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                form.reset();
            }
        }
        finally {
            setButtonLoading(submitBtn, false, 'Téléverser');
        }
    });
}
