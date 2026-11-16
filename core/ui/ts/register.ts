/**
 * @file register.ts
 * @description Wizard d'inscription en ligne multi-disciplines :
 * - Chargement dynamique du catalogue des Disciplines, Grades (Catégories d'âge) et Niveaux (Ceintures)
 * - Pré-sélection automatique du Grade d'âge dans les listes déroulantes dès que la date de naissance change
 * - Possibilité pour un même adhérent de suivre plusieurs disciplines (chacune avec son Grade et son Niveau)
 * - Transfert automatique de l'email mineur vers le Responsable Légal 1.
 */

import { fetchApi } from './api.js';
import { currentAuthContext, setButtonLoading, showToast } from './main.js';

interface LevelCatalogItem {
    id: number;
    level_type: 'grade' | 'niveau';
    name: string;
    age_min: number | null;
    age_max: number | null;
}

interface DisciplineCatalogItem {
    id: number;
    name: string;
    levels: LevelCatalogItem[];
}

interface PracticeSelection {
    discipline_id: number;
    discipline_name: string;
    grade_id: number | null;
    grade_name: string;
    level_id: number | null;
    level_name: string;
}

interface CartMember {
    first_name: string;
    last_name: string;
    birth_date: string;
    email?: string;
    discipline_id: number;
    practices: PracticeSelection[];
    emergency_contact_name: string;
    emergency_contact_phone: string;
    emergency_contact_relation?: string;
    emergency_contact_secondary_phone?: string;
    health_attestation_signed?: number;
}

interface CartTutor {
    first_name?: string;
    last_name?: string;
    relationship?: string;
    email: string;
    phone?: string;
    address?: string;
    birth_date?: string;
}

const familyCart: CartMember[] = [];
let disciplinesCatalog: DisciplineCatalogItem[] = [];
let isMinorFlow = false;
let connectedUserProfile: any = null;

document.addEventListener('DOMContentLoaded', async () => {
    initPublicRegistrationWizard();
    await loadDisciplinesCatalog();
    checkConnectedUserForReEnrollment();
    checkAndRestoreDraft();
    window.addEventListener('kanso:auth-ready', () => {
        checkConnectedUserForReEnrollment();
    });
});

