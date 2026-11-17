/**
 * @file roles.ts
 * @description Matrice des Rôles en Tableau Unique Multi-Colonnes (Point 10) :
 * - En-têtes de colonnes avec champ d'édition du nom du rôle (Renommage)
 * - Ajout d'une nouvelle colonne (POST /api/v1/roles)
 * - Suppression d'une colonne non-système (DELETE /api/v1/roles/{id})
 * - Sauvegarde granulaire ou globale par rôle.
 */
import { fetchApi } from './api.js';
import { openConfirmModal, setButtonLoading, showToast } from './main.js';
const PERMISSION_CATALOG = [
    { key: 'members.view', label: 'Consulter l\'annuaire adhérents', domain: 'CRM' },
    { key: 'members.edit', label: 'Créer / Modifier / Radier un adhérent', domain: 'CRM' },
    { key: 'stats.view', label: 'Consulter les statistiques', domain: 'Statistiques' },
    { key: 'finances.manage', label: 'Inspecter & Valider les cotisations', domain: 'Trésorerie' },
    { key: 'articles.write', label: 'Rédiger des articles (Brouillon)', domain: 'CMS' },
    { key: 'articles.publish', label: 'Publier & Supprimer des articles', domain: 'CMS' },
    { key: 'pages.manage', label: 'Éditer les pages légales (/rgpd, /cgv)', domain: 'CMS' },
    { key: 'roles.manage', label: 'Gérer les rôles RBAC & disciplines', domain: 'Admin' }
];
const bootRolesPage = () => {
    loadRolesMatrix();
    initAddRoleColumnForm();
};
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootRolesPage);
}
else {
    bootRolesPage();
}
function initAddRoleColumnForm() {
    const form = document.getElementById('form-add-role-column');
    const input = document.getElementById('new-role-name');
    const btn = document.getElementById('btn-add-role-col');
    if (!form || form.dataset.bound === '1')
        return;
    form.dataset.bound = '1';
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const name = input?.value.trim() || '';
        if (!name)
            return;
        setButtonLoading(btn, true, '+ Ajouter une colonne (Rôle)');
        try {
            const res = await fetchApi('/api/v1/roles', {
                method: 'POST',
                body: JSON.stringify({ name, permissions: ['members.view'] })
            });
            if (res.status === 'success') {
                showToast(`Colonne "${name}" ajoutée à la matrice !`, 'success');
                form.reset();
                await loadRolesMatrix();
            }
        }
        finally {
            setButtonLoading(btn, false, '+ Ajouter une colonne (Rôle)');
        }
    });
}
async function loadRolesMatrix() {
    const thead = document.getElementById('rbac-matrix-thead');
    const tbody = document.getElementById('rbac-matrix-tbody');
    const tfoot = document.getElementById('rbac-matrix-tfoot');
    const cardsContainer = document.getElementById('roles-container');
    if (!thead || !tbody || !tfoot || !cardsContainer)
        return;
    const response = await fetchApi('/api/v1/roles');
    if (response.status !== 'success' || !response.data)
        return;
    const roles = response.data.map((r) => ({
        ...r,
        is_system: Boolean(r.is_system),
        perms: Array.isArray(r.permissions) ? r.permissions : JSON.parse(r.permissions || '[]')
    }));
    const headerRow = document.createElement('tr');
    headerRow.innerHTML = `<th class="p-4 w-72 font-extrabold uppercase tracking-wider text-slate-300 border-r border-slate-800 sticky left-0 bg-slate-900 z-20 shadow-sm">Domaine & Permission</th>`;
    roles.forEach((role) => {
        const th = document.createElement('th');
        th.className = 'p-3.5 text-center border-r border-slate-800 min-w-[170px]';
        if (role.is_system) {
            th.innerHTML = `
                <div class="font-extrabold text-sm text-white">${role.name}</div>
                <span class="inline-block mt-1 px-2 py-0.5 rounded bg-red-900/70 text-red-200 text-[10px] uppercase font-bold">🔒 Système</span>
            `;
        }
        else {
            th.innerHTML = `
                <input type="text" data-role-name-id="${role.id}" value="${role.name}" title="Cliquez pour renommer ce rôle"
                       class="bg-slate-800 hover:bg-slate-700 focus:bg-slate-950 text-white font-bold text-center text-xs rounded-lg px-2 py-1.5 w-full border border-slate-700 focus:border-emerald-400 focus:outline-none">
                <span class="inline-block mt-1 text-[10px] text-slate-400">✏️ Nom modifiable</span>
            `;
        }
        headerRow.appendChild(th);
    });
    thead.innerHTML = '';
    thead.appendChild(headerRow);
    tbody.innerHTML = '';
    PERMISSION_CATALOG.forEach((perm) => {
        const tr = document.createElement('tr');
        tr.className = 'hover:bg-slate-50 transition';
        tr.innerHTML = `
            <td class="p-3.5 border-r border-slate-200 sticky left-0 bg-white z-10 shadow-xs">
                <div class="flex items-center gap-2">
                    <span class="px-2 py-0.5 rounded bg-slate-100 text-slate-600 font-bold text-[10px] uppercase">${perm.domain}</span>
                    <strong class="text-slate-900 font-bold">${perm.label}</strong>
                </div>
                <code class="text-[10px] text-slate-400 font-mono">${perm.key}</code>
            </td>
        `;
        roles.forEach((role) => {
            const isChecked = role.is_system || role.perms.includes(perm.key);
            const td = document.createElement('td');
            td.className = 'p-3 text-center border-r border-slate-100';
            td.innerHTML = `
                <label class="relative inline-flex items-center justify-center cursor-pointer" title="${role.is_system ? 'Droit verrouillé (Rôle Système)' : (isChecked ? 'Désactiver' : 'Activer')}">
                    <input type="checkbox" data-matrix-role="${role.id}" value="${perm.key}" ${isChecked ? 'checked' : ''} ${role.is_system ? 'disabled' : ''}
                           class="sr-only peer">
                    <div class="w-9 h-5 bg-slate-200 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-emerald-600 ${role.is_system ? 'opacity-40 cursor-not-allowed' : ''}"></div>
                </label>
            `;
            tr.appendChild(td);
        });
        tbody.appendChild(tr);
    });
    const footRow = document.createElement('tr');
    footRow.innerHTML = `<td class="p-4 font-bold text-slate-600 border-r border-slate-200 sticky left-0 bg-slate-50 z-10 shadow-xs">Actions sur la colonne</td>`;
    roles.forEach((role) => {
        const td = document.createElement('td');
        td.className = 'p-3 text-center border-r border-slate-200 space-y-1.5';
        if (role.is_system) {
            td.innerHTML = `<span class="text-[11px] text-slate-400 italic">Verrouillé</span>`;
        }
        else {
            const saveBtn = document.createElement('button');
            saveBtn.type = 'button';
            saveBtn.className = 'w-full bg-slate-900 hover:bg-slate-800 text-white py-2 px-3 rounded-xl text-xs font-bold transition';
            saveBtn.textContent = 'Enregistrer';
            saveBtn.addEventListener('click', async () => {
                const nameInput = document.querySelector(`input[data-role-name-id="${role.id}"]`);
                const newName = nameInput?.value.trim() || role.name;
                const checked = Array.from(document.querySelectorAll(`input[data-matrix-role="${role.id}"]:checked`)).map((el) => el.value);
                setButtonLoading(saveBtn, true, 'Enregistrer');
                try {
                    const res = await fetchApi(`/api/v1/roles/${role.id}`, {
                        method: 'PATCH',
                        body: JSON.stringify({ name: newName, permissions: checked })
                    });
                    if (res.status === 'success') {
                        showToast('Permissions mises à jour avec succès.', 'success');
                        await loadRolesMatrix();
                    }
                }
                finally {
                    setButtonLoading(saveBtn, false, 'Enregistrer');
                }
            });
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'w-full bg-red-50 hover:bg-red-100 text-red-600 py-1.5 px-3 rounded-xl text-[11px] font-bold transition';
            delBtn.textContent = 'Supprimer ce rôle';
            delBtn.addEventListener('click', () => {
                openConfirmModal('Supprimer ce rôle', `Confirmez-vous la suppression de la colonne "${role.name}" ?`, async () => {
                    const res = await fetchApi(`/api/v1/roles/${role.id}`, { method: 'DELETE' });
                    if (res.status === 'success') {
                        showToast(`Rôle "${role.name}" supprimé.`, 'info');
                        await loadRolesMatrix();
                    }
                });
            });
            td.appendChild(saveBtn);
            td.appendChild(delBtn);
        }
        footRow.appendChild(td);
    });
    tfoot.innerHTML = '';
    tfoot.appendChild(footRow);
    cardsContainer.innerHTML = '';
    roles.forEach((role) => {
        const card = document.createElement('div');
        card.className = 'bg-white p-4 rounded-xl border border-slate-200 flex flex-col justify-between space-y-3 text-xs';
        const boxesHtml = PERMISSION_CATALOG.map((p) => {
            const chk = role.is_system || role.perms.includes(p.key);
            return `<label class="flex items-center gap-1.5 text-slate-600"><input type="checkbox" value="${p.key}" ${chk ? 'checked' : ''} ${role.is_system ? 'disabled' : ''}><span>${p.key}</span></label>`;
        }).join('');
        card.innerHTML = `
            <div>
                <div class="font-bold text-slate-900 text-sm mb-2">${role.name}</div>
                <div class="space-y-1">${boxesHtml}</div>
            </div>
        `;
        if (!role.is_system) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'w-full bg-slate-900 text-white py-2 rounded-lg font-bold';
            btn.textContent = 'Enregistrer les droits';
            btn.addEventListener('click', async () => {
                const checked = Array.from(card.querySelectorAll('input[type="checkbox"]:checked')).map((c) => c.value);
                const res = await fetchApi(`/api/v1/roles/${role.id}`, {
                    method: 'PATCH',
                    body: JSON.stringify({ permissions: checked })
                });
                if (res.status === 'success') {
                    showToast('Permissions mises à jour avec succès.', 'success');
                    await loadRolesMatrix();
                }
            });
            card.appendChild(btn);
        }
        cardsContainer.appendChild(card);
    });
}
