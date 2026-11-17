/**
 * @file members.ts
 * @description Annuaire des Adhérents & Rattachements avec gestion Multi-Disciplines,
 * Grades (Catégories d'âge / Cours) et Niveaux techniques (Ceintures / Gants) :
 * - Affichage direct des badges de Cours (Grade) et Niveau (Ceinture) sur chaque ligne adhérent
 * - Filtre par Discipline, par Statut et Recherche instantanée 0ms
 * - Modale d'édition permettant d'ajouter/supprimer/modifier les disciplines, grades et niveaux d'un adhérent.
 */

import { fetchApi } from './api.js';
import { AuthContext, currentAuthContext, openConfirmModal, setButtonLoading, showToast } from './main.js';

interface TutorInfo {
    id: number;
    first_name?: string;
    last_name?: string;
    relationship?: string;
    email: string;
    phone?: string;
    address?: string;
    info_shared: boolean;
}

interface MemberPractice {
    practice_id?: number;
    discipline_id: number;
    grade_id: number | null;
    level_id: number | null;
    discipline_name: string;
    grade_name?: string;
    niveau_name?: string;
    color_hex?: string;
}

interface MemberRow {
    id: number;
    first_name: string;
    last_name: string;
    birth_date: string;
    email?: string;
    phone?: string;
    address?: string;
    emergency_contact_name?: string;
    emergency_contact_phone?: string;
    status: string;
    practices?: MemberPractice[];
    tutors?: TutorInfo[];
}

interface DisciplineRef {
    id: number;
    name: string;
    levels: Array<{
        id: number;
        level_type: 'grade' | 'niveau';
        name: string;
        color_hex?: string;
        age_min: number | null;
        age_max: number | null;
    }>;
}

let allMembers: MemberRow[] = [];
let disciplinesCatalog: DisciplineRef[] = [];
let activeStatusFilter = 'all';
let activeDisciplineFilter = 'all';
let activeGradeFilter: number | null = null;
let activeLevelFilter: number | null = null;
let activeAgeFilter = '';
let activeHealthFilter = '';
let activeSearchQuery = '';
let activeSortColumn = 'name';
let activeSortDirection: 'asc' | 'desc' = 'asc';
const selectedMemberIds = new Set<number>();

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
        const sel = document.getElementById('member-discipline-filter') as HTMLSelectElement | null;
        if (sel) sel.value = discParam;
    }

    window.addEventListener('kanso:members-updated', () => loadMembersTable());
    window.addEventListener('kanso:auth-ready', (e: Event) => {
        const ctx = (e as CustomEvent<AuthContext>).detail;
        if (ctx.role === 'Adhérent') {
            document.getElementById('edit-status-wrapper')?.classList.add('hidden');
            document.getElementById('edit-practices-section')?.classList.add('hidden');
        }
        renderMembersRows();
    });
});

function computeAge(birthDateStr: string): number {
    if (!birthDateStr) return 25;
    const dob = new Date(birthDateStr);
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const m = today.getMonth() - dob.getMonth();
    if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) {
        age--;
    }
    return age;
}

