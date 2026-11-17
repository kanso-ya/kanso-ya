/**
 * @file registrations.ts
 * @description Gestion de la Trésorerie (/registrations.php) :
 * - Inspection complète d'un dossier avec possibilité pour le valideur de modifier
 *   le Grade (catégorie d'âge) et le Niveau technique (ceinture) de chaque discipline avant validation
 * - Distinction stricte entre "Radier" (réversible avec bouton "↩️ Restaurer") et "Supprimer définitivement".
 */

import { fetchApi } from './api.js';
import { openConfirmModal, setButtonLoading, showToast } from './main.js';

interface PracticeDetail {
    practice_id: number;
    discipline_id: number;
    grade_id: number | null;
    level_id: number | null;
    discipline_name: string;
    grade_name?: string;
    niveau_name?: string;
}

interface RegisteredMemberDetail {
    id: number;
    first_name: string;
    last_name: string;
    birth_date: string;
    status: string;
    emergency_contact_name?: string;
    emergency_contact_phone?: string;
    discipline_name?: string;
    practices?: PracticeDetail[];
}

interface RegistrationRecord {
    id: number;
    payer_user_id: number | null;
    payer_email?: string;
    season: string;
    total_amount: number | string;
    payment_method: string;
    installments: number;
    status: string;
    created_at?: string;
    members?: RegisteredMemberDetail[];
}

interface DisciplineRef {
    id: number;
    name: string;
    levels: Array<{
        id: number;
        level_type: 'grade' | 'niveau';
        name: string;
        age_min: number | null;
        age_max: number | null;
    }>;
}

let allRegistrations: RegistrationRecord[] = [];
let disciplinesCatalog: DisciplineRef[] = [];
let currentSortField = 'id';
let currentSortOrder: 'asc' | 'desc' = 'desc';

document.addEventListener('DOMContentLoaded', async () => {
    await loadDisciplinesReference();
    await loadRegistrations();
    initStatusFilter();
    initInspectModalClose();
    initSorting();
    initDocumentModal();
});

async function loadDisciplinesReference(): Promise<void> {
    const res = await fetchApi<DisciplineRef[]>('/api/v1/disciplines');
    if (res.status === 'success' && Array.isArray(res.data)) {
        disciplinesCatalog = res.data;
    }
}

async function loadRegistrations(): Promise<void> {
    const res = await fetchApi<RegistrationRecord[]>('/api/v1/registrations');
    if (res.status !== 'success' || !Array.isArray(res.data)) return;

    allRegistrations = res.data;
    renderRegistrationsTable(allRegistrations);
}

function sortRecords(records: RegistrationRecord[]): RegistrationRecord[] {
    return [...records].sort((a, b) => {
        let valA: string | number = '';
        let valB: string | number = '';

        if (currentSortField === 'id') {
            valA = a.id;
            valB = b.id;
        } else if (currentSortField === 'payer') {
            valA = (a.payer_email || '').toLowerCase();
            valB = (b.payer_email || '').toLowerCase();
        } else if (currentSortField === 'amount') {
            valA = Number(a.total_amount) || 0;
            valB = Number(b.total_amount) || 0;
        } else if (currentSortField === 'status') {
            valA = a.status.toLowerCase();
            valB = b.status.toLowerCase();
        }

        if (valA < valB) return currentSortOrder === 'asc' ? -1 : 1;
        if (valA > valB) return currentSortOrder === 'asc' ? 1 : -1;
        return 0;
    });
}

function initSorting(): void {
    const headers = document.querySelectorAll<HTMLTableCellElement>('th[data-sort]');
    headers.forEach((th) => {
        th.addEventListener('click', () => {
            const field = th.getAttribute('data-sort');
            if (!field) return;

            if (currentSortField === field) {
                currentSortOrder = currentSortOrder === 'asc' ? 'desc' : 'asc';
            } else {
                currentSortField = field;
                currentSortOrder = 'asc';
            }

            headers.forEach((h) => {
                const icon = h.querySelector('.sort-icon');
                if (icon) {
                    if (h.getAttribute('data-sort') === currentSortField) {
                        icon.textContent = currentSortOrder === 'asc' ? ' ▲' : ' ▼';
                        (icon as HTMLElement).style.opacity = '1';
                    } else {
                        icon.textContent = ' ⇅';
                        (icon as HTMLElement).style.opacity = '0.4';
                    }
                }
            });

            applyFilterAndRender();
        });
    });
}

