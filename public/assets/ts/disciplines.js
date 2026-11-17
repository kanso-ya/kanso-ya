/**
 * @file disciplines.ts
 * @description Gestion des Disciplines, des Grades (Poussin, Benjamin, Minime)
 * et des Niveaux (Couleur de ceinture / gant) avec Badges numérotés d'effectifs (Point 11).
 */
import { fetchApi } from './api.js';
import { openConfirmModal, showToast } from './main.js';
let disciplinesData = [];
let activeDisciplineId = null;
document.addEventListener('DOMContentLoaded', () => {
    loadDisciplinesList();
    initDisciplineModal();
});
function cleanLabel(name) {
    return name.replace(/\s*\([^)]*\)/g, '').trim();
}
async function loadDisciplinesList() {
    const res = await fetchApi('/api/v1/disciplines');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    disciplinesData = res.data;
    if (disciplinesData.length > 0 && (activeDisciplineId === null || !disciplinesData.some(d => d.id === activeDisciplineId))) {
        activeDisciplineId = disciplinesData[0].id;
    }
    renderDisciplinesView(disciplinesData);
}
function renderDisciplinesView(disciplines) {
    const tabsBar = document.getElementById('disciplines-tabs-bar');
    const container = document.getElementById('disciplines-list');
    if (!tabsBar || !container)
        return;
    tabsBar.innerHTML = '';
    container.innerHTML = '';
    if (disciplines.length === 0) {
        container.innerHTML = `<div class="p-8 text-center text-slate-400 bg-white rounded-2xl border border-slate-200">Aucune discipline configurée. Cliquez sur "+ Nouvelle Discipline" pour démarrer.</div>`;
        return;
    }
    // 1. Génération de la barre d'onglets
    disciplines.forEach((disc) => {
        const isSelected = disc.id === activeDisciplineId;
        const tabBtn = document.createElement('button');
        tabBtn.type = 'button';
        tabBtn.className = `px-4 py-2 rounded-xl text-xs font-bold transition flex items-center gap-2 shrink-0 ${isSelected
            ? 'brand-primary-bg text-white shadow-xs'
            : 'bg-white hover:bg-slate-100 text-slate-700 border border-slate-200'}`;
        tabBtn.innerHTML = `<span>🥋 ${disc.name}</span> <span class="px-2 py-0.5 rounded-full text-[10px] ${isSelected ? 'bg-black/20 text-white' : 'bg-slate-100 text-slate-600 font-mono'}">${disc.member_count ?? 0}</span>`;
        tabBtn.addEventListener('click', () => {
            activeDisciplineId = disc.id;
            renderDisciplinesView(disciplines);
        });
        tabsBar.appendChild(tabBtn);
    });
    // 2. Affichage de la discipline sélectionnée uniquement
    const activeDisc = disciplines.find((d) => d.id === activeDisciplineId) || disciplines[0];
    if (!activeDisc)
        return;
    const card = document.createElement('div');
    card.className = 'bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col justify-between space-y-6';
    const allLevels = activeDisc.levels || [];
    const grades = allLevels.filter((l) => (l.level_type || 'grade') === 'grade');
    const niveaux = allLevels.filter((l) => l.level_type === 'niveau');
    const gradesHtml = grades.length > 0
        ? grades.map((g) => {
            const displayName = cleanLabel(g.name);
            const ageText = (g.age_min !== null || g.age_max !== null)
                ? `${g.age_min ?? 0} à ${g.age_max ?? 99} ans`
                : 'Tous âges';
            return `
            <li class="px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-xs flex items-center justify-between">
                <div>
                    <strong class="text-slate-900 font-bold">${displayName}</strong>
                    <span class="text-[11px] text-slate-500 ml-2">${ageText}</span>
                </div>
                <a href="/members?discipline=${activeDisc.id}&grade=${g.id}" class="px-3 py-1 rounded-full bg-blue-100 hover:bg-blue-200 text-blue-800 text-[11px] font-extrabold transition cursor-pointer" title="Voir les adhérents inscrits dans ce cours">
                    ${g.member_count ?? 0} inscrit(s)
                </a>
            </li>
        `;
        }).join('')
        : `<li class="text-xs text-slate-400 italic p-3">Aucun grade d'âge configuré pour cette discipline.</li>`;
    const niveauxHtml = niveaux.length > 0
        ? niveaux.map((n) => {
            const displayName = cleanLabel(n.name);
            return `
            <li class="px-4 py-3 rounded-xl bg-slate-50 border border-slate-200 text-xs flex items-center justify-between">
                <div class="flex items-center gap-2.5">
                    <span class="w-3.5 h-3.5 rounded-full border border-slate-300 inline-block shrink-0" style="background-color: ${n.color_hex || '#10b981'};"></span>
                    <strong class="text-slate-900 font-bold">${displayName}</strong>
                </div>
                <a href="/members?discipline=${activeDisc.id}&level=${n.id}" class="px-3 py-1 rounded-full bg-emerald-100 hover:bg-emerald-200 text-emerald-800 text-[11px] font-extrabold transition cursor-pointer" title="Voir les adhérents ayant ce niveau">
                    ${n.member_count ?? 0} inscrit(s)
                </a>
            </li>
        `;
        }).join('')
        : `<li class="text-xs text-slate-400 italic p-3">Aucun niveau technique configuré pour cette discipline.</li>`;
    card.innerHTML = `
        <div class="space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-4">
                <div class="flex items-center gap-3">
                    <h3 class="font-extrabold text-slate-900 text-2xl">${activeDisc.name}</h3>
                    <a href="/members?discipline=${activeDisc.id}" class="px-3 py-1 rounded-full bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-extrabold shadow-2xs transition" title="Filtrer l'annuaire sur cette discipline">
                        👥 ${activeDisc.member_count ?? 0} adhérent(s)
                    </a>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" class="btn-edit-disc text-xs bg-slate-900 hover:bg-slate-800 text-white px-3.5 py-2 rounded-xl font-semibold transition">
                        ✏️ Modifier / Grades
                    </button>
                    <button type="button" class="btn-del-disc text-xs bg-red-50 hover:bg-red-100 text-red-600 px-3.5 py-2 rounded-xl font-semibold transition">
                        Supprimer
                    </button>
                </div>
            </div>

            <!-- Section 1 : Les Grades (Catégories d'âge : Poussin, Benjamin, Minime...) -->
            <div class="space-y-2">
                <span class="text-xs font-bold uppercase tracking-wider text-blue-700 block">
                    A. Grades & Cours par Catégorie d'Âge
                </span>
                <ul class="space-y-2">${gradesHtml}</ul>
            </div>

            <!-- Section 2 : Les Niveaux Techniques (Ceintures, Gants...) -->
            <div class="space-y-2 pt-2 border-t border-slate-100">
                <span class="text-xs font-bold uppercase tracking-wider text-emerald-700 block">
                    B. Niveaux Techniques & Ceintures
                </span>
                <ul class="space-y-2">${niveauxHtml}</ul>
            </div>
        </div>
    `;
    card.querySelector('.btn-edit-disc')?.addEventListener('click', () => openModalForEdit(activeDisc));
    card.querySelector('.btn-del-disc')?.addEventListener('click', () => {
        openConfirmModal('Supprimer la discipline', `Confirmez-vous la suppression de "${activeDisc.name}" et de tous ses grades et niveaux associés ?`, async () => {
            const delRes = await fetchApi(`/api/v1/disciplines/${activeDisc.id}`, { method: 'DELETE' });
            if (delRes.status === 'success') {
                showToast(`Discipline "${activeDisc.name}" supprimée.`, 'info');
                activeDisciplineId = null;
                await loadDisciplinesList();
            }
        });
    });
    container.appendChild(card);
}
function appendLevelRow(name = '', ageMin = null, ageMax = null, levelType = 'grade', colorHex = '#10b981') {
    const levelsContainer = document.getElementById('levels-container');
    if (!levelsContainer)
        return;
    const row = document.createElement('div');
    row.className = 'level-row grid grid-cols-12 gap-2 items-center bg-slate-50 p-2.5 rounded-xl border border-slate-200';
    row.innerHTML = `
        <select class="level-type col-span-3 bg-white border border-slate-300 rounded-lg px-2 py-1.5 text-xs font-bold">
            <option value="grade" ${levelType === 'grade' ? 'selected' : ''}>Grade (Âge)</option>
            <option value="niveau" ${levelType === 'niveau' ? 'selected' : ''}>Niveau (Ceinture)</option>
        </select>
        <input type="text" value="${name}" class="level-name col-span-4 bg-white border border-slate-300 rounded-lg px-2.5 py-1.5 text-xs font-medium" placeholder="Ex: Poussin ou Ceinture Jaune">
        <input type="color" value="${colorHex}" class="level-color col-span-1 h-7 w-full rounded cursor-pointer bg-white border border-slate-300" title="Couleur de la ceinture / du gant">
        <input type="number" value="${ageMin ?? ''}" class="level-age-min col-span-1 bg-white border border-slate-300 rounded-lg px-1.5 py-1.5 text-xs" placeholder="Min">
        <input type="number" value="${ageMax ?? ''}" class="level-age-max col-span-2 bg-white border border-slate-300 rounded-lg px-1.5 py-1.5 text-xs" placeholder="Max">
        <button type="button" class="btn-remove-level col-span-1 text-xs text-red-600 hover:bg-red-50 py-1 rounded font-bold">✕</button>
    `;
    row.querySelector('.btn-remove-level')?.addEventListener('click', () => row.remove());
    levelsContainer.appendChild(row);
}
function openModalForEdit(disc) {
    const modal = document.getElementById('discipline-modal');
    const title = document.getElementById('discipline-modal-title');
    const editIdInput = document.getElementById('discipline-edit-id');
    const nameInput = document.getElementById('discipline-name');
    const levelsContainer = document.getElementById('levels-container');
    if (!modal || !title || !editIdInput || !nameInput || !levelsContainer)
        return;
    title.textContent = `Modifier "${disc.name}" (Grades d'âge & Niveaux)`;
    editIdInput.value = String(disc.id);
    nameInput.value = disc.name;
    levelsContainer.innerHTML = '';
    (disc.levels || []).forEach((lvl) => {
        appendLevelRow(lvl.name, lvl.age_min ?? null, lvl.age_max ?? null, lvl.level_type || 'grade', lvl.color_hex || '#10b981');
    });
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function initDisciplineModal() {
    const openBtn = document.getElementById('btn-open-modal');
    const closeBtn = document.getElementById('btn-close-modal');
    const modal = document.getElementById('discipline-modal');
    const title = document.getElementById('discipline-modal-title');
    const editIdInput = document.getElementById('discipline-edit-id');
    const addLevelBtn = document.getElementById('btn-add-level');
    const levelsContainer = document.getElementById('levels-container');
    const form = document.getElementById('discipline-form');
    const nameInput = document.getElementById('discipline-name');
    const nameError = document.getElementById('name-error');
    const submitBtn = document.getElementById('btn-submit');
    if (!openBtn || !modal || !form || !nameInput || !submitBtn)
        return;
    openBtn.addEventListener('click', () => {
        form.reset();
        if (editIdInput)
            editIdInput.value = '';
        if (title)
            title.textContent = 'Configurer une Discipline, ses Grades & ses Niveaux';
        if (levelsContainer)
            levelsContainer.innerHTML = '';
        nameError?.classList.add('hidden');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    });
    closeBtn?.addEventListener('click', () => {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    });
    addLevelBtn?.addEventListener('click', () => {
        appendLevelRow();
    });
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const nameVal = nameInput.value.trim();
        if (!nameVal) {
            nameError?.classList.remove('hidden');
            return;
        }
        nameError?.classList.add('hidden');
        const levels = [];
        document.querySelectorAll('.level-row').forEach((row) => {
            const lType = (row.querySelector('.level-type')?.value || 'grade');
            const lName = row.querySelector('.level-name')?.value.trim() || '';
            const lColor = row.querySelector('.level-color')?.value || '#10b981';
            const ageMinRaw = row.querySelector('.level-age-min')?.value || '';
            const ageMaxRaw = row.querySelector('.level-age-max')?.value || '';
            if (lName) {
                levels.push({
                    level_type: lType,
                    name: lName,
                    color_hex: lColor,
                    age_min: ageMinRaw !== '' ? parseInt(ageMinRaw, 10) : null,
                    age_max: ageMaxRaw !== '' ? parseInt(ageMaxRaw, 10) : null
                });
            }
        });
        const editId = editIdInput?.value.trim() || '';
        const endpoint = editId ? `/api/v1/disciplines/${editId}` : '/api/v1/disciplines';
        const method = editId ? 'PUT' : 'POST';
        submitBtn.disabled = true;
        submitBtn.classList.add('opacity-75', 'cursor-not-allowed');
        submitBtn.textContent = 'Traitement...';
        try {
            const res = await fetchApi(endpoint, {
                method,
                body: JSON.stringify({ name: nameVal, levels })
            });
            if (res.status === 'success') {
                showToast(editId ? 'Discipline, grades et niveaux mis à jour !' : 'Discipline créée avec succès !', 'success');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
                form.reset();
                if (levelsContainer)
                    levelsContainer.innerHTML = '';
                await loadDisciplinesList();
            }
        }
        finally {
            submitBtn.disabled = false;
            submitBtn.classList.remove('opacity-75', 'cursor-not-allowed');
            submitBtn.textContent = 'Enregistrer';
        }
    });
}
