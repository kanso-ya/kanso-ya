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
const familyCart = [];
let disciplinesCatalog = [];
let isMinorFlow = false;
let connectedUserProfile = null;
let currentStep = 'identity';
let goToStepRef = null;
function navigateWizardToStep(step) {
    currentStep = step;
    if (goToStepRef) {
        goToStepRef(step);
    }
}
document.addEventListener('DOMContentLoaded', async () => {
    initPublicRegistrationWizard();
    await loadDisciplinesCatalog();
    checkAndRestoreDraft();
    checkConnectedUserForReEnrollment();
    window.addEventListener('kanso:auth-ready', () => {
        checkConnectedUserForReEnrollment();
    });
});
function getUrlDisciplineId() {
    try {
        const params = new URLSearchParams(window.location.search);
        const raw = params.get('discipline');
        if (!raw)
            return null;
        const parsed = parseInt(raw, 10);
        if (Number.isNaN(parsed) || parsed <= 0)
            return null;
        return parsed;
    }
    catch {
        return null;
    }
}
export function calculateAgeFromInput(birthDateStr) {
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
/**
 * Charge le référentiel des disciplines, grades d'âge et niveaux techniques depuis l'API.
 * N'impose aucune discipline par défaut sauf si ?discipline=ID est présent dans l'URL.
 */
async function loadDisciplinesCatalog() {
    const res = await fetchApi('/api/v1/disciplines');
    if (res.status === 'success' && Array.isArray(res.data)) {
        disciplinesCatalog = res.data;
    }
    const urlDiscId = getUrlDisciplineId();
    const matchedUrlDisc = urlDiscId && disciplinesCatalog.some((d) => Number(d.id) === urlDiscId)
        ? urlDiscId
        : null;
    const container = document.getElementById('reg-practices-container');
    if (container && container.children.length === 0) {
        appendPracticeSelectorRow(matchedUrlDisc);
    }
}
/**
 * Ajoute une ligne permettant de choisir : Discipline + Grade (pré-rempli selon l'âge) + Niveau technique.
 * Sans initialDiscId, affiche un choix neutre "Sélectionnez une discipline...".
 */
function appendPracticeSelectorRow(initialDiscId = null, initialGradeId, initialLevelId) {
    const container = document.getElementById('reg-practices-container');
    if (!container)
        return;
    const birthInput = document.getElementById('reg-birth-date');
    const currentAge = birthInput?.value ? calculateAgeFromInput(birthInput.value) : 25;
    const row = document.createElement('div');
    row.className = 'practice-selector-row p-4 rounded-xl bg-slate-50 border border-slate-200 grid grid-cols-1 md:grid-cols-12 gap-3 items-end';
    const placeholderOpt = `<option value="" ${!initialDiscId ? 'selected' : ''}>Sélectionnez une discipline...</option>`;
    const discOptions = disciplinesCatalog.length > 0
        ? placeholderOpt + disciplinesCatalog.map((d) => `<option value="${d.id}" ${initialDiscId && Number(d.id) === Number(initialDiscId) ? 'selected' : ''}>${d.name}</option>`).join('')
        : `${placeholderOpt}<option value="1" ${initialDiscId === 1 ? 'selected' : ''}>Judo</option><option value="2" ${initialDiscId === 2 ? 'selected' : ''}>Karaté</option><option value="3" ${initialDiscId === 3 ? 'selected' : ''}>Aïkido</option>`;
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
    const discSelect = row.querySelector('.prac-discipline');
    const gradeSelect = row.querySelector('.prac-grade');
    const levelSelect = row.querySelector('.prac-level');
    const populateGradesAndLevels = (discId, ageForAutoGrade, forceGradeId, forceLevelId) => {
        if (!discId || Number.isNaN(discId)) {
            gradeSelect.innerHTML = `<option value="">Choisissez d'abord une discipline...</option>`;
            levelSelect.innerHTML = `<option value="">Choisissez d'abord une discipline...</option>`;
            return;
        }
        const disc = disciplinesCatalog.find((d) => Number(d.id) === Number(discId));
        const allLevels = disc?.levels || [];
        const grades = allLevels.filter((l) => (l.level_type || 'grade') === 'grade');
        const niveaux = allLevels.filter((l) => l.level_type === 'niveau');
        // 1. Peuplement des Grades (Catégories d'âge) avec pré-sélection automatique selon l'âge
        gradeSelect.innerHTML = '';
        if (grades.length === 0) {
            gradeSelect.innerHTML = `<option value="">Cours général (Tous âges)</option>`;
        }
        else {
            let matchedGradeId = forceGradeId || null;
            if (!matchedGradeId) {
                const autoMatch = grades.find((g) => (g.age_min === null || ageForAutoGrade >= g.age_min) && (g.age_max === null || ageForAutoGrade <= g.age_max));
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
        }
        else {
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
        const firstDiscSelect = document.querySelector('.prac-discipline');
        const hiddenDisc = document.getElementById('reg-discipline');
        if (firstDiscSelect && hiddenDisc && firstDiscSelect.value) {
            hiddenDisc.value = firstDiscSelect.value;
        }
    };
    populateGradesAndLevels(initialDiscId, currentAge, initialGradeId, initialLevelId);
    discSelect.addEventListener('change', () => {
        const ageNow = birthInput?.value ? calculateAgeFromInput(birthInput.value) : 25;
        const parsedId = discSelect.value ? parseInt(discSelect.value, 10) : null;
        populateGradesAndLevels(parsedId, ageNow);
    });
    row.querySelector('.btn-remove-practice')?.addEventListener('click', () => {
        if (container.querySelectorAll('.practice-selector-row').length > 1) {
            row.remove();
        }
        else {
            showToast('Au moins une discipline doit être sélectionnée.', 'info');
        }
    });
    container.appendChild(row);
}
/**
 * Met à jour automatiquement la pré-sélection du Grade (catégorie d'âge) sur toutes les lignes de disciplines
 * lorsque l'utilisateur saisit ou modifie la date de naissance.
 */
function autoSelectGradesForBirthDate(age) {
    document.querySelectorAll('.practice-selector-row').forEach((row) => {
        const discSelect = row.querySelector('.prac-discipline');
        const gradeSelect = row.querySelector('.prac-grade');
        if (!discSelect || !gradeSelect || !discSelect.value)
            return;
        const disc = disciplinesCatalog.find((d) => Number(d.id) === parseInt(discSelect.value, 10));
        const grades = (disc?.levels || []).filter((l) => (l.level_type || 'grade') === 'grade');
        const autoMatch = grades.find((g) => (g.age_min === null || age >= g.age_min) && (g.age_max === null || age <= g.age_max));
        if (autoMatch) {
            gradeSelect.value = String(autoMatch.id);
        }
    });
}
async function checkConnectedUserForReEnrollment() {
    if (!currentAuthContext.user_id || currentAuthContext.role === 'Visiteur')
        return;
    const banner = document.getElementById('connected-re-enrollment-banner');
    const select = document.getElementById('select-existing-family-member');
    if (!banner || !select || select.dataset.loaded === '1')
        return;
    const res = await fetchApi('/api/v1/users/me/profile');
    if (res.status !== 'success' || !res.data)
        return;
    connectedUserProfile = res.data;
    select.dataset.loaded = '1';
    banner.classList.remove('hidden');
    const linked = res.data.linked_members || [];
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
        const fnInput = document.getElementById('reg-first-name');
        const lnInput = document.getElementById('reg-last-name');
        const bdInput = document.getElementById('reg-birth-date');
        const emInput = document.getElementById('reg-email');
        const emergName = document.getElementById('reg-emerg-name');
        const emergPhone = document.getElementById('reg-emerg-phone');
        const emergRel = document.getElementById('reg-emerg-relation');
        const emergSec = document.getElementById('reg-emerg-phone-secondary');
        const prefillBanner = document.getElementById('reg-reenroll-prefilled-banner');
        const prefillText = document.getElementById('reg-reenroll-prefilled-text');
        const practicesContainer = document.getElementById('reg-practices-container');
        const populatePracticesForReEnroll = (practicesList) => {
            if (!practicesContainer)
                return;
            practicesContainer.innerHTML = '';
            if (Array.isArray(practicesList) && practicesList.length > 0) {
                practicesList.forEach((p) => {
                    appendPracticeSelectorRow(p.discipline_id ? Number(p.discipline_id) : null, p.grade_id ? Number(p.grade_id) : null, p.level_id ? Number(p.level_id) : null);
                });
            }
            else {
                appendPracticeSelectorRow(null);
            }
        };
        if (val === 'self') {
            const selfM = res.data.self_member || null;
            fnInput.value = selfM?.first_name || res.data.first_name || '';
            lnInput.value = selfM?.last_name || res.data.last_name || '';
            bdInput.value = selfM?.birth_date || '1988-05-15';
            emInput.value = selfM?.email || res.data.email || '';
            if (selfM?.emergency_contact_name)
                emergName.value = selfM.emergency_contact_name;
            if (selfM?.emergency_contact_phone)
                emergPhone.value = selfM.emergency_contact_phone;
            if (emergRel && selfM?.emergency_contact_relation)
                emergRel.value = selfM.emergency_contact_relation;
            if (emergSec && selfM?.emergency_contact_secondary_phone)
                emergSec.value = selfM.emergency_contact_secondary_phone;
            bdInput.dispatchEvent(new Event('change'));
            if (selfM?.practices && selfM.practices.length > 0) {
                populatePracticesForReEnroll(selfM.practices);
            }
            if (prefillBanner) {
                prefillBanner.classList.remove('hidden');
                if (prefillText) {
                    prefillText.textContent = `Vos informations personnelles et sportives (${fnInput.value} ${lnInput.value}) ont été pré-remplies.`;
                }
            }
            saveDraftToStorage();
            showToast('Vos informations de réinscription ont été pré-remplies.', 'info');
        }
        else if (val !== '') {
            const m = linked[parseInt(val, 10)];
            if (m) {
                fnInput.value = m.first_name || '';
                lnInput.value = m.last_name || '';
                bdInput.value = m.birth_date || '';
                if (m.email)
                    emInput.value = m.email;
                // Contact d'urgence complet
                emergName.value = m.emergency_contact_name || `${res.data.first_name || ''} ${res.data.last_name || ''}`.trim();
                emergPhone.value = m.emergency_contact_phone || res.data.phone || '';
                if (emergRel)
                    emergRel.value = m.emergency_contact_relation || '';
                if (emergSec)
                    emergSec.value = m.emergency_contact_secondary_phone || '';
                // Tuteurs légaux complets (Tuteur 1 + Co-tuteur 2 si présent/partagé)
                const tutors = Array.isArray(m.tutors) ? m.tutors : [];
                const t1 = tutors[0] || res.data;
                const setVal = (id, v) => {
                    const el = document.getElementById(id);
                    if (el)
                        el.value = v;
                };
                setVal('tutor1-first-name', t1.first_name || res.data.first_name || '');
                setVal('tutor1-last-name', t1.last_name || res.data.last_name || '');
                setVal('tutor1-email', t1.email || res.data.email || '');
                setVal('tutor1-phone', t1.phone || res.data.phone || '');
                setVal('tutor1-address', t1.address || res.data.address || '');
                if (t1.relationship) {
                    const relSel = document.getElementById('tutor1-relation');
                    if (relSel) {
                        const relLower = String(t1.relationship).toLowerCase();
                        if (relLower.includes('père') || relLower.includes('pere'))
                            relSel.value = 'Père';
                        else if (relLower.includes('mère') || relLower.includes('mere'))
                            relSel.value = 'Mère';
                        else
                            relSel.value = 'Tuteur légal';
                    }
                }
                const t2 = tutors[1] || null;
                if (t2) {
                    const t2FullName = `${t2.first_name || ''} ${t2.last_name || ''}`.trim();
                    setVal('tutor2-name', t2FullName);
                    setVal('tutor2-email', t2.email || '');
                    setVal('tutor2-phone', t2.phone || '');
                }
                else {
                    setVal('tutor2-name', '');
                    setVal('tutor2-email', '');
                    setVal('tutor2-phone', '');
                }
                // Déclenche d'abord la détection mineur/majeur, puis restaure exactement les disciplines/grades/ceintures
                bdInput.dispatchEvent(new Event('change'));
                populatePracticesForReEnroll(m.practices || []);
                if (prefillBanner) {
                    prefillBanner.classList.remove('hidden');
                    if (prefillText) {
                        prefillText.textContent = `Fiche de ${m.first_name} ${m.last_name} pré-remplie (identité, disciplines, grade, niveau technique, contact d'urgence et responsables légaux).`;
                    }
                }
                saveDraftToStorage();
                showToast(`Fiche complète de ${m.first_name} pré-remplie pour la réinscription !`, 'success');
            }
        }
    });
}
function initPublicRegistrationWizard() {
    const birthInput = document.getElementById('reg-birth-date');
    const tutorSection = document.getElementById('tutor-section');
    const stepPanelTutors = document.getElementById('step-panel-tutors');
    const breadcrumb = document.getElementById('registration-breadcrumb');
    const breadcrumbTutors = document.getElementById('breadcrumb-tutors');
    const ageBadge = document.getElementById('age-indicator-badge');
    const adultEmailWrapper = document.getElementById('adult-email-wrapper');
    const minorEmailNotice = document.getElementById('minor-email-notice');
    const addPracticeBtn = document.getElementById('btn-add-practice-row');
    const form = document.getElementById('public-registration-form');
    if (!form || !birthInput || !tutorSection)
        return;
    addPracticeBtn?.addEventListener('click', () => {
        appendPracticeSelectorRow(null);
    });
    const updateBreadcrumbLayout = (requiresTutors) => {
        if (!breadcrumb || !breadcrumbTutors)
            return;
        const bcEmergNum = document.querySelector('#bc-step-emergency .bc-num');
        const bcPayNum = document.querySelector('#bc-step-payment .bc-num');
        if (requiresTutors) {
            breadcrumb.className = 'grid grid-cols-2 sm:grid-cols-4 gap-2 mb-8';
            breadcrumbTutors.classList.remove('hidden');
            if (bcEmergNum)
                bcEmergNum.textContent = '3';
            if (bcPayNum)
                bcPayNum.textContent = '4';
        }
        else {
            breadcrumb.className = 'grid grid-cols-3 gap-2 mb-8';
            breadcrumbTutors.classList.add('hidden');
            if (bcEmergNum)
                bcEmergNum.textContent = '2';
            if (bcPayNum)
                bcPayNum.textContent = '3';
        }
    };
    const goToStep = (step) => {
        currentStep = step;
        document.getElementById('step-panel-identity')?.classList.toggle('hidden', step !== 'identity');
        stepPanelTutors?.classList.toggle('hidden', step !== 'tutors');
        document.getElementById('step-panel-emergency')?.classList.toggle('hidden', step !== 'emergency');
        document.getElementById('step-panel-payment')?.classList.toggle('hidden', step !== 'payment');
        if (step === 'tutors') {
            tutorSection.classList.remove('hidden');
        }
        document.querySelectorAll('#registration-breadcrumb .bc-item').forEach((item) => {
            const target = item.getAttribute('data-target');
            if (target === step) {
                item.className = 'bc-item cursor-pointer p-3 rounded-xl border-2 border-emerald-600 bg-emerald-50/70 text-emerald-900 transition';
            }
            else {
                item.className = `bc-item cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition ${target === 'tutors' && !isMinorFlow ? 'hidden' : ''}`;
            }
        });
        if (step === 'payment') {
            renderFamilyCart();
        }
        saveDraftToStorage();
    };
    goToStepRef = goToStep;
    birthInput.addEventListener('change', () => {
        const age = calculateAgeFromInput(birthInput.value);
        const cartHasMinor = familyCart.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        isMinorFlow = age < 18 || cartHasMinor;
        // Pré-sélection automatique du Grade (catégorie d'âge) selon l'âge calculé
        autoSelectGradesForBirthDate(age);
        const memberEmailInput = document.getElementById('reg-email');
        const tutor1EmailInput = document.getElementById('tutor1-email');
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
        }
        else {
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
    const payMethodSelect = document.getElementById('reg-payment-method');
    const installmentsSelect = document.getElementById('reg-installments');
    const installmentHint = document.getElementById('installment-restriction-hint');
    payMethodSelect?.addEventListener('change', () => {
        if (!installmentsSelect)
            return;
        const isCheque = payMethodSelect.value === 'cheque';
        installmentsSelect.querySelectorAll('.opt-multi-installment').forEach((opt) => {
            opt.disabled = !isCheque;
        });
        if (!isCheque) {
            installmentsSelect.value = '1';
            installmentHint?.classList.remove('hidden');
        }
        else {
            installmentHint?.classList.add('hidden');
        }
    });
    document.getElementById('btn-next-from-identity')?.addEventListener('click', () => {
        const fn = document.getElementById('reg-first-name').value.trim();
        const ln = document.getElementById('reg-last-name').value.trim();
        const bd = birthInput.value.trim();
        const em = document.getElementById('reg-email')?.value.trim() || '';
        if (!fn || !ln || !bd) {
            showToast('Veuillez renseigner le prénom, le nom et la date de naissance.', 'error');
            return;
        }
        const age = calculateAgeFromInput(bd);
        if (age < 0 || age > 120) {
            showToast('Veuillez saisir une date de naissance valide.', 'error');
            return;
        }
        if (age >= 18 && em !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(em)) {
            showToast('Veuillez saisir une adresse e-mail valide.', 'error');
            return;
        }
        const selectedDisciplines = Array.from(document.querySelectorAll('.prac-discipline')).filter((sel) => sel.value.trim() !== '');
        if (selectedDisciplines.length === 0) {
            showToast('Veuillez sélectionner au moins une discipline avant de continuer.', 'error');
            return;
        }
        isMinorFlow = age < 18 || familyCart.some((m) => calculateAgeFromInput(m.birth_date) < 18);
        updateBreadcrumbLayout(isMinorFlow);
        goToStep(isMinorFlow ? 'tutors' : 'emergency');
    });
    document.getElementById('btn-next-from-tutors')?.addEventListener('click', () => {
        const t1Email = document.getElementById('tutor1-email').value.trim();
        const t1Birth = document.getElementById('tutor1-birth').value.trim();
        const t2Email = document.getElementById('tutor2-email')?.value.trim() || '';
        if (!t1Email) {
            showToast('L\'adresse e-mail du Responsable Légal 1 est obligatoire pour inscrire un mineur.', 'error');
            return;
        }
        if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t1Email)) {
            showToast('L\'adresse e-mail du Responsable Légal 1 n\'est pas valide.', 'error');
            return;
        }
        if (t2Email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(t2Email)) {
            showToast('L\'adresse e-mail du Responsable Légal 2 n\'est pas valide.', 'error');
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
        const eName = document.getElementById('reg-emerg-name').value.trim();
        const ePhone = document.getElementById('reg-emerg-phone').value.trim();
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
    const acceptRulesCb = document.getElementById('modal-accept-rules');
    document.getElementById('btn-open-confirm-modal')?.addEventListener('click', () => {
        const currentMember = readCurrentMemberInputs();
        const allMembers = [...familyCart];
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
            const methodSelect = document.getElementById('reg-payment-method');
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
        if (acceptRulesCb)
            acceptRulesCb.checked = false;
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
    // Gestion du brouillon (Points 8 & 9)
    form.addEventListener('input', () => saveDraftToStorage());
    form.addEventListener('change', () => saveDraftToStorage());
    // Sauvegarde avant d'ouvrir le questionnaire de santé
    document.querySelectorAll('a[href*="questionnaire-sante"]').forEach((link) => {
        link.addEventListener('click', () => {
            saveDraftToStorage();
        });
    });
    document.getElementById('btn-restore-draft')?.addEventListener('click', () => {
        restoreDraftFromStorage(false);
        document.getElementById('draft-restore-banner')?.classList.add('hidden');
        showToast('Votre brouillon d\'inscription a été restauré.', 'success');
    });
    document.getElementById('btn-clear-draft')?.addEventListener('click', () => {
        localStorage.removeItem('kanso_reg_draft');
        sessionStorage.removeItem('kanso_health_attestation');
        sessionStorage.removeItem('kanso_health_attestation_date');
        document.getElementById('draft-restore-banner')?.classList.add('hidden');
        form.reset();
        familyCart.length = 0;
        renderFamilyCart();
        navigateWizardToStep('identity');
        showToast('Brouillon effacé.', 'info');
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const currentMember = readCurrentMemberInputs();
        const allMembers = [...familyCart];
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
        const submitBtn = document.getElementById('btn-submit-registration');
        setButtonLoading(submitBtn, true, 'Valider définitivement mon dossier d\'inscription');
        try {
            const response = await fetchApi('/api/v1/registrations', {
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
                if (regIdSpan)
                    regIdSpan.textContent = String(response.data.registration_id);
                if (amountSpan)
                    amountSpan.textContent = Number(response.data.total_amount).toFixed(2);
                successScreen?.classList.remove('hidden');
                familyCart.length = 0;
                form.reset();
            }
        }
        finally {
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
function readCurrentMemberInputs() {
    const firstName = document.getElementById('reg-first-name')?.value.trim() || '';
    const lastName = document.getElementById('reg-last-name')?.value.trim() || '';
    const birthDate = document.getElementById('reg-birth-date')?.value.trim() || '';
    const gender = document.getElementById('reg-member-gender')?.value.trim() || '';
    const email = document.getElementById('reg-email')?.value.trim() || '';
    const emergName = document.getElementById('reg-emerg-name')?.value.trim() || '';
    const emergPhone = document.getElementById('reg-emerg-phone')?.value.trim() || '';
    const emergRelation = document.getElementById('reg-emerg-relation')?.value.trim() || '';
    const emergPhoneSec = document.getElementById('reg-emerg-phone-secondary')?.value.trim() || '';
    const healthSigned = document.getElementById('choice-health-attestation')?.checked ? 1 : 0;
    if (!firstName || !lastName || !birthDate || !emergName || !emergPhone) {
        return null;
    }
    const practices = [];
    document.querySelectorAll('.practice-selector-row').forEach((row) => {
        const dSel = row.querySelector('.prac-discipline');
        const gSel = row.querySelector('.prac-grade');
        const lSel = row.querySelector('.prac-level');
        if (dSel && dSel.value.trim() !== '') {
            practices.push({
                discipline_id: parseInt(dSel.value, 10),
                discipline_name: dSel.options[dSel.selectedIndex]?.text || '',
                grade_id: gSel && gSel.value ? parseInt(gSel.value, 10) : null,
                grade_name: gSel?.options[gSel.selectedIndex]?.text || '',
                level_id: lSel && lSel.value ? parseInt(lSel.value, 10) : null,
                level_name: lSel?.options[lSel.selectedIndex]?.text || ''
            });
        }
    });
    if (practices.length === 0) {
        return null;
    }
    return {
        first_name: firstName,
        last_name: lastName,
        birth_date: birthDate,
        gender: gender || undefined,
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
function readTutorsInputs() {
    const tutors = [];
    const t1Email = document.getElementById('tutor1-email')?.value.trim() || '';
    const t1Birth = document.getElementById('tutor1-birth')?.value.trim() || '';
    const t1Fn = document.getElementById('tutor1-first-name')?.value.trim() || '';
    const t1Ln = document.getElementById('tutor1-last-name')?.value.trim() || '';
    const t1Rel = document.getElementById('tutor1-relation')?.value || 'Parent';
    const t1Phone = document.getElementById('tutor1-phone')?.value.trim() || '';
    const t1Addr = document.getElementById('tutor1-address')?.value.trim() || '';
    const t2Email = document.getElementById('tutor2-email')?.value.trim() || '';
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
function resetMemberInputsForNextSibling() {
    const fn = document.getElementById('reg-first-name');
    const birth = document.getElementById('reg-birth-date');
    const genderEl = document.getElementById('reg-member-gender');
    const container = document.getElementById('reg-practices-container');
    if (fn)
        fn.value = '';
    if (birth)
        birth.value = '';
    if (genderEl)
        genderEl.value = '';
    if (container) {
        container.innerHTML = '';
        appendPracticeSelectorRow(null);
    }
}
function renderFamilyCart() {
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
        }
        else {
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
    }
    else {
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
function getFirstIncompleteStep() {
    const fn = document.getElementById('reg-first-name')?.value.trim();
    const ln = document.getElementById('reg-last-name')?.value.trim();
    const bd = document.getElementById('reg-birth-date')?.value.trim();
    if (!fn || !ln || !bd)
        return 'identity';
    const age = calculateAgeFromInput(bd);
    if (age >= 18) {
        const em = document.getElementById('reg-email')?.value.trim();
        if (!em)
            return 'identity';
    }
    const hasMinor = age < 18 || familyCart.some((m) => calculateAgeFromInput(m.birth_date) < 18);
    if (hasMinor) {
        const t1Email = document.getElementById('tutor1-email')?.value.trim();
        if (!t1Email)
            return 'tutors';
    }
    const emergName = document.getElementById('reg-emerg-name')?.value.trim();
    const emergPhone = document.getElementById('reg-emerg-phone')?.value.trim();
    if (!emergName || !emergPhone)
        return 'emergency';
    return 'payment';
}
function saveDraftToStorage() {
    try {
        const practices = [];
        document.querySelectorAll('.practice-selector-row').forEach((row) => {
            const discSelect = row.querySelector('.prac-discipline');
            const gradeSelect = row.querySelector('.prac-grade');
            const levelSelect = row.querySelector('.prac-level');
            if (discSelect && discSelect.value) {
                practices.push({
                    discipline_id: parseInt(discSelect.value, 10),
                    grade_id: gradeSelect?.value ? parseInt(gradeSelect.value, 10) : null,
                    level_id: levelSelect?.value ? parseInt(levelSelect.value, 10) : null
                });
            }
        });
        const payMethod = document.getElementById('reg-payment-method')?.value || 'cheque';
        const installments = document.getElementById('reg-installments')?.value || '1';
        const healthCertChoice = document.getElementById('choice-health-cert')?.checked ? 'medical_cert' : 'attestation';
        const formData = {
            first_name: document.getElementById('reg-first-name')?.value || '',
            last_name: document.getElementById('reg-last-name')?.value || '',
            birth_date: document.getElementById('reg-birth-date')?.value || '',
            email: document.getElementById('reg-email')?.value || '',
            emerg_name: document.getElementById('reg-emerg-name')?.value || '',
            emerg_phone: document.getElementById('reg-emerg-phone')?.value || '',
            emerg_relation: document.getElementById('reg-emerg-relation')?.value || '',
            emerg_phone_sec: document.getElementById('reg-emerg-phone-secondary')?.value || '',
            t1_first_name: document.getElementById('tutor1-first-name')?.value || '',
            t1_last_name: document.getElementById('tutor1-last-name')?.value || '',
            t1_email: document.getElementById('tutor1-email')?.value || '',
            t1_phone: document.getElementById('tutor1-phone')?.value || '',
            t1_address: document.getElementById('tutor1-address')?.value || '',
            t1_birth: document.getElementById('tutor1-birth')?.value || '',
            t2_email: document.getElementById('tutor2-email')?.value || '',
            practices,
            payment_method: payMethod,
            installments,
            health_cert_choice: healthCertChoice
        };
        if (formData.first_name || formData.last_name || formData.emerg_name || formData.t1_email || familyCart.length > 0) {
            localStorage.setItem('kanso_reg_draft', JSON.stringify({ form: formData, familyCart, currentStep }));
        }
    }
    catch {
        // Ignorer les erreurs localStorage
    }
}
let hasDraftBeenChecked = false;
function checkAndRestoreDraft() {
    if (hasDraftBeenChecked)
        return;
    hasDraftBeenChecked = true;
    const raw = localStorage.getItem('kanso_reg_draft');
    if (!raw)
        return;
    try {
        const data = JSON.parse(raw);
        if (data && (data.form?.first_name || data.form?.last_name || data.form?.emerg_name || (data.familyCart && data.familyCart.length > 0))) {
            restoreDraftFromStorage(true);
        }
    }
    catch {
        localStorage.removeItem('kanso_reg_draft');
    }
}
function restoreDraftFromStorage(isAuto = false) {
    const raw = localStorage.getItem('kanso_reg_draft');
    if (!raw)
        return;
    try {
        const data = JSON.parse(raw);
        if (data && data.form) {
            const f = data.form;
            const setVal = (id, val) => {
                const el = document.getElementById(id);
                if (el && val !== undefined)
                    el.value = val;
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
            if (f.payment_method)
                setVal('reg-payment-method', f.payment_method);
            if (f.installments)
                setVal('reg-installments', f.installments);
            if (f.health_cert_choice === 'medical_cert') {
                const mc = document.getElementById('choice-health-cert');
                if (mc)
                    mc.checked = true;
            }
            else {
                const att = document.getElementById('choice-health-attestation');
                if (att)
                    att.checked = true;
            }
            // Retour depuis questionnaire santé ?
            if (sessionStorage.getItem('kanso_health_attestation') === '1') {
                const att = document.getElementById('choice-health-attestation');
                if (att)
                    att.checked = true;
            }
            if (data.familyCart && Array.isArray(data.familyCart)) {
                familyCart.length = 0;
                familyCart.push(...data.familyCart);
            }
            // Restauration des pratiques sélectionnées (sauf si un paramètre explicite ?discipline=ID est dans l'URL)
            const urlDiscId = getUrlDisciplineId();
            if (!urlDiscId && f.practices && Array.isArray(f.practices) && f.practices.length > 0) {
                const pContainer = document.getElementById('reg-practices-container');
                if (pContainer) {
                    pContainer.innerHTML = '';
                    f.practices.forEach((p) => {
                        appendPracticeSelectorRow(p.discipline_id, p.grade_id, p.level_id);
                    });
                }
            }
            const birthEl = document.getElementById('reg-birth-date');
            if (birthEl && birthEl.value) {
                birthEl.dispatchEvent(new Event('change'));
            }
            renderFamilyCart();
            // Positionne le wizard sur l'étape enregistrée ou la première étape incomplète
            const nextStep = (data.currentStep && ['identity', 'tutors', 'emergency', 'payment'].includes(data.currentStep))
                ? data.currentStep
                : getFirstIncompleteStep();
            navigateWizardToStep(nextStep);
            const banner = document.getElementById('draft-restore-banner');
            if (banner) {
                banner.classList.remove('hidden');
                const titleEl = banner.querySelector('span.block');
                if (titleEl) {
                    titleEl.textContent = `Brouillon restauré automatiquement (reprise à l'étape : ${nextStep})`;
                }
            }
        }
    }
    catch {
        // En cas d'erreur de parse
    }
}