function applyFilterAndRender(): void {
    const select = document.getElementById('filter-reg-status') as HTMLSelectElement | null;
    const val = select?.value || 'all';
    let filtered = allRegistrations;
    if (val !== 'all') {
        filtered = allRegistrations.filter((r) => r.status === val);
    }
    renderRegistrationsTable(sortRecords(filtered));
}

function renderRegistrationsTable(records: RegistrationRecord[]): void {
    const tbody = document.getElementById('registrations-tbody');
    if (!tbody) return;

    if (records.length === 0) {
        tbody.innerHTML = `<tr><td colspan="6" class="p-8 text-center text-slate-400">Aucun dossier d'inscription dans cette vue.</td></tr>`;
        return;
    }

    tbody.innerHTML = '';

    records.forEach((reg) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50 transition';

        let statusBadge = '<span class="px-2.5 py-1 text-xs rounded-full bg-amber-50 text-amber-700 font-semibold">En attente</span>';
        if (reg.status === 'validated') {
            statusBadge = '<span class="px-2.5 py-1 text-xs rounded-full bg-emerald-50 text-emerald-700 font-semibold">Validé</span>';
        } else if (reg.status === 'radiated') {
            statusBadge = '<span class="px-2.5 py-1 text-xs rounded-full bg-red-50 text-red-600 font-semibold">Radié (Archivé)</span>';
        }

        const membersList = reg.members || [];
        const membersSummary = membersList.length > 0
            ? membersList.map((m) => `${m.first_name} ${m.last_name}`).join(', ')
            : '1 adhérent rattaché';

        tr.innerHTML = `
            <td class="p-4 font-bold text-slate-900">#${reg.id} <span class="block text-[11px] font-normal text-slate-400">Saison ${reg.season}</span></td>
            <td class="p-4 text-xs text-slate-700 font-medium">${reg.payer_email || 'Dossier famille'}</td>
            <td class="p-4 text-xs text-slate-600">
                <strong class="text-slate-800">${Math.max(1, membersList.length)} pratiquant(s) :</strong>
                <div class="truncate max-w-xs text-slate-500">${membersSummary}</div>
            </td>
            <td class="p-4">
                <span class="font-extrabold text-slate-900">${Number(reg.total_amount).toFixed(2)} €</span>
                <span class="block text-[11px] text-slate-500 uppercase">${reg.payment_method} (${reg.installments}x)</span>
            </td>
            <td class="p-4">${statusBadge}</td>
            <td class="p-4 text-right whitespace-nowrap">
                <div class="inline-flex items-center gap-1.5 justify-end action-cell"></div>
            </td>
        `;

        const actionCell = tr.querySelector('.action-cell');
        if (actionCell) {
            const btnInspect = document.createElement('button');
            btnInspect.type = 'button';
            btnInspect.className = 'bg-slate-900 hover:bg-slate-800 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg transition inline-flex items-center gap-1';
            btnInspect.textContent = '🔍 Inspecter';
            btnInspect.title = 'Inspecter le dossier et les détails des cours';
            btnInspect.addEventListener('click', () => openInspectionModal(reg));
            actionCell.appendChild(btnInspect);

            if (reg.status === 'pending') {
                const btnVal = document.createElement('button');
                btnVal.type = 'button';
                btnVal.className = 'bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold px-2.5 py-1.5 rounded-lg transition inline-flex items-center gap-1';
                btnVal.textContent = '✓ Valider';
                btnVal.title = 'Valider le paiement et le dossier d\'adhésion';
                btnVal.addEventListener('click', async () => {
                    await triggerValidation(reg.id, btnVal, []);
                });
                actionCell.appendChild(btnVal);
            }

            if (reg.status === 'validated') {
                const btnReceipt = document.createElement('button');
                btnReceipt.type = 'button';
                btnReceipt.className = 'btn-download-receipt bg-blue-50 hover:bg-blue-100 text-blue-700 text-xs font-semibold px-2 py-1.5 rounded-lg transition inline-flex items-center gap-1';
                btnReceipt.setAttribute('data-reg-id', String(reg.id));
                btnReceipt.setAttribute('data-testid', `btn-receipt-${reg.id}`);
                btnReceipt.textContent = '📄 Reçu';
                btnReceipt.title = 'Télécharger le reçu de cotisation';
                btnReceipt.addEventListener('click', async () => {
                    const res = await fetchApi<{ receipt_text: string }>(`/api/v1/registrations/${reg.id}/receipt`);
                    if (res.status === 'success' && res.data) {
                        openDocumentModal(`Reçu de Cotisation — Dossier #${reg.id}`, 'Reçu Officiel', res.data.receipt_text);
                    }
                });
                actionCell.appendChild(btnReceipt);

                const btnCse = document.createElement('button');
                btnCse.type = 'button';
                btnCse.className = 'btn-download-cse bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-semibold px-2 py-1.5 rounded-lg transition inline-flex items-center gap-1';
                btnCse.setAttribute('data-reg-id', String(reg.id));
                btnCse.setAttribute('data-testid', `btn-cse-${reg.id}`);
                btnCse.textContent = '🏢 CSE';
                btnCse.title = 'Télécharger l\'attestation employeur / CSE';
                btnCse.addEventListener('click', async () => {
                    const res = await fetchApi<{ attestation_text: string }>(`/api/v1/registrations/${reg.id}/cse-attestation`);
                    if (res.status === 'success' && res.data) {
                        openDocumentModal(`Attestation de Prise en Charge CE / CSE — Dossier #${reg.id}`, 'Attestation CSE', res.data.attestation_text);
                    }
                });
                actionCell.appendChild(btnCse);
            }

            if (reg.status === 'radiated') {
                const btnRestore = document.createElement('button');
                btnRestore.type = 'button';
                btnRestore.className = 'bg-blue-50 hover:bg-blue-100 text-blue-700 border border-blue-200 text-xs font-bold px-2.5 py-1.5 rounded-lg transition inline-flex items-center gap-1';
                btnRestore.textContent = '↩️ Restaurer';
                btnRestore.title = 'Restaurer ce dossier radié en attente';
                btnRestore.addEventListener('click', async () => {
                    const res = await fetchApi(`/api/v1/registrations/${reg.id}/status`, {
                        method: 'PATCH',
                        body: JSON.stringify({ status: 'pending' })
                    });
                    if (res.status === 'success') {
                        showToast(`Dossier #${reg.id} restauré en attente !`, 'success');
                        await loadRegistrations();
                    }
                });
                actionCell.appendChild(btnRestore);
            } else {
                const btnRadiate = document.createElement('button');
                btnRadiate.type = 'button';
                btnRadiate.className = 'bg-amber-50 hover:bg-amber-100 text-amber-800 text-xs font-semibold px-2.5 py-1.5 rounded-lg transition inline-flex items-center gap-1';
                btnRadiate.textContent = '🚫 Radier';
                btnRadiate.title = 'Radier ce dossier (archivage réversible)';
                btnRadiate.addEventListener('click', () => {
                    openConfirmModal(
                        'Radier ce dossier (Archivage réversible)',
                        `Voulez-vous radier le dossier #${reg.id} ? Vous pourrez le restaurer ultérieurement en cas d'erreur.`,
                        async () => {
                            const res = await fetchApi(`/api/v1/registrations/${reg.id}/status`, {
                                method: 'PATCH',
                                body: JSON.stringify({ status: 'radiated' })
                            });
                            if (res.status === 'success') {
                                showToast(`Dossier #${reg.id} radié (restaurable).`, 'info');
                                await loadRegistrations();
                            }
                        }
                    );
                });
                actionCell.appendChild(btnRadiate);
            }

            const btnDel = document.createElement('button');
            btnDel.type = 'button';
            btnDel.className = 'bg-red-50 hover:bg-red-100 text-red-600 text-xs font-semibold px-2 py-1.5 rounded-lg transition inline-flex items-center gap-1';
            btnDel.textContent = '🗑️ Supprimer';
            btnDel.title = 'Supprimer définitivement ce dossier';
            btnDel.addEventListener('click', () => {
                openConfirmModal(
                    'Supprimer définitivement le dossier',
                    `Attention : la suppression du dossier #${reg.id} est définitive et irréversible.`,
                    async () => {
                        const delRes = await fetchApi(`/api/v1/registrations/${reg.id}`, { method: 'DELETE' });
                        if (delRes.status === 'success') {
                            showToast(`Dossier #${reg.id} supprimé définitivement.`, 'info');
                            await loadRegistrations();
                        }
                    }
                );
            });
            actionCell.appendChild(btnDel);
        }

        tbody.appendChild(tr);
    });
}