export function calculateAgeFromInput(birthDateStr: string): number {
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

/**
 * Charge le référentiel des disciplines, grades d'âge et niveaux techniques depuis l'API.
 */
async function loadDisciplinesCatalog(): Promise<void> {
    const res = await fetchApi<DisciplineCatalogItem[]>('/api/v1/disciplines');
    if (res.status === 'success' && Array.isArray(res.data)) {
        disciplinesCatalog = res.data;
    }

    const container = document.getElementById('reg-practices-container');
    if (container && container.children.length === 0) {
        appendPracticeSelectorRow();
    }
}

/**
 * Ajoute une ligne permettant de choisir : Discipline + Grade (pré-rempli selon l'âge) + Niveau technique.
 */
function appendPracticeSelectorRow(initialDiscId = 1, initialGradeId?: number | null, initialLevelId?: number | null): void {
    const container = document.getElementById('reg-practices-container');
    if (!container) return;

    const birthInput = document.getElementById('reg-birth-date') as HTMLInputElement | null;
    const currentAge = birthInput?.value ? calculateAgeFromInput(birthInput.value) : 25;

    const row = document.createElement('div');
    row.className = 'practice-selector-row p-4 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-1 md:grid-cols-12 gap-3 items-end';

    const discOptions = disciplinesCatalog.length > 0
        ? disciplinesCatalog.map((d) => `<option value="${d.id}" ${d.id === initialDiscId ? 'selected' : ''}>${d.name}</option>`).join('')
        : `<option value="1">Judo</option><option value="2">Karaté</option><option value="3">Aïkido</option>`;

    row.innerHTML = `
        <div class="md:col-span-4">
            <label class="block text-[11px] font-bold uppercase text-slate-600 mb-1">Discipline *</label>
            <select class="prac-discipline w-full bg-white border border-slate-300 rounded-xl px-3 py-2 text-xs font-bold text-slate-900">
                ${discOptions}
            </select>
        </div>
        <div class="md:col-span-4">
            <label class="block text-[11px] font-bold uppercase text-blue-700 mb-1">Grade / Cours (Pré-rempli par âge) *</label>
            <select class="prac-grade w-full bg-white border border-blue-300 rounded-xl px-3 py-2 text-xs font-semibold text-slate-800"></select>
        </div>
        <div class="md:col-span-3">
            <label class="block text-[11px] font-bold uppercase text-emerald-700 mb-1">Niveau Technique (Ceinture) *</label>
            <select class="prac-level w-full bg-white border border-emerald-300 rounded-xl px-3 py-2 text-xs font-semibold text-slate-800"></select>
        </div>
        <div class="md:col-span-1 flex justify-end">
            <button type="button" class="btn-remove-practice px-2.5 py-2 rounded-xl bg-red-50 hover:bg-red-100 text-red-600 text-xs font-bold transition" title="Retirer cette discipline">✕</button>
        </div>
    `;

    const discSelect = row.querySelector('.prac-discipline') as HTMLSelectElement;
    const gradeSelect = row.querySelector('.prac-grade') as HTMLSelectElement;
    const levelSelect = row.querySelector('.prac-level') as HTMLSelectElement;

    const populateGradesAndLevels = (discId: number, ageForAutoGrade: number, forceGradeId?: number | null, forceLevelId?: number | null): void => {
        const disc = disciplinesCatalog.find((d) => Number(d.id) === Number(discId));
        const allLevels = disc?.levels || [];
        const grades = allLevels.filter((l) => (l.level_type || 'grade') === 'grade');
        const niveaux = allLevels.filter((l) => l.level_type === 'niveau');

        // 1. Peuplement des Grades (Catégories d'âge) avec pré-sélection automatique selon l'âge
        gradeSelect.innerHTML = '';
        if (grades.length === 0) {
            gradeSelect.innerHTML = `<option value="">Cours général (Tous âges)</option>`;
        } else {
            let matchedGradeId = forceGradeId || null;
            if (!matchedGradeId) {
                const autoMatch = grades.find(
                    (g) => (g.age_min === null || ageForAutoGrade >= g.age_min) && (g.age_max === null || ageForAutoGrade <= g.age_max)
                );
                matchedGradeId = autoMatch ? autoMatch.id : grades[0].id;
            }

            grades.forEach((g) => {
                const opt = document.createElement('option');
                opt.value = String(g.id);
                opt.textContent = `${g.name} (${g.age_min ?? 0}-${g.age_max ?? 99} ans)`;
                if (Number(g.id) === Number(matchedGradeId)) {
                    opt.selected = true;
                }
                gradeSelect.appendChild(opt);
            });
        }

        // 2. Peuplement des Niveaux techniques (Ceintures / Gants)
        levelSelect.innerHTML = '';
        if (niveaux.length === 0) {
            levelSelect.innerHTML = `<option value="">Niveau Débutant</option>`;
        } else {
            const targetLevelId = forceLevelId || niveaux[0].id;
            niveaux.forEach((n) => {
                const opt = document.createElement('option');
                opt.value = String(n.id);
                opt.textContent = n.name;
                if (Number(n.id) === Number(targetLevelId)) {
                    opt.selected = true;
                }
                levelSelect.appendChild(opt);
            });
        }

        // Synchronise le champ caché #reg-discipline avec la 1ère discipline choisie
        const firstDiscSelect = document.querySelector('.prac-discipline') as HTMLSelectElement | null;
        const hiddenDisc = document.getElementById('reg-discipline') as HTMLSelectElement | null;
        if (firstDiscSelect && hiddenDisc) {
            hiddenDisc.value = firstDiscSelect.value;
        }
    };

    populateGradesAndLevels(initialDiscId, currentAge, initialGradeId, initialLevelId);

    discSelect.addEventListener('change', () => {
        const ageNow = birthInput?.value ? calculateAgeFromInput(birthInput.value) : 25;
        populateGradesAndLevels(parseInt(discSelect.value, 10), ageNow);
    });

    row.querySelector('.btn-remove-practice')?.addEventListener('click', () => {
        if (container.querySelectorAll('.practice-selector-row').length > 1) {
            row.remove();
        } else {
            showToast('Au moins une discipline doit être sélectionnée.', 'info');
        }
    });

    container.appendChild(row);
}

/**
 * Met à jour automatiquement la pré-sélection du Grade (catégorie d'âge) sur toutes les lignes de disciplines
 * lorsque l'utilisateur saisit ou modifie la date de naissance.
 */
function autoSelectGradesForBirthDate(age: number): void {
    document.querySelectorAll<HTMLElement>('.practice-selector-row').forEach((row) => {
        const discSelect = row.querySelector('.prac-discipline') as HTMLSelectElement | null;
        const gradeSelect = row.querySelector('.prac-grade') as HTMLSelectElement | null;
        if (!discSelect || !gradeSelect) return;

        const disc = disciplinesCatalog.find((d) => Number(d.id) === parseInt(discSelect.value, 10));
        const grades = (disc?.levels || []).filter((l) => (l.level_type || 'grade') === 'grade');
        const autoMatch = grades.find(
            (g) => (g.age_min === null || age >= g.age_min) && (g.age_max === null || age <= g.age_max)
        );
        if (autoMatch) {
            gradeSelect.value = String(autoMatch.id);
        }
    });
}

async function checkConnectedUserForReEnrollment(): Promise<void> {
    if (!currentAuthContext.user_id || currentAuthContext.role === 'Visiteur') return;
    const banner = document.getElementById('connected-re-enrollment-banner');
    const select = document.getElementById('select-existing-family-member') as HTMLSelectElement | null;
    if (!banner || !select || select.dataset.loaded === '1') return;

    const res = await fetchApi<any>('/api/v1/users/me/profile');
    if (res.status !== 'success' || !res.data) return;

    connectedUserProfile = res.data;
    select.dataset.loaded = '1';
    banner.classList.remove('hidden');

    const linked: any[] = res.data.linked_members || [];

    if (res.data.first_name && res.data.last_name) {
        const selfOpt = document.createElement('option');
        selfOpt.value = 'self';
        selfOpt.textContent = `🔄 Me réinscrire (${res.data.first_name} ${res.data.last_name})`;
        select.appendChild(selfOpt);
    }

    linked.forEach((m, idx) => {
        const opt = document.createElement('option');
        opt.value = String(idx);
        opt.textContent = `🔄 Réinscrire ${m.first_name} ${m.last_name} (${m.birth_date})`;
        select.appendChild(opt);
    });

    select.addEventListener('change', () => {
        const val = select.value;
        const fnInput = document.getElementById('reg-first-name') as HTMLInputElement;
        const lnInput = document.getElementById('reg-last-name') as HTMLInputElement;
        const bdInput = document.getElementById('reg-birth-date') as HTMLInputElement;
        const emInput = document.getElementById('reg-email') as HTMLInputElement;
        const emergName = document.getElementById('reg-emerg-name') as HTMLInputElement;
        const emergPhone = document.getElementById('reg-emerg-phone') as HTMLInputElement;

        if (val === 'self') {
            fnInput.value = res.data.first_name || '';
            lnInput.value = res.data.last_name || '';
            bdInput.value = '1988-05-15';
            emInput.value = res.data.email || '';
            bdInput.dispatchEvent(new Event('change'));
            saveDraftToStorage();
            showToast('Vos informations ont été pré-remplies.', 'info');
        } else if (val !== '') {
            const m = linked[parseInt(val, 10)];
            if (m) {
                fnInput.value = m.first_name || '';
                lnInput.value = m.last_name || '';
                bdInput.value = m.birth_date || '';
                emergName.value = m.emergency_contact_name || `${res.data.first_name || ''} ${res.data.last_name || ''}`.trim();
                emergPhone.value = m.emergency_contact_phone || res.data.phone || '';
                (document.getElementById('tutor1-first-name') as HTMLInputElement).value = res.data.first_name || '';
                (document.getElementById('tutor1-last-name') as HTMLInputElement).value = res.data.last_name || '';
                (document.getElementById('tutor1-email') as HTMLInputElement).value = res.data.email || '';
                (document.getElementById('tutor1-phone') as HTMLInputElement).value = res.data.phone || '';
                (document.getElementById('tutor1-address') as HTMLInputElement).value = res.data.address || '';
                bdInput.dispatchEvent(new Event('change'));
                saveDraftToStorage();
                showToast(`Fiche de ${m.first_name} pré-remplie (Grade ajusté selon son âge) !`, 'success');
            }
        }
    });
}

function initPublicRegistrationWizard(): void {
    const birthInput = document.getElementById('reg-birth-date') as HTMLInputElement | null;
    const tutorSection = document.getElementById('tutor-section') as HTMLElement | null;
    const stepPanelTutors = document.getElementById('step-panel-tutors') as HTMLElement | null;
    const breadcrumb = document.getElementById('registration-breadcrumb') as HTMLElement | null;
    const breadcrumbTutors = document.getElementById('breadcrumb-tutors') as HTMLElement | null;
    const ageBadge = document.getElementById('age-indicator-badge');
    const adultEmailWrapper = document.getElementById('adult-email-wrapper');
    const minorEmailNotice = document.getElementById('minor-email-notice');
    const addPracticeBtn = document.getElementById('btn-add-practice-row');
    const form = document.getElementById('public-registration-form') as HTMLFormElement | null;

    if (!form || !birthInput || !tutorSection) return;

    addPracticeBtn?.addEventListener('click', () => {
        appendPracticeSelectorRow(2);
    });

    const updateBreadcrumbLayout = (requiresTutors: boolean): void => {
        if (!breadcrumb || !breadcrumbTutors) return;
        const bcEmergNum = document.querySelector('#bc-step-emergency .bc-num');
        const bcPayNum = document.querySelector('#bc-step-payment .bc-num');

        if (requiresTutors) {
            breadcrumb.className = 'grid grid-cols-2 sm:grid-cols-4 gap-2 mb-8';
            breadcrumbTutors.classList.remove('hidden');
            if (bcEmergNum) bcEmergNum.textContent = '3';
            if (bcPayNum) bcPayNum.textContent = '4';
        } else {
            breadcrumb.className = 'grid grid-cols-3 gap-2 mb-8';
            breadcrumbTutors.classList.add('hidden');
            if (bcEmergNum) bcEmergNum.textContent = '2';
            if (bcPayNum) bcPayNum.textContent = '3';
        }
    };

    const goToStep = (step: 'identity' | 'tutors' | 'emergency' | 'payment'): void => {
        document.getElementById('step-panel-identity')?.classList.toggle('hidden', step !== 'identity');
        stepPanelTutors?.classList.toggle('hidden', step !== 'tutors');
        document.getElementById('step-panel-emergency')?.classList.toggle('hidden', step !== 'emergency');
        document.getElementById('step-panel-payment')?.classList.toggle('hidden', step !== 'payment');

        if (step === 'tutors') {
            tutorSection.classList.remove('hidden');
        }

        document.querySelectorAll<HTMLElement>('#registration-breadcrumb .bc-item').forEach((item) => {
            const target = item.getAttribute('data-target');
            if (target === step) {
                item.className = 'bc-item cursor-pointer p-3 rounded-xl border-2 border-emerald-600 bg-emerald-50/70 text-emerald-900 transition';
            } else {
                item.className = `bc-item cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition ${target === 'tutors' && !isMinorFlow ? 'hidden' : ''}`;
            }
        });

        if (step === 'payment') {
            renderFamilyCart();
        }
    };

    birthInput.addEventListener('change', () => {
        const age = calculateAgeFromInput(birthInput.value);
        const cartHasMinor = familyCart.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        isMinorFlow = age < 18 || cartHasMinor;

        // Pré-sélection automatique du Grade (catégorie d'âge) selon l'âge calculé
        autoSelectGradesForBirthDate(age);

        const memberEmailInput = document.getElementById('reg-email') as HTMLInputElement | null;
        const tutor1EmailInput = document.getElementById('tutor1-email') as HTMLInputElement | null;

        if (age < 18) {
            tutorSection.classList.remove('hidden');
            if (memberEmailInput && memberEmailInput.value.trim() !== '' && tutor1EmailInput && tutor1EmailInput.value.trim() === '') {
                tutor1EmailInput.value = memberEmailInput.value.trim();
                memberEmailInput.value = '';
            }
            adultEmailWrapper?.classList.add('hidden');
            minorEmailNotice?.classList.remove('hidden');
            document.getElementById('btn-copy-tutor-to-emergency')?.classList.remove('hidden');

            if (ageBadge) {
                ageBadge.textContent = `Pratiquant Mineur (${age} ans) — Grade pré-sélectionné`;
                ageBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-amber-100 text-amber-800';
            }
        } else {
            tutorSection.classList.add('hidden');
            adultEmailWrapper?.classList.remove('hidden');
            minorEmailNotice?.classList.add('hidden');

            if (ageBadge) {
                ageBadge.textContent = `Pratiquant Majeur (${age} ans) — Grade Adulte pré-sélectionné`;
                ageBadge.className = 'text-xs font-bold px-3 py-1 rounded-full bg-emerald-100 text-emerald-800';
            }
        }

        updateBreadcrumbLayout(isMinorFlow);
    });

    const payMethodSelect = document.getElementById('reg-payment-method') as HTMLSelectElement | null;
    const installmentsSelect = document.getElementById('reg-installments') as HTMLSelectElement | null;
    const installmentHint = document.getElementById('installment-restriction-hint');

    payMethodSelect?.addEventListener('change', () => {
        if (!installmentsSelect) return;
        const isCheque = payMethodSelect.value === 'cheque';
        installmentsSelect.querySelectorAll<HTMLOptionElement>('.opt-multi-installment').forEach((opt) => {
            opt.disabled = !isCheque;
        });
        if (!isCheque) {
            installmentsSelect.value = '1';
            installmentHint?.classList.remove('hidden');
        } else {
            installmentHint?.classList.add('hidden');
        }
    });

    document.getElementById('btn-next-from-identity')?.addEventListener('click', () => {
        const fn = (document.getElementById('reg-first-name') as HTMLInputElement).value.trim();
        const ln = (document.getElementById('reg-last-name') as HTMLInputElement).value.trim();
        const bd = birthInput.value.trim();

        if (!fn || !ln || !bd) {
            showToast('Veuillez renseigner le prénom, le nom et la date de naissance.', 'error');
            return;
        }

        const age = calculateAgeFromInput(bd);
        isMinorFlow = age < 18 || familyCart.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        updateBreadcrumbLayout(isMinorFlow);
        goToStep(isMinorFlow ? 'tutors' : 'emergency');
    });

    document.getElementById('btn-next-from-tutors')?.addEventListener('click', () => {
        const t1Email = (document.getElementById('tutor1-email') as HTMLInputElement).value.trim();
        const t1Birth = (document.getElementById('tutor1-birth') as HTMLInputElement).value.trim();

        if (!t1Email) {
            showToast('L\'email du Responsable Légal 1 est obligatoire pour inscrire un mineur.', 'error');
            return;
        }
        if (t1Birth && calculateAgeFromInput(t1Birth) < 18) {
            showToast('Le Responsable Légal 1 doit obligatoirement être majeur (18 ans minimum).', 'error');
            return;
        }
        goToStep('emergency');
    });

    document.querySelectorAll('.btn-step-prev').forEach((btn) => {
        btn.addEventListener('click', () => goToStep('identity'));
    });

    document.getElementById('btn-prev-from-emergency')?.addEventListener('click', () => {
        goToStep(isMinorFlow ? 'tutors' : 'identity');
    });

    document.getElementById('btn-next-from-emergency')?.addEventListener('click', () => {
        const eName = (document.getElementById('reg-emerg-name') as HTMLInputElement).value.trim();
        const ePhone = (document.getElementById('reg-emerg-phone') as HTMLInputElement).value.trim();
        if (!eName || !ePhone) {
            showToast('Le nom et le téléphone du contact d\'urgence sont obligatoires.', 'error');
            return;
        }
        goToStep('payment');
    });

    document.getElementById('btn-prev-from-payment')?.addEventListener('click', () => {
        goToStep('emergency');
    });

    document.getElementById('btn-add-family-member')?.addEventListener('click', () => {
        const currentMember = readCurrentMemberInputs();
        if (!currentMember) {
            showToast('Veuillez compléter l\'identité et le contact d\'urgence avant d\'ajouter un autre membre.', 'error');
            return;
        }

        familyCart.push(currentMember);
        resetMemberInputsForNextSibling();
        renderFamilyCart();
        saveDraftToStorage();
        showToast(`${currentMember.first_name} a été ajouté au panier familial !`, 'success');
        goToStep('identity');
    });

    // Modale de pré-soumission et confirmation (Point 17)
    const preSubmitModal = document.getElementById('pre-submit-modal');
    const modalSummary = document.getElementById('modal-summary-content');
    const acceptRulesCb = document.getElementById('modal-accept-rules') as HTMLInputElement | null;

    document.getElementById('btn-open-confirm-modal')?.addEventListener('click', () => {
        const currentMember = readCurrentMemberInputs();
        const allMembers: CartMember[] = [...familyCart];
        if (currentMember) {
            allMembers.push(currentMember);
        }

        if (allMembers.length === 0) {
            showToast('Veuillez renseigner au moins un adhérent avant de valider.', 'error');
            goToStep('identity');
            return;
        }

        const hasMinor = allMembers.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        const tutors = readTutorsInputs();
        if (hasMinor && tutors.length === 0) {
            showToast('Un responsable légal est obligatoire pour inscrire un mineur.', 'error');
            goToStep('tutors');
            return;
        }

        // Construire le récapitulatif
        if (modalSummary) {
            const card = document.getElementById('registration-wizard-card');
            const priceText = document.getElementById('reg-total-price')?.textContent || '0.00';
            const methodSelect = document.getElementById('reg-payment-method') as HTMLSelectElement | null;
            const methodLabel = methodSelect?.options[methodSelect.selectedIndex]?.text || 'Chèque';

            let membersHtml = allMembers.map((m, i) => {
                const age = calculateAgeFromInput(m.birth_date);
                const discNames = m.practices.map(p => `${p.discipline_name} (${p.grade_name || 'Cours'})`).join(', ');
                const emerg = `${m.emergency_contact_name} (${m.emergency_contact_phone})`;
                return `
                    <div class="p-3 bg-slate-50 rounded-xl border border-slate-200">
                        <div class="font-bold text-slate-900">#${i + 1} ${m.first_name} ${m.last_name} (${age} ans)</div>
                        <div class="text-[11px] text-slate-600">Disciplines : ${discNames}</div>
                        <div class="text-[11px] text-slate-500">Contact d'urgence : ${emerg}</div>
                        ${m.health_attestation_signed ? '<div class="text-[11px] text-emerald-700 font-semibold">✓ Questionnaire de santé Cerfa validé</div>' : ''}
                    </div>
                `;
            }).join('');

            modalSummary.innerHTML = `
                <div class="space-y-2">
                    <div class="text-xs font-bold uppercase text-slate-500">Adhérents concernés (${allMembers.length}) :</div>
                    ${membersHtml}
                </div>
                <div class="p-3 bg-emerald-50 rounded-xl border border-emerald-200 flex justify-between items-center text-xs mt-3">
                    <div>
                        <span class="font-bold text-emerald-900 block">Règlement : ${methodLabel}</span>
                        <span class="text-emerald-700">Cotisation annuelle totale</span>
                    </div>
                    <span class="text-base font-black text-emerald-800">${priceText} €</span>
                </div>
            `;
        }

        if (acceptRulesCb) acceptRulesCb.checked = false;
        preSubmitModal?.classList.remove('hidden');
    });

    document.getElementById('btn-close-pre-submit')?.addEventListener('click', () => {
        preSubmitModal?.classList.add('hidden');
    });

    document.getElementById('btn-cancel-confirm')?.addEventListener('click', () => {
        preSubmitModal?.classList.add('hidden');
    });

    document.getElementById('btn-confirm-registration')?.addEventListener('click', () => {
        if (acceptRulesCb && !acceptRulesCb.checked) {
            showToast('Veuillez cocher la case attestant avoir pris connaissance du règlement intérieur.', 'error');
            return;
        }
        preSubmitModal?.classList.add('hidden');
        document.getElementById('btn-submit-registration')?.click();
    });

    // Gestion du brouillon (Point 16)
    form.addEventListener('input', () => saveDraftToStorage());
    form.addEventListener('change', () => saveDraftToStorage());

    document.getElementById('btn-restore-draft')?.addEventListener('click', () => {
        restoreDraftFromStorage();
        document.getElementById('draft-restore-banner')?.classList.add('hidden');
        showToast('Votre brouillon d\'inscription a été restauré.', 'success');
    });

    document.getElementById('btn-clear-draft')?.addEventListener('click', () => {
        localStorage.removeItem('kanso_reg_draft');
        document.getElementById('draft-restore-banner')?.classList.add('hidden');
        form.reset();
        familyCart.length = 0;
        renderFamilyCart();
        showToast('Brouillon effacé.', 'info');
    });

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();

        const currentMember = readCurrentMemberInputs();
        const allMembers: CartMember[] = [...familyCart];
        if (currentMember) {
            allMembers.push(currentMember);
        }

        if (allMembers.length === 0) {
            showToast('Veuillez renseigner au moins un adhérent.', 'error');
            return;
        }

        const hasMinor = allMembers.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        const tutors = readTutorsInputs();

        if (hasMinor && tutors.length === 0) {
            showToast('Un responsable légal est obligatoire pour inscrire un mineur.', 'error');
            goToStep('tutors');
            return;
        }

        const paymentMethod = payMethodSelect?.value || 'cheque';
        const installments = parseInt(installmentsSelect?.value || '1', 10);
        const submitBtn = document.getElementById('btn-submit-registration') as HTMLButtonElement | null;

        setButtonLoading(submitBtn, true, 'Valider définitivement mon dossier d\'inscription');

        try {
            const response = await fetchApi<{ registration_id: number; total_amount: number }>('/api/v1/registrations', {
                method: 'POST',
                body: JSON.stringify({
                    members: allMembers,
                    tutors,
                    payment_method: paymentMethod,
                    installments
                })
            });

            if (response.status === 'success' && response.data) {
                showToast('Dossier enregistré avec succès !', 'success');
                localStorage.removeItem('kanso_reg_draft');
                document.getElementById('draft-restore-banner')?.classList.add('hidden');
                document.getElementById('registration-wizard-card')?.classList.add('hidden');
                const successScreen = document.getElementById('registration-success-screen');
                const regIdSpan = document.getElementById('confirm-screen-reg-id');
                const amountSpan = document.getElementById('confirm-screen-amount');

                if (regIdSpan) regIdSpan.textContent = String(response.data.registration_id);
                if (amountSpan) amountSpan.textContent = Number(response.data.total_amount).toFixed(2);
                successScreen?.classList.remove('hidden');

                familyCart.length = 0;
                form.reset();
            }
        } finally {
            setButtonLoading(submitBtn, false, 'Valider définitivement mon dossier d\'inscription');
        }
    });

    document.getElementById('btn-new-registration-again')?.addEventListener('click', () => {
        document.getElementById('registration-success-screen')?.classList.add('hidden');
        document.getElementById('registration-wizard-card')?.classList.remove('hidden');
        isMinorFlow = false;
        updateBreadcrumbLayout(false);
        goToStep('identity');
    });
}

