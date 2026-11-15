/**
 * @file disciplines.ts
 * @description Gestion des Disciplines, des Grades (Poussin, Benjamin, Minime)
 * et des Niveaux (Couleur de ceinture / gant) avec Badges numérotés d'effectifs (Point 11).
 */
import { fetchApi } from './api.js';
import { openConfirmModal, showToast } from './main.js';
document.addEventListener('DOMContentLoaded', () => {
    loadDisciplinesList();
    initDisciplineModal();
});
async function loadDisciplinesList() {
    const container = document.getElementById('disciplines-list');
    if (!container)
        return;
    const res = await fetchApi('/api/v1/disciplines');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    container.innerHTML = '';
    res.data.forEach((disc) => {
        const card = document.createElement('div');
        card.className = 'bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col justify-between space-y-5';
        const allLevels = disc.levels || [];
        const grades = allLevels.filter((l) => (l.level_type || 'grade') === 'grade');
        const niveaux = allLevels.filter((l) => l.level_type === 'niveau');
        const gradesHtml = grades.length > 0
            ? grades.map((g) => `
                <li class="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs flex items-center justify-between">
                    <div>
                        <strong class="text-slate-900">${g.name}</strong>
                        <span class="text-[11px] text-slate-400 ml-1">(${g.age_min ?? 0} - ${g.age_max ?? 99} ans)</span>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full bg-blue-100 text-blue-800 text-[11px] font-extrabold" title="Nombre d'adhérents inscrits dans ce grade">
                        ${g.member_count ?? 0} inscrit(s)
                    </span>
                </li>
            `).join('')
            : `<li class="text-xs text-slate-400 italic">Aucun grade d'âge configuré.</li>`;
        const niveauxHtml = niveaux.length > 0
            ? niveaux.map((n) => `
                <li class="px-3 py-2 rounded-xl bg-slate-50 border border-slate-200 text-xs flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="w-3.5 h-3.5 rounded-full border border-slate-300 inline-block" style="background-color: ${n.color_hex || '#10b981'};"></span>
                        <strong class="text-slate-900">${n.name}</strong>
                    </div>
                    <span class="px-2.5 py-0.5 rounded-full bg-emerald-100 text-emerald-800 text-[11px] font-extrabold" title="Nombre d'adhérents ayant ce niveau">
                        ${n.member_count ?? 0} inscrit(s)
                    </span>
                </li>
            `).join('')
            : `<li class="text-xs text-slate-400 italic">Aucun niveau (ceinture/gant) configuré.</li>`;
        card.innerHTML = `
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                    <div class="flex items-center gap-3">
                        <h3 class="font-extrabold text-slate-900 text-xl">${disc.name}</h3>
                        <span class="px-3 py-1 rounded-full bg-emerald-600 text-white text-xs font-extrabold shadow-2xs" title="Total des adhérents inscrits dans cette discipline">
                            👥 ${disc.member_count ?? 0} adhérent(s)
                        </span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" class="btn-edit-disc text-xs bg-slate-900 hover:bg-slate-800 text-white px-3 py-1.5 rounded-xl font-semibold transition">
                            ✏️ Modifier / Grades
                        </button>
                        <button type="button" class="btn-del-disc text-xs bg-red-50 hover:bg-red-100 text-red-600 px-3 py-1.5 rounded-xl font-semibold transition">
                            Supprimer
                        </button>
                    </div>
                </div>

                <!-- Section 1 : Les Grades (Catégories d'âge : Poussin, Benjamin, Minime) -->
                <div class="space-y-2">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-blue-700 block">
                        A. Grades / Catégories d'âge (Poussin, Benjamin, Minime...)
                    </span>
                    <ul class="space-y-1.5">${gradesHtml}</ul>
                </div>

                <!-- Section 2 : Les Niveaux (Couleur de ceinture, gant...) -->
                <div class="space-y-2 pt-2 border-t border-slate-100">
                    <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-700 block">
                        B. Niveaux Techniques (Couleur de ceinture, de gant...)
                    </span>
                    <ul class="space-y-1.5">${niveauxHtml}</ul>
                </div>
            </div>
        `;
        card.querySelector('.btn-edit-disc')?.addEventListener('click', () => openModalForEdit(disc));
        card.querySelector('.btn-del-disc')?.addEventListener('click', () => {
            openConfirmModal('Supprimer la discipline', `Confirmez-vous la suppression de "${disc.name}" et de tous ses grades et niveaux associés ?`, async () => {
                const delRes = await fetchApi(`/api/v1/disciplines/${disc.id}`, { method: 'DELETE' });
                if (delRes.status === 'success') {
                    showToast(`Discipline "${disc.name}" supprimée.`, 'info');
                    await loadDisciplinesList();
                }
            });
        });
        container.appendChild(card);
    });
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