async function loadDisciplinesCatalog(): Promise<void> {
    const res = await fetchApi<DisciplineRef[]>('/api/v1/disciplines');
    if (res.status === 'success' && Array.isArray(res.data)) {
        disciplinesCatalog = res.data;
        const filterSelect = document.getElementById('member-discipline-filter') as HTMLSelectElement | null;
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

async function loadMembersTable(): Promise<void> {
    const response = await fetchApi<MemberRow[]>('/api/v1/members');
    if (response.status !== 'success' || !Array.isArray(response.data)) return;

    allMembers = response.data;
    updateKpiCounters(allMembers);
    renderDisciplineTabs();
    renderMembersRows();
}

function updateKpiCounters(members: MemberRow[]): void {
    const setTxt = (id: string, v: number): void => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(v);
    };
    setTxt('kpi-total', members.length);
    setTxt('kpi-active', members.filter((m) => m.status === 'active').length);
    setTxt('kpi-pending', members.filter((m) => m.status === 'pending').length);
    setTxt('kpi-radiated', members.filter((m) => m.status === 'radiated').length);
}

function renderMembersRows(): void {
    const tbody = document.querySelector('#members-table tbody') as HTMLTableSectionElement | null;
    if (!tbody) return;

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
        let valA: any = '';
        let valB: any = '';
        if (activeSortColumn === 'name') {
            valA = `${a.last_name} ${a.first_name}`.toLowerCase();
            valB = `${b.last_name} ${b.first_name}`.toLowerCase();
        } else if (activeSortColumn === 'birth_date') {
            valA = a.birth_date;
            valB = b.birth_date;
        } else if (activeSortColumn === 'discipline') {
            valA = a.practices?.[0]?.discipline_name || '';
            valB = b.practices?.[0]?.discipline_name || '';
        } else if (activeSortColumn === 'status') {
            valA = a.status;
            valB = b.status;
        }
        if (valA < valB) return activeSortDirection === 'asc' ? -1 : 1;
        if (valA > valB) return activeSortDirection === 'asc' ? 1 : -1;
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
        } else if (member.status === 'radiated') {
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
                <div class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg bg-slate-50 border border-slate-200 text-[11px] mr-1 mb-1">
                    <strong class="text-slate-900">${p.discipline_name}</strong>
                    <span class="text-slate-400">•</span>
                    <span class="font-semibold text-blue-700">${p.grade_name || 'Tous âges'}</span>
                    <span class="text-slate-400">•</span>
                    <span class="inline-flex items-center gap-1 text-emerald-800 font-medium">
                        <span class="w-2.5 h-2.5 rounded-full border border-slate-300 inline-block" style="background-color: ${p.color_hex || '#10b981'};"></span>
                        ${p.niveau_name || 'Débutant'}
                    </span>
                </div>
            `).join('')
            : `<span class="text-xs text-slate-400 italic">Aucun cours affecté</span>`;

        const tutors = member.tutors || [];
        const tutorSummary = isTeacher
            ? `<span class="text-[11px] text-slate-400 block mt-1">🔒 Parents masqués (RGPD)</span>`
            : (tutors.length > 0
                ? `<span class="text-[11px] font-semibold text-emerald-700 underline block mt-1">${tutors.length} Responsable(s) ▾</span>`
                : `<span class="text-[11px] text-slate-400 block mt-1">Majeur autonome</span>`);

        const buttons: string[] = [];
        if (canResendOtp) {
            buttons.push(`<button type="button" class="btn-resend-otp px-2 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold transition" title="Renvoyer le lien d'activation OTP (48h)">✉️</button>`);
        }
        buttons.push(`<button type="button" class="btn-medical-member px-2 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold transition" title="Certificat médical & Santé">📄</button>`);
        if (canEdit) {
            buttons.push(`<button type="button" class="btn-edit-member px-2.5 py-1.5 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold transition flex items-center gap-1" title="Modifier la fiche adhérent & les cours">✏️ <span>Modifier</span></button>`);
        }
        if (canDeleteOrRadiate) {
            buttons.push(`<button type="button" class="btn-radiate-member px-2 py-1.5 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 text-xs font-semibold transition" title="Radier cet adhérent (Archivage réversible)">🚫</button>`);
            buttons.push(`<button type="button" class="btn-delete-member px-2 py-1.5 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold transition" title="Supprimer définitivement la fiche">🗑️</button>`);
        }

        const addressLine = (!isTeacher && member.address)
            ? `<div class="text-[11px] text-slate-400 truncate max-w-[180px]">📍 ${member.address}</div>`
            : '';

        const isChecked = selectedMemberIds.has(member.id);

        memberTr.innerHTML = `
            <td class="p-3.5 text-center ${isAdherent ? 'hidden' : ''}">
                <input type="checkbox" class="member-select-cb member-row-checkbox rounded text-emerald-600 focus:ring-emerald-500" data-id="${member.id}" ${isChecked ? 'checked' : ''}>
            </td>
            <td class="p-3.5">
                <div class="flex items-center gap-3">
                    <div class="w-9 h-9 rounded-xl bg-slate-900 text-white font-bold text-xs flex items-center justify-center shrink-0">
                        ${initials}
                    </div>
                    <div>
                        <div class="font-bold text-slate-900">${member.first_name} ${member.last_name}</div>
                        ${addressLine}
                    </div>
                </div>
            </td>
            <td class="p-3.5">
                <div class="text-xs font-medium text-slate-700">${member.birth_date}</div>
                <div class="mt-0.5">${ageBadge}</div>
            </td>
            <td class="p-3.5 max-w-md">${practicesHtml}</td>
            <td class="p-3.5 text-xs">
                <strong class="text-slate-800 block">${member.emergency_contact_name || 'Parent référent'}</strong>
                <span class="text-slate-500 font-mono">${member.emergency_contact_phone || '06 11 22 33 44'}</span>
            </td>
            <td class="p-3.5">
                ${statusBadge}
                ${healthBadge}
                ${tutorSummary}
            </td>
            <td class="p-3.5 text-right action-buttons whitespace-nowrap">
                <div class="inline-flex items-center gap-1 justify-end">${buttons.join('')}</div>
            </td>
        `;

        // Sous-ligne Accordéon : Détails & Partage RGPD entre co-tuteurs
        const accordionTr = document.createElement('tr');
        accordionTr.className = 'tutor-accordion hidden bg-slate-50/90 border-b border-slate-200';

        const accordionTd = document.createElement('td');
        accordionTd.colSpan = 6;
        accordionTd.className = 'p-4 pl-14';

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
        } else if (tutors.length > 0) {
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
                row.querySelector('button')?.addEventListener('click', async (ev: Event) => {
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
        } else {
            box.innerHTML += `<div class="text-xs text-slate-500">Adhérent majeur autonome (aucun tuteur légal rattaché).</div>`;
        }

        accordionTd.appendChild(box);
        accordionTr.appendChild(accordionTd);

        memberTr.addEventListener('click', (e: MouseEvent) => {
            if ((e.target as HTMLElement).closest('.action-buttons')) return;
            accordionTr.classList.toggle('hidden');
        });

        memberTr.querySelector('.btn-resend-otp')?.addEventListener('click', async (e: Event) => {
            e.stopPropagation();
            const btn = e.currentTarget as HTMLButtonElement;
            setButtonLoading(btn, true, '✉️ OTP');
            try {
                const res = await fetchApi<{ recipient_email: string }>(`/api/v1/members/${member.id}/resend-otp`, {
                    method: 'POST'
                });
                if (res.status === 'success' && res.data) {
                    showToast(`Nouveau lien OTP envoyé à ${res.data.recipient_email} !`, 'success');
                }
            } finally {
                setButtonLoading(btn, false, '✉️ OTP');
            }
        });

        memberTr.querySelector('.btn-medical-member')?.addEventListener('click', (e: Event) => {
            e.stopPropagation();
            openMedicalModal(member);
        });

        memberTr.querySelector('.btn-edit-member')?.addEventListener('click', (e: Event) => {
            e.stopPropagation();
            openEditMemberModal(member);
        });

        memberTr.querySelector('.btn-radiate-member')?.addEventListener('click', (e: Event) => {
            e.stopPropagation();
            openConfirmModal(
                'Radier cet adhérent',
                `Confirmez-vous la radiation de ${member.first_name} ${member.last_name} ?`,
                async () => {
                    const res = await fetchApi(`/api/v1/members/${member.id}/radiate`, { method: 'POST' });
                    if (res.status === 'success') {
                        showToast('Adhérent placé en statut Radié.', 'info');
                        await loadMembersTable();
                    }
                }
            );
        });

        memberTr.querySelector('.btn-delete-member')?.addEventListener('click', (e: Event) => {
            e.stopPropagation();
            openConfirmModal(
                'Supprimer définitivement la fiche',
                `Attention : voulez-vous supprimer définitivement ${member.first_name} ${member.last_name} ?`,
                async () => {
                    const res = await fetchApi(`/api/v1/members/${member.id}`, { method: 'DELETE' });
                    if (res.status === 'success') {
                        showToast('Fiche adhérent supprimée définitivement.', 'success');
                        await loadMembersTable();
                    }
                }
            );
        });

        memberTr.querySelector('.member-select-cb')?.addEventListener('click', (e: Event) => {
            e.stopPropagation();
            const cb = e.currentTarget as HTMLInputElement;
            if (cb.checked) {
                selectedMemberIds.add(member.id);
            } else {
                selectedMemberIds.delete(member.id);
            }
            updateBulkUi();
        });

        tbody.appendChild(memberTr);
        tbody.appendChild(accordionTr);
    });

    updateBulkUi();
}

function emptyHealthAttestation(m: any): boolean {
    return !m.health_attestation_signed || Number(m.health_attestation_signed) === 0;
}

function updateBulkUi(): void {
    const btn = document.getElementById('btn-bulk-resend-otp');
    const countSpan = document.getElementById('bulk-selected-count');
    const count = selectedMemberIds.size;
    if (countSpan) countSpan.textContent = String(count);
    if (btn) {
        if (count > 0) {
            btn.classList.remove('hidden');
        } else {
            btn.classList.add('hidden');
        }
    }
}

function initAdvancedFilters(): void {
    const searchInput = document.getElementById('member-search') as HTMLInputElement | null;
    searchInput?.addEventListener('input', () => {
        const query = searchInput.value.toLowerCase().trim();
        document.querySelectorAll<HTMLElement>('#members-table tbody tr.member-row').forEach((row) => {
            const text = row.textContent?.toLowerCase() || '';
            const match = text.includes(query);
            row.style.display = match ? '' : 'none';
            const accordion = row.nextElementSibling as HTMLElement | null;
            if (accordion?.classList.contains('tutor-accordion') && !match) {
                accordion.style.display = 'none';
            }
        });
    });

    const ageSelect = document.getElementById('filter-member-age') as HTMLSelectElement | null;
    ageSelect?.addEventListener('change', () => {
        activeAgeFilter = ageSelect.value;
        renderMembersRows();
    });

    const healthSelect = document.getElementById('filter-member-health') as HTMLSelectElement | null;
    healthSelect?.addEventListener('change', () => {
        activeHealthFilter = healthSelect.value;
        renderMembersRows();
    });
}

function renderDisciplineTabs(): void {
    const container = document.getElementById('discipline-tabs-container');
    if (!container) return;

    container.innerHTML = '';

    // Onglet "Toutes les disciplines"
    const totalCount = allMembers.length;
    const allBtn = document.createElement('button');
    allBtn.type = 'button';
    allBtn.className = `px-3.5 py-1.5 rounded-xl font-bold transition flex items-center gap-1.5 ${activeDisciplineFilter === 'all' ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200'}`;
    allBtn.innerHTML = `🥋 Toutes <span class="discipline-count-badge px-2 py-0.5 rounded-full text-[10px] ${activeDisciplineFilter === 'all' ? 'bg-slate-700 text-white' : 'bg-white text-slate-700'}">${totalCount}</span>`;
    allBtn.addEventListener('click', () => {
        activeDisciplineFilter = 'all';
        const sel = document.getElementById('member-discipline-filter') as HTMLSelectElement | null;
        if (sel) sel.value = 'all';
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
            const sel = document.getElementById('member-discipline-filter') as HTMLSelectElement | null;
            if (sel) sel.value = String(d.id);
            renderDisciplineTabs();
            renderMembersRows();
        });
        container.appendChild(btn);
    });
}