function readCurrentMemberInputs(): CartMember | null {
    const firstName = (document.getElementById('reg-first-name') as HTMLInputElement | null)?.value.trim() || '';
    const lastName = (document.getElementById('reg-last-name') as HTMLInputElement | null)?.value.trim() || '';
    const birthDate = (document.getElementById('reg-birth-date') as HTMLInputElement | null)?.value.trim() || '';
    const email = (document.getElementById('reg-email') as HTMLInputElement | null)?.value.trim() || '';
    const emergName = (document.getElementById('reg-emerg-name') as HTMLInputElement | null)?.value.trim() || '';
    const emergPhone = (document.getElementById('reg-emerg-phone') as HTMLInputElement | null)?.value.trim() || '';
    const emergRelation = (document.getElementById('reg-emerg-relation') as HTMLInputElement | null)?.value.trim() || '';
    const emergPhoneSec = (document.getElementById('reg-emerg-phone-secondary') as HTMLInputElement | null)?.value.trim() || '';
    const healthSigned = (document.getElementById('choice-health-attestation') as HTMLInputElement | null)?.checked ? 1 : 0;

    if (!firstName || !lastName || !birthDate || !emergName || !emergPhone) {
        return null;
    }

    const practices: PracticeSelection[] = [];
    document.querySelectorAll<HTMLElement>('.practice-selector-row').forEach((row) => {
        const dSel = row.querySelector('.prac-discipline') as HTMLSelectElement | null;
        const gSel = row.querySelector('.prac-grade') as HTMLSelectElement | null;
        const lSel = row.querySelector('.prac-level') as HTMLSelectElement | null;

        if (dSel) {
            practices.push({
                discipline_id: parseInt(dSel.value || '1', 10),
                discipline_name: dSel.options[dSel.selectedIndex]?.text || 'Judo',
                grade_id: gSel && gSel.value ? parseInt(gSel.value, 10) : null,
                grade_name: gSel?.options[gSel.selectedIndex]?.text || '',
                level_id: lSel && lSel.value ? parseInt(lSel.value, 10) : null,
                level_name: lSel?.options[lSel.selectedIndex]?.text || ''
            });
        }
    });

    if (practices.length === 0) {
        practices.push({
            discipline_id: 1,
            discipline_name: 'Judo',
            grade_id: null,
            grade_name: '',
            level_id: null,
            level_name: ''
        });
    }

    return {
        first_name: firstName,
        last_name: lastName,
        birth_date: birthDate,
        email,
        discipline_id: practices[0].discipline_id,
        practices,
        emergency_contact_name: emergName,
        emergency_contact_phone: emergPhone,
        emergency_contact_relation: emergRelation || undefined,
        emergency_contact_secondary_phone: emergPhoneSec || undefined,
        health_attestation_signed: healthSigned
    };
}

