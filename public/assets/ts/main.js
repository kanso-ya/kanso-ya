/**
 * @file main.ts
 * @description Orchestrateur Frontend du CMS Kanso-ya (Lots 1 & 2).
 * Implémente :
 * - La synchronisation dynamique de l'UI via GET /api/v1/auth/me (US 2.1 CA3)
 * - Le tiroir mobile avec backdrop (Section 2.2)
 * - La recherche instantanée 0ms sur les Data Tables (Section 4.1)
 * - Les boutons avec état de chargement et spinner CSS (Section 4.2)
 * - Les Toasts non-intrusifs (Section 4.3)
 * - La Modale de confirmation destructrice bg-slate-900/60 + bouton bg-red-600 (Section 4.4)
 */
/** Contexte utilisateur courant accessible par tous les scripts TS */
export let currentAuthContext = {
    user_id: 1,
    role: 'Président',
    permissions: []
};
document.addEventListener('DOMContentLoaded', async () => {
    initMobileMenu();
    initMemberSearch();
    initLogoutButtons();
    initPersonaSwitcher();
    initQuickAddMemberModal();
    await syncUserContextAndPermissions();
});
/**
 * Interroge GET /api/v1/auth/me pour adapter dynamiquement la Sidebar,
 * le bouton Connexion/Déconnexion, le thème Superadmin et les permissions UI (US 2.1 CA3).
 *
 * @returns {Promise<AuthContext>}
 */
export async function syncUserContextAndPermissions() {
    try {
        const res = await fetch('/api/v1/auth/me');
        const json = await res.json();
        if (json.status === 'success' && json.data) {
            currentAuthContext = json.data;
        }
    }
    catch {
        // Fallback silencieux
    }
    const { role, permissions, user_id } = currentAuthContext;
    const isVisitor = !role || role === 'Visiteur' || user_id === 0;
    const isSuperadmin = role === 'Superadmin';
    // 1. Mise à jour du badge de rôle dans le Header
    const rolePill = document.getElementById('active-role-pill');
    if (rolePill) {
        rolePill.textContent = isVisitor ? 'Visiteur Public' : `Rôle : ${role}`;
        rolePill.classList.remove('hidden');
        if (isSuperadmin) {
            rolePill.className = 'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-bold bg-red-950 text-red-400 border border-red-700';
        }
    }
    // 2. Synchronisation du sélecteur de compte
    const switcher = document.getElementById('persona-switcher');
    if (switcher) {
        if (isVisitor)
            switcher.value = 'visitor';
        else if (isSuperadmin)
            switcher.value = 'superadmin';
        else if (role === 'Président')
            switcher.value = 'president';
        else if (role === 'Trésorier')
            switcher.value = 'tresorier';
        else if (role === 'Rédacteur')
            switcher.value = 'redacteur';
        else if (role === 'Entraîneur')
            switcher.value = 'entraineur';
        else if (role === 'Adhérent' && user_id === 2)
            switcher.value = 'tutorB';
        else if (role === 'Adhérent')
            switcher.value = 'tutorA';
    }
    // 3. Bascule des boutons Connexion / Déconnexion sous le logo de la Sidebar (Section 2.1)
    const btnLogout = document.getElementById('btn-logout');
    const btnLogin = document.getElementById('btn-sidebar-login');
    if (isVisitor) {
        btnLogout?.classList.add('hidden');
        btnLogin?.classList.remove('hidden');
    }
    else {
        btnLogout?.classList.remove('hidden');
        btnLogin?.classList.add('hidden');
    }
    // 4. Isolation visuelle stricte du Superadmin (Dark mode bg-slate-900 + accents rouges - Section 3)
    const header = document.getElementById('app-header');
    const workspace = document.getElementById('main-workspace');
    if (isSuperadmin) {
        header?.classList.replace('bg-white', 'bg-slate-950');
        header?.classList.replace('border-slate-200', 'border-red-900');
        document.getElementById('club-name-heading')?.classList.replace('text-slate-900', 'text-red-500');
        workspace?.classList.add('bg-slate-900', 'text-slate-100');
    }
    // 5. Filtrage dynamique de la Sidebar selon les permissions (US 2.1 CA3)
    const backofficeSection = document.getElementById('nav-section-backoffice');
    if (isVisitor && backofficeSection) {
        backofficeSection.classList.add('hidden');
    }
    else if (backofficeSection) {
        backofficeSection.classList.remove('hidden');
    }
    document.querySelectorAll('#sidebar [data-perm]').forEach((el) => {
        const requiredPerm = el.getAttribute('data-perm') || '';
        const allowed = isSuperadmin
            || permissions.includes(requiredPerm)
            || (requiredPerm === 'members.view' && role === 'Adhérent')
            || (requiredPerm === 'articles.write' && (permissions.includes('articles.publish') || permissions.includes('finances.manage')));
        if (!allowed) {
            // Suppression du DOM pour satisfaire TC-RBAC.spec.ts (expect(locator).toHaveCount(0))
            el.remove();
        }
    });
    // Déclenche un événement personnalisé pour informer les scripts de page que le contexte est prêt
    window.dispatchEvent(new CustomEvent('kanso:auth-ready', { detail: currentAuthContext }));
    return currentAuthContext;
}
/**
 * Initialise le tiroir mobile (Drawer) et son fond assombri (Section 2.2).
 */
