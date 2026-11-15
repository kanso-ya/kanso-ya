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
document.addEventListener('DOMContentLoaded', async () => {
    await loadDisciplinesCatalog();
    initPublicRegistrationWizard();
    checkConnectedUserForReEnrollment();
    window.addEventListener('kanso:auth-ready', () => {
        checkConnectedUserForReEnrollment();
    });
});
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
 */
async function loadDisciplinesCatalog() {
    const res = await fetchApi('/api/v1/disciplines');
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
function appendPracticeSelectorRow(initialDiscId = 1, initialGradeId, initialLevelId) {
    const container = document.getElementById('reg-practices-container');
    if (!container)
        return;
    const birthInput = document.getElementById('reg-birth-date');
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
    const discSelect = row.querySelector('.prac-discipline');
    const gradeSelect = row.querySelector('.prac-grade');
    const levelSelect = row.querySelector('.prac-level');
    const populateGradesAndLevels = (discId, ageForAutoGrade, forceGradeId, forceLevelId) => {
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
        if (!discSelect || !gradeSelect)
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
        if (val === 'self') {
            fnInput.value = res.data.first_name || '';
            lnInput.value = res.data.last_name || '';
            bdInput.value = '1988-05-15';
            emInput.value = res.data.email || '';
            bdInput.dispatchEvent(new Event('change'));
            showToast('Vos informations ont été pré-remplies.', 'info');
        }
        else if (val !== '') {
            const m = linked[parseInt(val, 10)];
            if (m) {
                fnInput.value = m.first_name || '';
                lnInput.value = m.last_name || '';
                bdInput.value = m.birth_date || '';
                emergName.value = m.emergency_contact_name || `${res.data.first_name || ''} ${res.data.last_name || ''}`.trim();
                emergPhone.value = m.emergency_contact_phone || res.data.phone || '';
                document.getElementById('tutor1-first-name').value = res.data.first_name || '';
                document.getElementById('tutor1-last-name').value = res.data.last_name || '';
                document.getElementById('tutor1-email').value = res.data.email || '';
                document.getElementById('tutor1-phone').value = res.data.phone || '';
                document.getElementById('tutor1-address').value = res.data.address || '';
                bdInput.dispatchEvent(new Event('change'));
                showToast(`Fiche de ${m.first_name} pré-remplie (Grade ajusté selon son âge) !`, 'success');
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
        appendPracticeSelectorRow(2);
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
    };
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
        const t1Email = document.getElementById('tutor1-email').value.trim();
        const t1Birth = document.getElementById('tutor1-birth').value.trim();
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
        showToast(`${currentMember.first_name} a été ajouté au panier familial !`, 'success');
        goToStep('identity');
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
    const email = document.getElementById('reg-email')?.value.trim() || '';
    const emergName = document.getElementById('reg-emerg-name')?.value.trim() || '';
    const emergPhone = document.getElementById('reg-emerg-phone')?.value.trim() || '';
    if (!firstName || !lastName || !birthDate || !emergName || !emergPhone) {
        return null;
    }
    const practices = [];
    document.querySelectorAll('.practice-selector-row').forEach((row) => {
        const dSel = row.querySelector('.prac-discipline');
        const gSel = row.querySelector('.prac-grade');
        const lSel = row.querySelector('.prac-level');
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
        emergency_contact_phone: emergPhone
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
    const container = document.getElementById('reg-practices-container');
    if (fn)
        fn.value = '';
    if (birth)
        birth.value = '';
    if (container) {
        container.innerHTML = '';
        appendPracticeSelectorRow(1);
    }
}
function renderFamilyCart() {
    const listEl = document.getElementById('family-cart-list');
    const countEl = document.getElementById('family-cart-count');
    const priceEl = document.getElementById('reg-total-price');
    const card = document.getElementById('registration-wizard-card');
    const basePrice = parseFloat(card?.getAttribute('data-base-price') || '150');
    const currentDraft = readCurrentMemberInputs();
    const displayMembers = [...familyCart];
    if (currentDraft) {
        displayMembers.push(currentDraft);
    }
    const totalCount = Math.max(1, displayMembers.length);
    if (countEl) {
        countEl.textContent = `Panier : ${totalCount} adhérent(s)`;
    }
    const calculatedTotal = totalCount === 1 ? basePrice : (basePrice * totalCount) * 0.9;
    if (priceEl) {
        priceEl.textContent = calculatedTotal.toFixed(2);
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
