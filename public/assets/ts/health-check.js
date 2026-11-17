import { showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    const btnMinor = document.getElementById('btn-tab-minor');
    const btnAdult = document.getElementById('btn-tab-adult');
    const formMinor = document.getElementById('cerfa-minor-form');
    const formAdult = document.getElementById('cerfa-adult-form');
    const resultBanner = document.getElementById('health-result-banner');
    const warningBanner = document.getElementById('health-warning-banner');
    const attestationCb = document.getElementById('health-attestation-checkbox');
    const btnConfirm = document.getElementById('btn-confirm-health-attestation');
    if (!btnMinor || !btnAdult || !formMinor || !formAdult || !resultBanner || !warningBanner) {
        return;
    }
    const setMode = (isMinor) => {
        if (isMinor) {
            btnMinor.className = 'flex-1 py-2 text-xs font-bold rounded-lg transition-colors bg-white text-slate-900 shadow-xs';
            btnAdult.className = 'flex-1 py-2 text-xs font-bold rounded-lg transition-colors text-slate-600 hover:text-slate-900';
            formMinor.classList.remove('hidden');
            formAdult.classList.add('hidden');
        }
        else {
            btnAdult.className = 'flex-1 py-2 text-xs font-bold rounded-lg transition-colors bg-white text-slate-900 shadow-xs';
            btnMinor.className = 'flex-1 py-2 text-xs font-bold rounded-lg transition-colors text-slate-600 hover:text-slate-900';
            formAdult.classList.remove('hidden');
            formMinor.classList.add('hidden');
        }
        evaluateCheckboxes();
    };
    btnMinor.addEventListener('click', () => setMode(true));
    btnAdult.addEventListener('click', () => setMode(false));
    const evaluateCheckboxes = () => {
        const activeContainer = formMinor.classList.contains('hidden') ? formAdult : formMinor;
        const checkedCount = activeContainer.querySelectorAll('input[type="checkbox"]:checked').length;
        if (checkedCount > 0) {
            resultBanner.classList.add('hidden');
            warningBanner.classList.remove('hidden');
            if (attestationCb)
                attestationCb.checked = false;
        }
        else {
            resultBanner.classList.remove('hidden');
            warningBanner.classList.add('hidden');
        }
    };
    document.querySelectorAll('.qs-minor-checkbox, .qs-adult-checkbox').forEach((cb) => {
        cb.addEventListener('change', evaluateCheckboxes);
    });
    btnConfirm?.addEventListener('click', () => {
        const hasWarning = !warningBanner.classList.contains('hidden');
        if (hasWarning) {
            showToast('Un certificat médical de votre médecin est obligatoire.', 'error');
            sessionStorage.setItem('kanso_health_cert_required', '1');
            window.location.href = '/register';
            return;
        }
        if (!attestationCb?.checked) {
            showToast("Veuillez cocher l'attestation sur l'honneur avant de valider.", 'error');
            return;
        }
        sessionStorage.setItem('kanso_health_attestation', '1');
        sessionStorage.setItem('kanso_health_attestation_date', new Date().toISOString());
        // Met à jour le brouillon d'inscription dans localStorage s'il existe
        try {
            const rawDraft = localStorage.getItem('kanso_reg_draft');
            if (rawDraft) {
                const draft = JSON.parse(rawDraft);
                if (draft && draft.form) {
                    draft.form.health_cert_choice = 'attestation';
                    draft.healthAttestation = true;
                    localStorage.setItem('kanso_reg_draft', JSON.stringify(draft));
                }
            }
        }
        catch {
            // Ignorer les erreurs localStorage
        }
        showToast('Attestation de santé validée avec succès !', 'success');
        setTimeout(() => {
            window.location.href = '/register';
        }, 500);
    });
});