function initMobileMenu() {
    const btn = document.getElementById('mobile-menu-btn');
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebar-backdrop');
    if (btn && sidebar) {
        btn.addEventListener('click', () => {
            const isClosed = sidebar.classList.toggle('-translate-x-full');
            backdrop?.classList.toggle('hidden', isClosed);
        });
        backdrop?.addEventListener('click', () => {
            sidebar.classList.add('-translate-x-full');
            backdrop.classList.add('hidden');
        });
    }
}
/**
 * Recherche instantanée 0ms sur #members-table (Section 4.1).
 */
function initMemberSearch() {
    const searchInput = document.getElementById('member-search');
    const tableBody = document.querySelector('#members-table tbody');
    if (searchInput && tableBody) {
        searchInput.addEventListener('input', (e) => {
            const query = e.target.value.toLowerCase().trim();
            const rows = tableBody.querySelectorAll('tr');
            rows.forEach((row) => {
                if (row.classList.contains('tutor-accordion')) {
                    if (query !== '')
                        row.classList.add('hidden');
                    return;
                }
                const textContent = row.textContent?.toLowerCase() || '';
                row.style.display = textContent.includes(query) ? '' : 'none';
            });
        });
    }
}
/**
 * Déconnexion renvoyant sur la page d'accueil publique / (US 1.4 CA3).
 */
function initLogoutButtons() {
    document.querySelectorAll('#btn-logout, [data-action="logout"]').forEach((btn) => {
        btn.addEventListener('click', async (e) => {
            e.preventDefault();
            await fetch('/api/v1/auth/logout', { method: 'POST' });
            await fetch('/api/v1/auth/switch-persona', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ persona: 'visitor' })
            });
            showToast('Session fermée. Retour à l\'accueil public.', 'info');
            setTimeout(() => {
                window.location.href = '/';
            }, 350);
        });
    });
}
/**
 * Changement de compte actif sans quitter la page courante (Soft Retention).
 */
function initPersonaSwitcher() {
    const switcher = document.getElementById('persona-switcher');
    if (!switcher)
        return;
    switcher.addEventListener('change', async () => {
        const persona = switcher.value;
        const res = await fetch('/api/v1/auth/switch-persona', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ persona })
        });
        const json = await res.json();
        if (json.status === 'success') {
            showToast(`Compte actif : ${json.data.role}`, 'success');
            setTimeout(() => window.location.reload(), 300);
        }
    });
}
/**
 * Ouvre la modale de confirmation standardisée (Section 4.4 : bg-slate-900/60 + bouton rouge bg-red-600)
 * avant toute action destructrice (Radiation, Suppression).
 *
 * @param {string} title Titre de la modale.
 * @param {string} message Message d'avertissement.
 * @param {() => Promise<void>} onConfirm Callback asynchrone exécuté au clic sur le bouton rouge.
 */