function readTutorsInputs(): CartTutor[] {
    const tutors: CartTutor[] = [];
    const t1Email = (document.getElementById('tutor1-email') as HTMLInputElement | null)?.value.trim() || '';
    const t1Birth = (document.getElementById('tutor1-birth') as HTMLInputElement | null)?.value.trim() || '';
    const t1Fn    = (document.getElementById('tutor1-first-name') as HTMLInputElement | null)?.value.trim() || '';
    const t1Ln    = (document.getElementById('tutor1-last-name') as HTMLInputElement | null)?.value.trim() || '';
    const t1Rel   = (document.getElementById('tutor1-relation') as HTMLSelectElement | null)?.value || 'Parent';
    const t1Phone = (document.getElementById('tutor1-phone') as HTMLInputElement | null)?.value.trim() || '';
    const t1Addr  = (document.getElementById('tutor1-address') as HTMLInputElement | null)?.value.trim() || '';
    const t2Email = (document.getElementById('tutor2-email') as HTMLInputElement | null)?.value.trim() || '';

    if (t1Email) {
        tutors.push({
            first_name: t1Fn,
            last_name: t1Ln,
            relationship: t1Rel,
            email: t1Email,
            phone: t1Phone,
            address: t1Addr,
            birth_date: t1Birth || undefined
        });
    }
    if (t2Email) {
        tutors.push({ email: t2Email });
    }
    return tutors;
}