/**
 * Ouvre la modale d'inspection en permettant au valideur d'ajuster le Grade (catégorie d'âge)
 * et le Niveau technique (ceinture) de chaque discipline suivie par chaque pratiquant.
 */
function openInspectionModal(reg: RegistrationRecord): void {
    const modal = document.getElementById('inspect-registration-modal');
    const badge = document.getElementById('inspect-reg-status-badge');
    const title = document.getElementById('inspect-reg-title');
    const meta = document.getElementById('inspect-reg-meta');
    const payerEl = document.getElementById('inspect-payer-email');
    const amountEl = document.getElementById('inspect-total-amount');
    const paymentEl = document.getElementById('inspect-payment-info');
    const membersContainer = document.getElementById('inspect-members-list');
    const validateBtn = document.getElementById('btn-modal-validate-reg') as HTMLButtonElement | null;

    if (!modal || !badge || !title || !meta || !payerEl || !amountEl || !paymentEl || !membersContainer || !validateBtn) return;

    const isValidated = reg.status === 'validated';
    badge.className = isValidated
        ? 'text-xs font-bold px-2.5 py-1 rounded-full bg-emerald-50 text-emerald-700'
        : 'text-xs font-bold px-2.5 py-1 rounded-full bg-amber-50 text-amber-700';
    badge.textContent = isValidated ? 'DOSSIER VALIDÉ' : (reg.status === 'radiated' ? 'DOSSIER RADIÉ' : 'EN ATTENTE DE VALIDATION');

    title.textContent = `Dossier d'Adhésion #${reg.id} — Saison ${reg.season}`;
    meta.textContent = `Vérifiez ou ajustez ci-dessous les Grades (cours d'âge) et Niveaux (ceintures) avant de valider.`;
    payerEl.textContent = reg.payer_email || 'Payeur principal rattaché';
    amountEl.textContent = `${Number(reg.total_amount).toFixed(2)} €`;

    const perInstallment = (Number(reg.total_amount) / Math.max(1, reg.installments)).toFixed(2);
    paymentEl.textContent = `${reg.payment_method.toUpperCase()} en ${reg.installments}x (${perInstallment} € / éch.)`;

    membersContainer.innerHTML = '';
    const members = reg.members || [];

    members.forEach((m, idx) => {
        const card = document.createElement('div');
        card.className = 'p-4 rounded-xl border border-slate-200 bg-white space-y-3 text-xs';

        const practices = m.practices || [];
        const practicesHtml = practices.map((p) => {
            const discRef = disciplinesCatalog.find((d) => Number(d.id) === Number(p.discipline_id));
            const grades = (discRef?.levels || []).filter((l) => (l.level_type || 'grade') === 'grade');
            const niveaux = (discRef?.levels || []).filter((l) => l.level_type === 'niveau');

            const gradeOpts = grades.map((g) => `<option value="${g.id}" ${Number(g.id) === Number(p.grade_id) ? 'selected' : ''}>${g.name}</option>`).join('');
            const levelOpts = niveaux.map((n) => `<option value="${n.id}" ${Number(n.id) === Number(p.level_id) ? 'selected' : ''}>${n.name}</option>`).join('');

            return `
                <div class="practice-adjust-row grid grid-cols-1 sm:grid-cols-3 gap-2 items-center p-2.5 rounded-lg bg-slate-50 border border-slate-200" data-practice-id="${p.practice_id}">
                    <div>
                        <span class="text-[10px] font-bold uppercase text-slate-400 block">Discipline</span>
                        <strong class="text-slate-900">🥋 ${p.discipline_name}</strong>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold uppercase text-blue-700 block">Grade / Catégorie d'âge</label>
                        <select class="val-adjust-grade w-full bg-white border border-slate-300 rounded-lg px-2 py-1 text-xs font-semibold">
                            ${gradeOpts || `<option value="">${p.grade_name || 'Standard'}</option>`}
                        </select>
                    </div>
                    <div>
                        <label class="text-[10px] font-bold uppercase text-emerald-700 block">Niveau (Ceinture)</label>
                        <select class="val-adjust-level w-full bg-white border border-slate-300 rounded-lg px-2 py-1 text-xs font-semibold">
                            ${levelOpts || `<option value="">${p.niveau_name || 'Débutant'}</option>`}
                        </select>
                    </div>
                </div>
            `;
        }).join('');

        card.innerHTML = `
            <div class="flex items-center justify-between border-b border-slate-100 pb-2">
                <div>
                    <span class="font-bold text-slate-900 text-sm">Pratiquant #${idx + 1} : ${m.first_name} ${m.last_name}</span>
                    <span class="text-slate-500 ml-2">(Né(e) le ${m.birth_date})</span>
                </div>
                <span class="text-slate-600">🚨 Urgence : <strong>${m.emergency_contact_name || 'Renseigné'} (${m.emergency_contact_phone || '06 00 00 00 00'})</strong></span>
            </div>
            <div class="space-y-2">${practicesHtml}</div>
        `;
        membersContainer.appendChild(card);
    });

    if (reg.status !== 'pending') {
        validateBtn.classList.add('hidden');
    } else {
        validateBtn.classList.remove('hidden');
        validateBtn.onclick = async () => {
            const practiceUpdates: Array<{ practice_id: number; grade_id: number | null; level_id: number | null }> = [];
            membersContainer.querySelectorAll<HTMLElement>('.practice-adjust-row').forEach((row) => {
                const pid = parseInt(row.getAttribute('data-practice-id') || '0', 10);
                const gidVal = (row.querySelector('.val-adjust-grade') as HTMLSelectElement | null)?.value || '';
                const lidVal = (row.querySelector('.val-adjust-level') as HTMLSelectElement | null)?.value || '';
                if (pid > 0) {
                    practiceUpdates.push({
                        practice_id: pid,
                        grade_id: gidVal ? parseInt(gidVal, 10) : null,
                        level_id: lidVal ? parseInt(lidVal, 10) : null
                    });
                }
            });

            await triggerValidation(reg.id, validateBtn, practiceUpdates);
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };
    }

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

async function triggerValidation(
    regId: number,
    btn: HTMLButtonElement,
    practiceUpdates: Array<{ practice_id: number; grade_id: number | null; level_id: number | null }> = []
): Promise<void> {
    setButtonLoading(btn, true, 'Valider');
    try {
        const res = await fetchApi<{ otp_code_stub?: string }>(`/api/v1/registrations/${regId}/validate`, {
            method: 'PATCH',
            body: JSON.stringify({ practice_updates: practiceUpdates })
        });
        if (res.status === 'success') {
            const otp = res.data?.otp_code_stub || '';
            showToast(`Dossier #${regId} validé (Cours & Grades enregistrés) !`, 'success');
            await loadRegistrations();
            if (otp) {
                const tbody = document.getElementById('registrations-tbody');
                const banner = document.createElement('tr');
                banner.id = `otp-banner-${regId}`;
                banner.setAttribute('data-testid', `otp-banner-${regId}`);
                banner.className = 'bg-emerald-50 border-b border-emerald-200';
                banner.innerHTML = `
                    <td colspan="6" class="p-3 text-xs text-emerald-900">
                        <div class="flex items-center justify-between">
                            <span>📧 <strong>Lien d'activation généré (Dossier #${regId}) :</strong> <code id="otp-code-${regId}">${otp}</code></span>
                            <a href="/activate-otp?otp=${otp}" class="px-3 py-1.5 bg-emerald-600 text-white font-bold rounded-lg hover:bg-emerald-700 transition">
                                Tester l'activation du compte →
                            </a>
                        </div>
                    </td>
                `;
                tbody?.prepend(banner);
            }
        }
    } finally {
        setButtonLoading(btn, false, 'Valider');
    }
}

function initStatusFilter(): void {
    const select = document.getElementById('filter-reg-status') as HTMLSelectElement | null;
    if (!select) return;

    select.addEventListener('change', () => {
        applyFilterAndRender();
    });
}

function initInspectModalClose(): void {
    const modal = document.getElementById('inspect-registration-modal');
    const close1 = document.getElementById('btn-close-inspect-modal');
    const close2 = document.getElementById('btn-cancel-inspect');

    const close = (): void => {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
    };
    close1?.addEventListener('click', close);
    close2?.addEventListener('click', close);
}

function openDocumentModal(title: string, badge: string, content: string): void {
    const modal = document.getElementById('document-preview-modal');
    const titleEl = document.getElementById('doc-modal-title');
    const badgeEl = document.getElementById('doc-modal-type-badge');
    const bodyEl = document.getElementById('doc-modal-body');

    if (!modal || !titleEl || !badgeEl || !bodyEl) return;

    titleEl.textContent = title;
    badgeEl.textContent = badge;
    bodyEl.textContent = content;

    modal.classList.remove('hidden');
    modal.classList.add('flex');
}

function initDocumentModal(): void {
    const modal = document.getElementById('document-preview-modal');
    const closeBtn = document.getElementById('btn-close-doc-modal');
    const dismissBtn = document.getElementById('btn-dismiss-doc-modal');
    const printBtn = document.getElementById('btn-print-doc');

    const close = (): void => {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
    };

    closeBtn?.addEventListener('click', close);
    dismissBtn?.addEventListener('click', close);
    printBtn?.addEventListener('click', () => {
        window.print();
    });
}