function initTableSorting(): void {
    document.querySelectorAll<HTMLElement>('#members-table th[data-sort]').forEach((th) => {
        th.addEventListener('click', () => {
            const col = th.getAttribute('data-sort') || 'name';
            if (activeSortColumn === col) {
                activeSortDirection = activeSortDirection === 'asc' ? 'desc' : 'asc';
            } else {
                activeSortColumn = col;
                activeSortDirection = 'asc';
            }

            // Met à jour les icônes de tri
            document.querySelectorAll<HTMLElement>('#members-table th[data-sort] .sort-icon').forEach((icon) => {
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

function initBulkActions(): void {
    const selectAllCb = document.getElementById('select-all-members') as HTMLInputElement | null;
    selectAllCb?.addEventListener('change', () => {
        const checked = selectAllCb.checked;
        document.querySelectorAll<HTMLInputElement>('.member-select-cb').forEach((cb) => {
            cb.checked = checked;
            const id = parseInt(cb.getAttribute('data-id') || '0', 10);
            if (checked && id > 0) {
                selectedMemberIds.add(id);
            } else {
                selectedMemberIds.delete(id);
            }
        });
        updateBulkUi();
    });

    document.getElementById('btn-bulk-resend-otp')?.addEventListener('click', async () => {
        if (selectedMemberIds.size === 0) return;
        const btn = document.getElementById('btn-bulk-resend-otp') as HTMLButtonElement | null;
        setButtonLoading(btn, true, 'Envoi en cours...');
        try {
            const res = await fetchApi<{ sent_count: number }>('/api/v1/members/bulk-resend-otp', {
                method: 'POST',
                body: JSON.stringify({ member_ids: Array.from(selectedMemberIds) })
            });
            if (res.status === 'success' && res.data) {
                showToast(`${res.data.sent_count} lien(s) OTP renvoyé(s) avec succès !`, 'success');
                selectedMemberIds.clear();
                updateBulkUi();
            }
        } finally {
            setButtonLoading(btn, false, '📧 Relancer OTP');
        }
    });
}

function initExportCsv(): void {
    document.getElementById('btn-export-csv')?.addEventListener('click', async () => {
        const params = new URLSearchParams({
            status: activeStatusFilter,
            age_group: activeAgeFilter,
            health_status: activeHealthFilter
        });
        const res = await fetchApi<{ csv: string; count: number }>(`/api/v1/members/export?${params.toString()}`);
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

function initStatusTabs(): void {
    const container = document.getElementById('member-status-filters');
    if (!container) return;

    const buttons = container.querySelectorAll<HTMLButtonElement>('button[data-status]');
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

function initDisciplineFilter(): void {
    const select = document.getElementById('member-discipline-filter') as HTMLSelectElement | null;
    select?.addEventListener('change', () => {
        activeDisciplineFilter = select.value;
        renderMembersRows();
    });
}

/**
 * Ajoute une ligne d'affectation (Discipline + Grade d'âge + Niveau technique) dans la modale d'édition d'un adhérent.
 */
function appendEditPracticeRow(discId = 1, gradeId: number | null = null, levelId: number | null = null): void {
    const container = document.getElementById('edit-practices-container');
    if (!container) return;

    const birthVal = (document.getElementById('edit-birth-date') as HTMLInputElement | null)?.value || '2012-01-01';
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

    const dSel = row.querySelector('.edit-prac-disc') as HTMLSelectElement;
    const gSel = row.querySelector('.edit-prac-grade') as HTMLSelectElement;
    const lSel = row.querySelector('.edit-prac-level') as HTMLSelectElement;

    const fillSubSelects = (targetDiscId: number, targetGradeId: number | null, targetLevelId: number | null): void => {
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

function openEditMemberModal(member: MemberRow): void {
    const modal = document.getElementById('modal-edit-member');
    const pracContainer = document.getElementById('edit-practices-container');
    if (!modal || !pracContainer) return;

    (document.getElementById('edit-member-id') as HTMLInputElement).value = String(member.id);
    (document.getElementById('edit-first-name') as HTMLInputElement).value = member.first_name;
    (document.getElementById('edit-last-name') as HTMLInputElement).value = member.last_name;
    (document.getElementById('edit-birth-date') as HTMLInputElement).value = member.birth_date;
    (document.getElementById('edit-phone') as HTMLInputElement).value = member.phone || '';
    (document.getElementById('edit-address') as HTMLInputElement).value = member.address || '';
    (document.getElementById('edit-emerg-name') as HTMLInputElement).value = member.emergency_contact_name || '';
    (document.getElementById('edit-emerg-phone') as HTMLInputElement).value = member.emergency_contact_phone || '';
    (document.getElementById('edit-status') as HTMLSelectElement).value = member.status;

    pracContainer.innerHTML = '';
    const practices = member.practices || [];
    if (practices.length === 0) {
        appendEditPracticeRow(1, null, null);
    } else {
        practices.forEach((p) => {
            appendEditPracticeRow(p.discipline_id, p.grade_id, p.level_id);
        });
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function initEditMemberModal(): void {
    const modal = document.getElementById('modal-edit-member');
    const closeBtn = document.getElementById('btn-close-edit-modal');
    const addPracBtn = document.getElementById('btn-edit-add-practice');
    const form = document.getElementById('form-edit-member') as HTMLFormElement | null;
    const submitBtn = document.getElementById('btn-submit-edit-member') as HTMLButtonElement | null;

    if (!modal || !form) return;

    addPracBtn?.addEventListener('click', () => {
        appendEditPracticeRow(2, null, null);
    });

    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        setButtonLoading(submitBtn, true, 'Enregistrer les modifications');

        const id = (document.getElementById('edit-member-id') as HTMLInputElement).value;
        const practices: Array<{ discipline_id: number; grade_id: number | null; level_id: number | null }> = [];

        document.querySelectorAll<HTMLElement>('.edit-practice-row').forEach((row) => {
            const did = parseInt((row.querySelector('.edit-prac-disc') as HTMLSelectElement)?.value || '1', 10);
            const gidVal = (row.querySelector('.edit-prac-grade') as HTMLSelectElement)?.value || '';
            const lidVal = (row.querySelector('.edit-prac-level') as HTMLSelectElement)?.value || '';
            practices.push({
                discipline_id: did,
                grade_id: gidVal ? parseInt(gidVal, 10) : null,
                level_id: lidVal ? parseInt(lidVal, 10) : null
            });
        });

        const payload = {
            first_name: (document.getElementById('edit-first-name') as HTMLInputElement).value.trim(),
            last_name: (document.getElementById('edit-last-name') as HTMLInputElement).value.trim(),
            birth_date: (document.getElementById('edit-birth-date') as HTMLInputElement).value.trim(),
            phone: (document.getElementById('edit-phone') as HTMLInputElement).value.trim(),
            address: (document.getElementById('edit-address') as HTMLInputElement).value.trim(),
            emergency_contact_name: (document.getElementById('edit-emerg-name') as HTMLInputElement).value.trim(),
            emergency_contact_phone: (document.getElementById('edit-emerg-phone') as HTMLInputElement).value.trim(),
            status: (document.getElementById('edit-status') as HTMLSelectElement).value,
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
        } finally {
            setButtonLoading(submitBtn, false, 'Enregistrer les modifications');
        }
    });
}

function openMedicalModal(member: MemberRow): void {
    const modal = document.getElementById('modal-medical-upload');
    const label = document.getElementById('medical-member-label');
    const idInput = document.getElementById('medical-member-id') as HTMLInputElement | null;
    if (!modal || !label || !idInput) return;

    idInput.value = String(member.id);
    label.textContent = `Adhérent concerné : ${member.first_name} ${member.last_name} (#${member.id})`;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function initMedicalUploadModal(): void {
    const modal = document.getElementById('modal-medical-upload');
    const closeBtn = document.getElementById('btn-close-medical-modal');
    const form = document.getElementById('form-medical-upload') as HTMLFormElement | null;
    const submitBtn = document.getElementById('btn-submit-medical') as HTMLButtonElement | null;

    if (!modal || !form) return;

    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        const id = (document.getElementById('medical-member-id') as HTMLInputElement).value;
        const fileInput = document.getElementById('medical-file-input') as HTMLInputElement;
        const file = fileInput.files?.[0];
        if (!file) return;

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
        } finally {
            setButtonLoading(submitBtn, false, 'Téléverser');
        }
    });
}