function resetMemberInputsForNextSibling(): void {
    const fn = document.getElementById('reg-first-name') as HTMLInputElement | null;
    const birth = document.getElementById('reg-birth-date') as HTMLInputElement | null;
    const container = document.getElementById('reg-practices-container');
    if (fn) fn.value = '';
    if (birth) birth.value = '';
    if (container) {
        container.innerHTML = '';
        appendPracticeSelectorRow(1);
    }
}

function renderFamilyCart(): void {
    const listEl = document.getElementById('family-cart-list');
    const countEl = document.getElementById('family-cart-count');
    const priceEl = document.getElementById('reg-total-price');
    const card = document.getElementById('registration-wizard-card');
    const basePrice = parseFloat(card?.getAttribute('data-base-price') || '150');
    const discountThreshold = parseInt(card?.getAttribute('data-discount-threshold') || '2', 10);
    const discountType = card?.getAttribute('data-discount-type') || 'percent';
    const discountValue = parseFloat(card?.getAttribute('data-discount-value') || '10');

    const currentDraft = readCurrentMemberInputs();
    const displayMembers = [...familyCart];
    if (currentDraft) {
        displayMembers.push(currentDraft);
    }

    const totalCount = Math.max(1, displayMembers.length);
    if (countEl) {
        countEl.textContent = `Panier : ${totalCount} adhérent(s)`;
    }

    const subTotal = basePrice * totalCount;
    let discountAmount = 0;
    let discountDesc = '';

    if (totalCount >= discountThreshold) {
        if (discountType === 'percent') {
            discountAmount = subTotal * (discountValue / 100);
            discountDesc = `-${discountValue}% dès ${discountThreshold} inscrits`;
        } else {
            discountAmount = discountValue;
            discountDesc = `-${discountValue.toFixed(2)} € dès ${discountThreshold} inscrits`;
        }
    }

    const calculatedTotal = Math.max(0, subTotal - discountAmount);
    if (priceEl) {
        priceEl.textContent = calculatedTotal.toFixed(2);
    }
    const headerPriceEl = document.getElementById('reg-header-total-price');
    if (headerPriceEl) {
        headerPriceEl.textContent = calculatedTotal.toFixed(2);
    }

    const discountBadge = document.getElementById('reg-discount-badge');
    const discountAmtEl = document.getElementById('reg-discount-amount');
    if (discountAmount > 0) {
        if (discountBadge) {
            discountBadge.textContent = `Remise Famille (${discountDesc})`;
            discountBadge.classList.remove('hidden');
        }
        if (discountAmtEl) {
            discountAmtEl.textContent = `-${discountAmount.toFixed(2)} €`;
            discountAmtEl.classList.remove('hidden');
        }
    } else {
        discountBadge?.classList.add('hidden');
        discountAmtEl?.classList.add('hidden');
    }

    if (listEl) {
        listEl.innerHTML = '';
        displayMembers.forEach((m, idx) => {
            const age = calculateAgeFromInput(m.birth_date);
            const pracBadges = m.practices.map((p) => `
                <span class="inline-block text-xs font-bold px-2.5 py-1 rounded-lg bg-emerald-50 text-emerald-800 border border-emerald-200">
                    🥋 ${p.discipline_name} — ${p.grade_name || 'Cours'} (${p.level_name || 'Débutant'})
                </span>
            `).join(' ');

            const li = document.createElement('li');
            li.className = 'p-4 bg-white border border-slate-200 rounded-xl flex flex-col sm:flex-row sm:items-center justify-between gap-2';
            li.innerHTML = `
                <div>
                    <strong class="text-slate-900">Pratiquant #${idx + 1} : ${m.first_name} ${m.last_name}</strong>
                    <span class="text-xs text-slate-500 ml-2">(${m.birth_date} • ${age < 18 ? 'Mineur' : 'Majeur'})</span>
                    <div class="text-xs text-slate-500 mt-0.5">🚨 Urgence : ${m.emergency_contact_name} (${m.emergency_contact_phone})</div>
                </div>
                <div class="flex flex-wrap gap-1.5">${pracBadges}</div>
            `;
            listEl.appendChild(li);
        });
    }
}