export function openConfirmModal(title, message, onConfirm) {
    const modal = document.getElementById('confirm-modal');
    const titleEl = document.getElementById('confirm-modal-title');
    const msgEl = document.getElementById('confirm-modal-message');
    const cancelBtn = document.getElementById('confirm-modal-cancel');
    const acceptBtn = document.getElementById('confirm-modal-accept');
    if (!modal || !titleEl || !msgEl || !cancelBtn || !acceptBtn)
        return;
    titleEl.textContent = title;
    msgEl.textContent = message;
    modal.classList.remove('hidden');
    modal.classList.add('flex');
    const closeModal = () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };
    cancelBtn.onclick = closeModal;
    acceptBtn.onclick = async () => {
        setButtonLoading(acceptBtn, true, 'Confirmer');
        try {
            await onConfirm();
        }
        finally {
            setButtonLoading(acceptBtn, false, 'Confirmer');
            closeModal();
        }
    };
}
/**
 * Active ou désactive l'état de chargement d'un bouton avec texte "Traitement..." et spinner CSS (Section 4.2).
 *
 * @param {HTMLButtonElement | null} btn Le bouton cible.
 * @param {boolean} isLoading True pour verrouiller, False pour restaurer.
 * @param {string} defaultLabel Texte d'origine du bouton.
 */
export function setButtonLoading(btn, isLoading, defaultLabel) {
    if (!btn)
        return;
    if (isLoading) {
        btn.disabled = true;
        btn.classList.add('opacity-75', 'cursor-not-allowed');
        btn.innerHTML = `<span class="inline-block w-3.5 h-3.5 border-2 border-white border-t-transparent rounded-full animate-spin mr-1.5"></span>Traitement...`;
    }
    else {
        btn.disabled = false;
        btn.classList.remove('opacity-75', 'cursor-not-allowed');
        btn.textContent = defaultLabel;
    }
}
/**
 * Initialise la modale d'ajout rapide d'adhérent (#modal-add-member).
 */
function initQuickAddMemberModal() {
    const openBtn = document.getElementById('btn-add-member');
    const modal = document.getElementById('modal-add-member');
    const closeBtn = document.getElementById('btn-close-member-modal');
    const form = document.getElementById('form-quick-member');
    if (!openBtn || !modal)
        return;
    openBtn.addEventListener('click', () => {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    });
    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });
    form?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = form.querySelector('button[type="submit"]');
        setButtonLoading(submitBtn, true, 'Enregistrer');
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        try {
            const res = await fetch('/api/v1/members', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            if (json.status === 'success') {
                showToast('Adhérent créé avec succès !', 'success');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                form.reset();
                window.dispatchEvent(new CustomEvent('kanso:members-updated'));
            }
            else {
                showToast(json.message || 'Action refusée.', 'error');
            }
        }
        finally {
            setButtonLoading(submitBtn, false, 'Enregistrer');
        }
    });
}
/**
 * Affiche un Toast non-intrusif en bas à droite (Section 4.3).
 *
 * @param {string} message Texte de la notification.
 * @param {'success' | 'error' | 'info'} type Couleur sémantique.
 */
export function showToast(message, type = 'info') {
    const container = document.getElementById('toast-container');
    if (!container)
        return;
    const toast = document.createElement('div');
    toast.className = 'px-4 py-3 rounded-xl shadow-lg text-white text-sm font-medium transform transition-all duration-300 translate-y-10 opacity-0 pointer-events-auto';
    if (type === 'success')
        toast.classList.add('bg-green-600');
    else if (type === 'error')
        toast.classList.add('bg-red-600');
    else
        toast.classList.add('bg-slate-800');
    toast.textContent = message;
    container.appendChild(toast);
    requestAnimationFrame(() => {
        toast.classList.remove('translate-y-10', 'opacity-0');
    });
    setTimeout(() => {
        toast.classList.add('opacity-0');
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}