function saveDraftToStorage(): void {
    try {
        const formData = {
            first_name: (document.getElementById('reg-first-name') as HTMLInputElement)?.value || '',
            last_name: (document.getElementById('reg-last-name') as HTMLInputElement)?.value || '',
            birth_date: (document.getElementById('reg-birth-date') as HTMLInputElement)?.value || '',
            email: (document.getElementById('reg-email') as HTMLInputElement)?.value || '',
            emerg_name: (document.getElementById('reg-emerg-name') as HTMLInputElement)?.value || '',
            emerg_phone: (document.getElementById('reg-emerg-phone') as HTMLInputElement)?.value || '',
            emerg_relation: (document.getElementById('reg-emerg-relation') as HTMLInputElement)?.value || '',
            emerg_phone_sec: (document.getElementById('reg-emerg-phone-secondary') as HTMLInputElement)?.value || '',
            t1_first_name: (document.getElementById('tutor1-first-name') as HTMLInputElement)?.value || '',
            t1_last_name: (document.getElementById('tutor1-last-name') as HTMLInputElement)?.value || '',
            t1_email: (document.getElementById('tutor1-email') as HTMLInputElement)?.value || '',
            t1_phone: (document.getElementById('tutor1-phone') as HTMLInputElement)?.value || '',
            t1_address: (document.getElementById('tutor1-address') as HTMLInputElement)?.value || '',
            t1_birth: (document.getElementById('tutor1-birth') as HTMLInputElement)?.value || '',
            t2_email: (document.getElementById('tutor2-email') as HTMLInputElement)?.value || ''
        };
        if (formData.first_name || formData.last_name || familyCart.length > 0) {
            localStorage.setItem('kanso_reg_draft', JSON.stringify({ form: formData, familyCart }));
        }
    } catch {
        // Ignorer les erreurs localStorage
    }
}

function checkAndRestoreDraft(): void {
    const banner = document.getElementById('draft-restore-banner');
    const raw = localStorage.getItem('kanso_reg_draft');
    if (!banner || !raw) return;

    try {
        const data = JSON.parse(raw);
        if (data && (data.form?.first_name || data.form?.last_name || (data.familyCart && data.familyCart.length > 0))) {
            banner.classList.remove('hidden');
        }
    } catch {
        localStorage.removeItem('kanso_reg_draft');
    }
}

function restoreDraftFromStorage(): void {
    const raw = localStorage.getItem('kanso_reg_draft');
    if (!raw) return;

    try {
        const data = JSON.parse(raw);
        if (data && data.form) {
            const f = data.form;
            const setVal = (id: string, val: string | undefined) => {
                const el = document.getElementById(id) as HTMLInputElement | null;
                if (el && val !== undefined) el.value = val;
            };

            setVal('reg-first-name', f.first_name);
            setVal('reg-last-name', f.last_name);
            setVal('reg-birth-date', f.birth_date);
            setVal('reg-email', f.email);
            setVal('reg-emerg-name', f.emerg_name);
            setVal('reg-emerg-phone', f.emerg_phone);
            setVal('reg-emerg-relation', f.emerg_relation);
            setVal('reg-emerg-phone-secondary', f.emerg_phone_sec);
            setVal('tutor1-first-name', f.t1_first_name);
            setVal('tutor1-last-name', f.t1_last_name);
            setVal('tutor1-email', f.t1_email);
            setVal('tutor1-phone', f.t1_phone);
            setVal('tutor1-address', f.t1_address);
            setVal('tutor1-birth', f.t1_birth);
            setVal('tutor2-email', f.t2_email);

            if (data.familyCart && Array.isArray(data.familyCart)) {
                familyCart.length = 0;
                familyCart.push(...data.familyCart);
            }

            const birthEl = document.getElementById('reg-birth-date') as HTMLInputElement | null;
            if (birthEl && birthEl.value) {
                birthEl.dispatchEvent(new Event('change'));
            }
            renderFamilyCart();
        }
    } catch {
        // En cas d'erreur de parse
    }
}