/**
 * @file cms.ts
 * @description Studio Éditorial à 2 Onglets avec fonctionnalités avancées :
 * - Onglet 1 : Builder Multi-Colonnes (1, 2, 3 colonnes et 2 Colonnes Asymétriques avec réglage de largeur au choix),
 *   Drag & Drop intégral (Barre d'outils -> Canevas ET Réorganisation intra-colonne / inter-colonnes avec indicateur visuel),
 *   Bloc Vidéo Courte avec Lecteur Léger Personnalisé (Play/Pause, Stop, Volume sans contrôles natifs),
 *   Bloc Photo Simple ou Carrousel (Horizontal avec flèches ou Vertical),
 *   Infobulles et Modale d'Aperçu Live.
 * - Onglet 2 : Catalogue des Publications avec Filtres Multi-Critères (Statut, Catégorie, Auteur),
 *   Traçabilité complète (Auteur + Création, Modificateur + Modification), Tri chronologique et Nettoyage médias orphelins.
 */
import { fetchApi } from './api.js';
import { openConfirmModal, setButtonLoading, showToast } from './main.js';
import { initCmsMediaInteractions } from './cms-media.js';
let draggedBlock = null;
let draggedToolbarType = null;
let activeColumnTarget = null;
let dropIndicator = null;
let canPublishArticles = true;
let allArticles = [];
let articlesSortOrder = 'desc';
// Filtres multi-critères
let filterStatus = 'all';
let filterCategory = 'all';
let filterAuthor = 'all';
document.addEventListener('DOMContentLoaded', () => {
    initCmsTabs();
    initMultiColumnBuilder();
    initPreviewModal();
    initArticlesFilters();
    loadArticles();
    initArticleForm();
    initLegalPagesEditor();
    initMediaCleanup();
    initArticlesSort();
    window.addEventListener('kanso:auth-ready', (e) => {
        const ctx = e.detail;
        canPublishArticles = ctx.permissions.includes('articles.publish') || ctx.role === 'Superadmin';
        const statusSelect = document.getElementById('art-status');
        if (!canPublishArticles && statusSelect) {
            statusSelect.value = 'draft';
            statusSelect.disabled = true;
        }
        if (!ctx.permissions.includes('pages.manage') && ctx.role !== 'Superadmin') {
            document.getElementById('legal-pages-section')?.classList.add('hidden');
        }
        loadArticles();
    });
});
function initCmsTabs() {
    const btnEditor = document.getElementById('cms-tab-btn-editor');
    const btnPubs = document.getElementById('cms-tab-btn-publications');
    const panelEditor = document.getElementById('cms-tab-panel-editor');
    const panelPubs = document.getElementById('cms-tab-panel-publications');
    if (!btnEditor || !btnPubs || !panelEditor || !panelPubs)
        return;
    btnEditor.addEventListener('click', () => {
        btnEditor.className = 'px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition';
        btnPubs.className = 'px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition';
        panelEditor.classList.remove('hidden');
        panelPubs.classList.add('hidden');
    });
    btnPubs.addEventListener('click', () => {
        btnPubs.className = 'px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition';
        btnEditor.className = 'px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition';
        panelPubs.classList.remove('hidden');
        panelEditor.classList.add('hidden');
    });
}
function getOrCreateDropIndicator() {
    if (!dropIndicator) {
        dropIndicator = document.createElement('div');
        dropIndicator.className = 'drop-indicator h-1.5 bg-emerald-500 rounded-full my-1.5 pointer-events-none transition-all shadow-sm';
    }
    return dropIndicator;
}
function removeDropIndicator() {
    if (dropIndicator && dropIndicator.parentElement) {
        dropIndicator.remove();
    }
}
/**
 * Initialise le Builder Multi-Colonnes et le Drag & Drop intégral (Barre d'outils -> Canevas et Intra/Inter-colonnes).
 */
function initMultiColumnBuilder() {
    const canvas = document.getElementById('builder-blocks-canvas');
    const rawWrapper = document.getElementById('raw-html-wrapper');
    const rawTextarea = document.getElementById('art-content');
    const toggleHtmlBtn = document.getElementById('btn-toggle-html-source');
    const mediaImageInput = document.getElementById('art-media-upload');
    const mediaVideoInput = document.getElementById('art-video-upload');
    if (!canvas || !rawTextarea)
        return;
    // Crée une première rangée 1 colonne avec un bloc paragraphe initial
    const firstCol = addLayoutRow(1);
    if (firstCol) {
        addNestedBlock('p', 'Rédigez votre actualité ici ou ajoutez des colonnes et des blocs via la barre d\'outils...', firstCol);
    }
    // Boutons "+ Disposition : 1 Colonne / 2 Colonnes / 2 Col. Asymétriques / 3 Colonnes"
    document.querySelectorAll('.btn-add-row').forEach((btn) => {
        btn.addEventListener('click', () => {
            const colsAttr = btn.getAttribute('data-cols') || '1';
            const isAsym = colsAttr === 'asym';
            const cols = isAsym ? 2 : parseInt(colsAttr, 10);
            const newCol = addLayoutRow(cols, isAsym);
            if (newCol) {
                addNestedBlock('p', 'Nouveau bloc dans cette colonne...', newCol);
            }
            showToast(isAsym ? 'Section 2 colonnes asymétriques ajoutée.' : `Rangée de ${cols} colonne(s) ajoutée au canevas.`, 'info');
        });
    });
    // Boutons "+ Bloc" : Draggable depuis la barre d'outils (Exigence 1) et cliquables
    document.querySelectorAll('.btn-add-block').forEach((btn) => {
        btn.setAttribute('draggable', 'true');
        btn.addEventListener('dragstart', (ev) => {
            const type = btn.getAttribute('data-add-block') || 'p';
            draggedToolbarType = type;
            draggedBlock = null;
            try {
                ev.dataTransfer?.setData('text/plain', type);
                ev.dataTransfer?.setData('text', type);
            }
            catch { }
            btn.classList.add('opacity-50');
        });
        btn.addEventListener('dragend', () => {
            btn.classList.remove('opacity-50');
            removeDropIndicator();
            setTimeout(() => {
                draggedToolbarType = null;
            }, 200);
        });
        btn.addEventListener('click', () => {
            const type = btn.getAttribute('data-add-block') || 'p';
            addNestedBlock(type, '', activeColumnTarget);
        });
    });
    // Commandes WYSIWYG
    document.querySelectorAll('.wysiwyg-cmd').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cmd = btn.getAttribute('data-cmd') || '';
            if (cmd === 'createLink') {
                const url = prompt('Adresse du lien (https://...) :', 'https://');
                if (url)
                    document.execCommand('createLink', false, url);
            }
            else {
                document.execCommand(cmd, false);
            }
            syncCanvasToTextarea();
        });
    });
    // Upload rapide d'image depuis la barre d'outils
    mediaImageInput?.addEventListener('change', async () => {
        const file = mediaImageInput.files?.[0];
        if (!file)
            return;
        const fd = new FormData();
        fd.append('file', file);
        const res = await fetchApi('/api/v1/articles/media', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            addNestedBlock('image', res.data.url, activeColumnTarget);
            showToast('Image insérée dans la colonne active !', 'success');
        }
        mediaImageInput.value = '';
    });
    // Upload rapide de vidéo depuis la barre d'outils
    mediaVideoInput?.addEventListener('change', async () => {
        const file = mediaVideoInput.files?.[0];
        if (!file)
            return;
        const fd = new FormData();
        fd.append('file', file);
        const res = await fetchApi('/api/v1/articles/media', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            addNestedBlock('video', res.data.url, activeColumnTarget);
            showToast('Lecteur vidéo inséré dans la colonne active !', 'success');
        }
        mediaVideoInput.value = '';
    });
    toggleHtmlBtn?.addEventListener('click', () => {
        rawWrapper?.classList.toggle('hidden');
    });
    rawTextarea.addEventListener('input', () => {
        canvas.innerHTML = '';
        const col = addLayoutRow(1);
        if (col)
            addNestedBlock('raw', rawTextarea.value, col);
    });
}
/**
 * Ajoute une rangée de colonnes (symétriques 1/2/3 ou asymétriques 2 cols) au canevas.
 */
function addLayoutRow(colCount, isAsym = false) {
    const canvas = document.getElementById('builder-blocks-canvas');
    if (!canvas)
        return null;
    const rowWrapper = document.createElement('div');
    rowWrapper.className = 'builder-row p-3 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/50 space-y-2';
    const rowHeader = document.createElement('div');
    rowHeader.className = 'flex flex-wrap items-center justify-between gap-2 text-[11px] text-slate-400 font-bold uppercase px-1';
    let ratioControlHtml = '';
    if (isAsym) {
        ratioControlHtml = `
            <div class="flex items-center gap-1.5 text-xs text-slate-500 normal-case">
                <span class="font-bold text-slate-700">Répartition :</span>
                <select class="select-col-ratio text-[11px] bg-slate-900 text-emerald-400 border border-slate-700 rounded-lg px-2 py-0.5 font-mono cursor-pointer font-bold">
                    <option value="30-70">30% / 70%</option>
                    <option value="40-60">40% / 60%</option>
                    <option value="50-50">50% / 50%</option>
                    <option value="60-40">60% / 40%</option>
                    <option value="70-30">70% / 30%</option>
                </select>
            </div>
        `;
    }
    rowHeader.innerHTML = `
        <div class="flex items-center gap-3">
            <span>Section ${isAsym ? '2 Colonnes Asymétriques' : `${colCount} Colonne(s)`}</span>
            ${ratioControlHtml}
        </div>
        <div class="flex items-center gap-2">
            <button type="button" class="btn-row-up hover:text-slate-900" title="Monter toute cette rangée">▲</button>
            <button type="button" class="btn-row-down hover:text-slate-900" title="Descendre toute cette rangée">▼</button>
            <button type="button" class="btn-row-del hover:text-red-600" title="Supprimer cette rangée">✕ Supprimer</button>
        </div>
    `;
    const gridEl = document.createElement('div');
    if (isAsym) {
        gridEl.className = 'builder-row-grid builder-row-asym flex flex-col md:flex-row gap-4 w-full';
        gridEl.setAttribute('data-cols', 'asym');
        gridEl.setAttribute('data-ratio', '30-70');
        gridEl.style.display = 'flex';
        gridEl.style.gap = '1rem';
    }
    else {
        const gridClass = colCount === 3
            ? 'grid grid-cols-1 md:grid-cols-3 gap-4'
            : (colCount === 2 ? 'grid grid-cols-1 md:grid-cols-2 gap-4' : 'grid grid-cols-1 gap-4');
        gridEl.className = `builder-row-grid ${gridClass}`;
        gridEl.setAttribute('data-cols', String(colCount));
    }
    let firstCol = null;
    const columnsArray = [];
    for (let i = 0; i < colCount; i++) {
        const colEl = document.createElement('div');
        colEl.className = 'builder-column min-h-[90px] p-3 rounded-xl bg-white border border-slate-200 space-y-2.5 transition flex-1';
        if (isAsym) {
            const initialWidth = i === 0 ? '30%' : '70%';
            colEl.style.flex = `0 0 ${initialWidth}`;
            colEl.style.width = initialWidth;
            colEl.style.maxWidth = initialWidth;
        }
        colEl.innerHTML = `
            <div class="flex items-center justify-between text-[10px] font-bold uppercase text-slate-400 border-b border-slate-100 pb-1">
                <span class="col-title">Colonne ${i + 1}${isAsym ? (i === 0 ? ' (30%)' : ' (70%)') : ''}</span>
                <button type="button" class="btn-quick-add-in-col text-emerald-600 hover:underline font-bold">+ Bloc ici</button>
            </div>
            <div class="column-dropzone space-y-2 min-h-[50px]"></div>
        `;
        const dropzone = colEl.querySelector('.column-dropzone');
        if (i === 0)
            firstCol = dropzone;
        columnsArray.push(colEl);
        // Sélection de la colonne active
        colEl.addEventListener('click', () => {
            document.querySelectorAll('.builder-column').forEach((c) => c.classList.remove('ring-2', 'ring-emerald-500'));
            colEl.classList.add('ring-2', 'ring-emerald-500');
            activeColumnTarget = dropzone;
        });
        colEl.querySelector('.btn-quick-add-in-col')?.addEventListener('click', (ev) => {
            ev.stopPropagation();
            addNestedBlock('p', 'Nouveau bloc dans cette colonne...', dropzone);
        });
        // =====================================================================
        // DRAG & DROP INTÉGRAL (Barre d'outils et Réorganisation intra-colonne)
        // =====================================================================
        const handleDragOver = (e) => {
            e.preventDefault();
            e.stopPropagation();
            colEl.classList.add('bg-emerald-50/50');
            const targetBlock = e.target.closest('.builder-block');
            const indicator = getOrCreateDropIndicator();
            if (targetBlock && dropzone.contains(targetBlock)) {
                const rect = targetBlock.getBoundingClientRect();
                const midY = rect.top + rect.height / 2;
                if (e.clientY < midY) {
                    targetBlock.before(indicator);
                }
                else {
                    targetBlock.after(indicator);
                }
            }
            else if (!dropzone.contains(indicator)) {
                dropzone.appendChild(indicator);
            }
        };
        const handleDrop = (e) => {
            e.preventDefault();
            e.stopPropagation();
            colEl.classList.remove('bg-emerald-50/50');
            const indicator = dropIndicator;
            const insertPosition = indicator && indicator.parentElement === dropzone ? indicator : null;
            let typeToInsert = draggedToolbarType;
            if (!typeToInsert) {
                try {
                    typeToInsert = e.dataTransfer?.getData('text/plain') || e.dataTransfer?.getData('text') || null;
                }
                catch { }
            }
            if (typeToInsert) {
                draggedToolbarType = null;
                const newBlock = createNestedBlockElement(typeToInsert, '', dropzone);
                if (insertPosition) {
                    insertPosition.replaceWith(newBlock);
                }
                else {
                    dropzone.appendChild(newBlock);
                }
                removeDropIndicator();
                syncCanvasToTextarea();
                showToast(`Bloc ${typeToInsert} inséré dans la colonne !`, 'success');
            }
            else if (draggedBlock) {
                if (insertPosition) {
                    insertPosition.replaceWith(draggedBlock);
                }
                else {
                    dropzone.appendChild(draggedBlock);
                }
                draggedBlock = null;
                removeDropIndicator();
                syncCanvasToTextarea();
            }
            else {
                removeDropIndicator();
            }
        };
        colEl.addEventListener('dragover', handleDragOver);
        dropzone.addEventListener('dragover', handleDragOver);
        colEl.addEventListener('dragleave', (e) => {
            if (!colEl.contains(e.relatedTarget)) {
                colEl.classList.remove('bg-emerald-50/50');
                removeDropIndicator();
            }
        });
        colEl.addEventListener('drop', handleDrop);
        dropzone.addEventListener('drop', handleDrop);
        gridEl.appendChild(colEl);
    }
    // Gestion du sélecteur de ratio pour les colonnes asymétriques
    if (isAsym) {
        const ratioSelect = rowHeader.querySelector('.select-col-ratio');
        ratioSelect?.addEventListener('change', () => {
            const val = ratioSelect.value;
            gridEl.setAttribute('data-ratio', val);
            const [r1, r2] = val.split('-');
            if (columnsArray[0] && columnsArray[1]) {
                columnsArray[0].style.flex = `0 0 ${r1}%`;
                columnsArray[0].style.width = `${r1}%`;
                columnsArray[0].style.maxWidth = `${r1}%`;
                const t0 = columnsArray[0].querySelector('.col-title');
                if (t0)
                    t0.textContent = `Colonne 1 (${r1}%)`;
                columnsArray[1].style.flex = `0 0 ${r2}%`;
                columnsArray[1].style.width = `${r2}%`;
                columnsArray[1].style.maxWidth = `${r2}%`;
                const t1 = columnsArray[1].querySelector('.col-title');
                if (t1)
                    t1.textContent = `Colonne 2 (${r2}%)`;
            }
            syncCanvasToTextarea();
        });
    }
    rowHeader.querySelector('.btn-row-up')?.addEventListener('click', () => {
        const prev = rowWrapper.previousElementSibling;
        if (prev) {
            prev.before(rowWrapper);
            syncCanvasToTextarea();
        }
    });
    rowHeader.querySelector('.btn-row-down')?.addEventListener('click', () => {
        const next = rowWrapper.nextElementSibling;
        if (next) {
            next.after(rowWrapper);
            syncCanvasToTextarea();
        }
    });
    rowHeader.querySelector('.btn-row-del')?.addEventListener('click', () => {
        rowWrapper.remove();
        syncCanvasToTextarea();
    });
    rowWrapper.appendChild(rowHeader);
    rowWrapper.appendChild(gridEl);
    canvas.appendChild(rowWrapper);
    if (firstCol)
        activeColumnTarget = firstCol;
    syncCanvasToTextarea();
    return firstCol;
}
/**
 * Crée un élément DOM de bloc imbriqué (sans l'insérer d'office dans une dropzone).
 */
function createNestedBlockElement(type, initialContent = '', parentDropzone = null) {
    const blockWrapper = document.createElement('div');
    blockWrapper.draggable = true;
    blockWrapper.className = 'builder-block group relative flex items-start gap-2 p-2.5 rounded-xl border border-slate-200 bg-slate-50/60 hover:bg-white hover:border-emerald-400 transition shadow-2xs w-full';
    let innerHtml = '';
    let label = 'Texte';
    if (type === 'h2') {
        label = 'Sous-Titre';
        innerHtml = `<h2 contenteditable="true" class="block-editable text-lg font-bold text-slate-900 focus:outline-none w-full">${initialContent || 'Titre de la section'}</h2>`;
    }
    else if (type === 'callout') {
        label = 'Encadré Info';
        innerHtml = `<p contenteditable="true" class="block-editable p-3 bg-emerald-50 border-l-4 border-emerald-600 text-emerald-950 rounded-r-lg text-xs font-medium focus:outline-none w-full">${initialContent || 'Encadré important : pensez à vous munir de votre passeport sportif.'}</p>`;
    }
    else if (type === 'list') {
        label = 'Liste à puces';
        innerHtml = `<ul contenteditable="true" class="block-editable list-disc pl-5 text-xs text-slate-700 space-y-1 focus:outline-none w-full"><li>${initialContent || 'Premier point important'}</li><li>Second point</li></ul>`;
    }
    else if (type === 'quote') {
        label = 'Citation / Podium';
        innerHtml = `<blockquote contenteditable="true" class="block-editable pl-3 border-l-4 border-amber-500 italic text-slate-700 text-xs focus:outline-none w-full">${initialContent || '« Bravo à tous nos participants ! »'}</blockquote>`;
    }
    else if (type === 'video') {
        // =====================================================================
        // BLOC VIDÉO COURTE AVEC LECTEUR LÉGER PERSONNALISÉ (Exigence 3)
        // =====================================================================
        label = 'Vidéo Courte';
        const defaultVideoUrl = initialContent || '/uploads/media/video_sample.mp4';
        innerHtml = `
            <div class="block-editable video-block-container w-full space-y-2">
                <div class="flex items-center gap-2">
                    <input type="text" class="video-url-input text-xs border border-slate-300 rounded-lg px-2.5 py-1.5 flex-1 font-mono" placeholder="URL de la vidéo (.mp4, .webm)" value="${defaultVideoUrl}">
                    <label class="px-2.5 py-1.5 rounded-lg bg-indigo-600 hover:bg-indigo-700 text-white text-xs font-bold cursor-pointer shrink-0">
                        📁 Fichier
                        <input type="file" accept="video/mp4,video/webm" class="video-file-inline hidden">
                    </label>
                </div>
                <div class="custom-video-player relative rounded-xl overflow-hidden bg-slate-950 border border-slate-800 shadow-sm w-full" data-video-src="${defaultVideoUrl}">
                    <video src="${defaultVideoUrl}" class="w-full max-h-72 object-cover block" preload="metadata"></video>
                    <div class="video-custom-controls flex items-center justify-between gap-3 p-2.5 bg-slate-900 text-white text-xs border-t border-slate-800 select-none">
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn-video-play px-2.5 py-1 rounded bg-emerald-600 hover:bg-emerald-500 font-bold transition flex items-center gap-1" title="Lecture / Pause">
                                ▶ Play
                            </button>
                            <button type="button" class="btn-video-stop px-2.5 py-1 rounded bg-slate-700 hover:bg-slate-600 font-bold transition flex items-center gap-1" title="Arrêter">
                                ⏹ Stop
                            </button>
                        </div>
                        <div class="flex items-center gap-2">
                            <button type="button" class="btn-video-mute text-slate-400 hover:text-white" title="Couper le son">🔊</button>
                            <input type="range" class="video-volume-slider w-20 accent-emerald-500 cursor-pointer" min="0" max="1" step="0.05" value="1" title="Volume">
                        </div>
                    </div>
                </div>
            </div>
        `;
    }
    else if (type === 'image') {
        // =====================================================================
        // BLOC PHOTO SIMPLE OU CARROUSEL HORIZONTAL / VERTICAL (Exigence 4)
        // =====================================================================
        label = 'Photo / Carrousel';
        const defaultImg = initialContent || '/assets/img/kanso-ya-logo.webp';
        innerHtml = `
            <div class="block-editable photo-block-container w-full space-y-2">
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-2">
                    <div class="flex items-center gap-1.5 text-xs">
                        <span class="font-semibold text-slate-600">Affichage :</span>
                        <select class="select-photo-mode text-xs border border-slate-300 rounded-lg px-2 py-1 font-semibold bg-white">
                            <option value="single">Photo simple</option>
                            <option value="carousel-horizontal">Carrousel Horizontal</option>
                            <option value="carousel-vertical">Carrousel Vertical</option>
                        </select>
                    </div>
                    <label class="px-2 py-1 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-white text-xs font-semibold cursor-pointer shrink-0">
                        + Ajouter photo
                        <input type="file" accept="image/jpeg,image/png,image/webp" class="photo-file-inline hidden">
                    </label>
                </div>
                <div class="photo-render-area w-full">
                    <div class="single-photo-view">
                        <img src="${defaultImg}" alt="Illustration" class="rounded-lg max-h-48 object-cover mb-1 w-full">
                        <p contenteditable="true" class="text-[11px] text-slate-500 italic">Légende de la photo</p>
                    </div>
                </div>
            </div>
        `;
    }
    else if (type === 'raw') {
        label = 'Contenu Riche';
        innerHtml = `<div contenteditable="true" class="block-editable text-sm text-slate-700 space-y-2 focus:outline-none w-full">${initialContent}</div>`;
    }
    else {
        label = 'Paragraphe';
        innerHtml = `<p contenteditable="true" class="block-editable text-xs text-slate-700 leading-relaxed focus:outline-none w-full">${initialContent || 'Cliquez ici pour rédiger votre texte...'}</p>`;
    }
    blockWrapper.innerHTML = `
        <div class="flex flex-col items-center gap-0.5 text-slate-400 select-none shrink-0">
            <span class="cursor-grab active:cursor-grabbing text-sm font-bold px-1 hover:text-slate-800" title="Maintenez cliqué pour glisser-déposer ce bloc">⠿</span>
            <button type="button" class="btn-move-up text-[9px] hover:text-slate-900" title="Monter">▲</button>
            <button type="button" class="btn-move-down text-[9px] hover:text-slate-900" title="Descendre">▼</button>
        </div>
        <div class="flex-1 space-y-1 overflow-hidden">
            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400 block">${label}</span>
            ${innerHtml}
        </div>
        <button type="button" class="btn-remove-block text-xs text-slate-300 hover:text-red-600 font-bold px-1" title="Supprimer ce bloc">✕</button>
    `;
    // Événements de Drag & Drop sur le bloc
    blockWrapper.addEventListener('dragstart', (ev) => {
        ev.stopPropagation();
        draggedBlock = blockWrapper;
        draggedToolbarType = null;
        blockWrapper.classList.add('opacity-50', 'border-dashed');
    });
    blockWrapper.addEventListener('dragend', () => {
        blockWrapper.classList.remove('opacity-50', 'border-dashed');
        removeDropIndicator();
        setTimeout(() => {
            draggedBlock = null;
        }, 200);
        syncCanvasToTextarea();
    });
    blockWrapper.querySelector('.btn-move-up')?.addEventListener('click', () => {
        const prev = blockWrapper.previousElementSibling;
        if (prev) {
            prev.before(blockWrapper);
            syncCanvasToTextarea();
        }
    });
    blockWrapper.querySelector('.btn-move-down')?.addEventListener('click', () => {
        const next = blockWrapper.nextElementSibling;
        if (next) {
            next.after(blockWrapper);
            syncCanvasToTextarea();
        }
    });
    blockWrapper.querySelector('.btn-remove-block')?.addEventListener('click', () => {
        blockWrapper.remove();
        syncCanvasToTextarea();
    });
    blockWrapper.querySelector('.block-editable')?.addEventListener('input', () => {
        syncCanvasToTextarea();
    });
    // Configuration interactive du bloc vidéo
    if (type === 'video') {
        const urlInput = blockWrapper.querySelector('.video-url-input');
        const fileInput = blockWrapper.querySelector('.video-file-inline');
        const videoEl = blockWrapper.querySelector('video');
        const playerContainer = blockWrapper.querySelector('.custom-video-player');
        const updateVideoSource = (src) => {
            if (videoEl)
                videoEl.src = src;
            if (playerContainer)
                playerContainer.setAttribute('data-video-src', src);
            if (urlInput)
                urlInput.value = src;
            syncCanvasToTextarea();
        };
        urlInput?.addEventListener('change', () => {
            updateVideoSource(urlInput.value.trim());
        });
        fileInput?.addEventListener('change', async () => {
            const f = fileInput.files?.[0];
            if (!f)
                return;
            const fd = new FormData();
            fd.append('file', f);
            const res = await fetchApi('/api/v1/articles/media', { method: 'POST', body: fd });
            if (res.status === 'success' && res.data?.url) {
                updateVideoSource(res.data.url);
                showToast('Vidéo importée avec succès !', 'success');
            }
        });
    }
    // Configuration interactive du bloc photo / carrousel
    if (type === 'image') {
        const photoList = [initialContent || '/assets/img/kanso-ya-logo.webp'];
        const selectMode = blockWrapper.querySelector('.select-photo-mode');
        const photoFileInput = blockWrapper.querySelector('.photo-file-inline');
        const renderArea = blockWrapper.querySelector('.photo-render-area');
        const renderPhotoView = () => {
            if (!renderArea || !selectMode)
                return;
            const mode = selectMode.value;
            if (mode === 'single') {
                renderArea.innerHTML = `
                    <div class="single-photo-view">
                        <img src="${photoList[0] || '/assets/img/kanso-ya-logo.webp'}" alt="Illustration" class="rounded-lg max-h-48 object-cover mb-1 w-full">
                        <p contenteditable="true" class="text-[11px] text-slate-500 italic">Légende de la photo</p>
                    </div>
                `;
            }
            else if (mode === 'carousel-horizontal') {
                const slidesHtml = photoList.map((url, i) => `
                    <div class="carousel-slide min-w-full shrink-0">
                        <img src="${url}" class="w-full h-56 object-cover block" alt="Photo ${i + 1}">
                    </div>
                `).join('');
                renderArea.innerHTML = `
                    <div class="cms-carousel cms-carousel-horizontal relative rounded-xl overflow-hidden border border-slate-200 bg-slate-900 w-full" data-carousel-orientation="horizontal" data-current-slide="0">
                        <div class="carousel-track flex transition-transform duration-300" style="display: flex;">
                            ${slidesHtml}
                        </div>
                        <div class="carousel-controls absolute inset-0 flex items-center justify-between p-2 pointer-events-none">
                            <button type="button" class="btn-carousel-prev pointer-events-auto bg-slate-900/80 hover:bg-slate-900 text-white rounded-full p-2 text-xs font-bold transition">◀</button>
                            <button type="button" class="btn-carousel-next pointer-events-auto bg-slate-900/80 hover:bg-slate-900 text-white rounded-full p-2 text-xs font-bold transition">▶</button>
                        </div>
                        <div class="carousel-indicator absolute bottom-2 left-1/2 -translate-x-1/2 bg-black/60 text-white text-[10px] px-2.5 py-0.5 rounded-full font-bold">1 / ${photoList.length}</div>
                    </div>
                `;
            }
            else if (mode === 'carousel-vertical') {
                const slidesHtml = photoList.map((url, i) => `
                    <div class="carousel-slide h-full shrink-0">
                        <img src="${url}" class="w-full h-full object-cover block" alt="Photo ${i + 1}">
                    </div>
                `).join('');
                renderArea.innerHTML = `
                    <div class="cms-carousel cms-carousel-vertical relative rounded-xl overflow-hidden border border-slate-200 bg-slate-900 w-full h-64" data-carousel-orientation="vertical" data-current-slide="0">
                        <div class="carousel-track flex flex-col h-full transition-transform duration-300" style="display: flex; flex-direction: column; height: 100%;">
                            ${slidesHtml}
                        </div>
                        <div class="carousel-controls absolute top-2 right-2 flex flex-col gap-1.5 pointer-events-none z-10">
                            <button type="button" class="btn-carousel-prev pointer-events-auto bg-slate-900/80 hover:bg-slate-900 text-white rounded-full p-1.5 text-xs font-bold transition">▲</button>
                            <button type="button" class="btn-carousel-next pointer-events-auto bg-slate-900/80 hover:bg-slate-900 text-white rounded-full p-1.5 text-xs font-bold transition">▼</button>
                        </div>
                        <div class="carousel-indicator absolute bottom-2 left-1/2 -translate-x-1/2 bg-black/60 text-white text-[10px] px-2.5 py-0.5 rounded-full font-bold">1 / ${photoList.length}</div>
                    </div>
                `;
            }
            initCmsMediaInteractions(renderArea);
            syncCanvasToTextarea();
        };
        selectMode?.addEventListener('change', renderPhotoView);
        photoFileInput?.addEventListener('change', async () => {
            const f = photoFileInput.files?.[0];
            if (!f)
                return;
            const fd = new FormData();
            fd.append('file', f);
            const res = await fetchApi('/api/v1/articles/media', { method: 'POST', body: fd });
            if (res.status === 'success' && res.data?.url) {
                photoList.push(res.data.url);
                if (photoList.length > 1 && selectMode && selectMode.value === 'single') {
                    selectMode.value = 'carousel-horizontal';
                }
                renderPhotoView();
                showToast(`Photo #${photoList.length} ajoutée au bloc !`, 'success');
            }
        });
        renderPhotoView();
    }
    initCmsMediaInteractions(blockWrapper);
    return blockWrapper;
}
/**
 * Ajoute un bloc imbriqué dans la colonne cible.
 */
function addNestedBlock(type, initialContent = '', targetDropzone = null) {
    let dropzone = targetDropzone;
    if (!dropzone || !document.body.contains(dropzone)) {
        dropzone = document.querySelector('.column-dropzone');
        if (!dropzone) {
            dropzone = addLayoutRow(1);
        }
    }
    if (!dropzone)
        return;
    const block = createNestedBlockElement(type, initialContent, dropzone);
    dropzone.appendChild(block);
    syncCanvasToTextarea();
}
/**
 * Convertit l'ensemble des rangées multi-colonnes et blocs imbriqués en HTML propre dans #art-content.
 * Fige les styles inline des colonnes asymétriques pour le rendu final.
 */
function syncCanvasToTextarea() {
    const canvas = document.getElementById('builder-blocks-canvas');
    const rawTextarea = document.getElementById('art-content');
    if (!canvas || !rawTextarea)
        return;
    const rowHtmlList = [];
    canvas.querySelectorAll('.builder-row-grid').forEach((grid) => {
        const colsAttr = grid.getAttribute('data-cols') || '1';
        const isAsym = colsAttr === 'asym';
        const colHtmlList = [];
        grid.querySelectorAll('.column-dropzone').forEach((dz) => {
            const blocksHtml = [];
            dz.querySelectorAll('.block-editable').forEach((el) => {
                const videoPlayer = el.querySelector('.custom-video-player');
                const carousel = el.querySelector('.cms-carousel');
                if (videoPlayer) {
                    blocksHtml.push(videoPlayer.outerHTML);
                }
                else if (carousel) {
                    blocksHtml.push(carousel.outerHTML);
                }
                else {
                    const tag = el.tagName.toLowerCase();
                    if (tag === 'div') {
                        blocksHtml.push(el.innerHTML.trim());
                    }
                    else {
                        blocksHtml.push(`<${tag}>${el.innerHTML.trim()}</${tag}>`);
                    }
                }
            });
            colHtmlList.push(`<div>${blocksHtml.join('\n')}</div>`);
        });
        if (isAsym) {
            const ratio = grid.getAttribute('data-ratio') || '30-70';
            const [r1, r2] = ratio.split('-');
            const col1 = colHtmlList[0] || '<div></div>';
            const col2 = colHtmlList[1] || '<div></div>';
            rowHtmlList.push(`
                <div class="cms-row-asym flex flex-col md:flex-row gap-4 w-full" style="display: flex; gap: 1rem; width: 100%;" data-ratio="${ratio}">
                    <div class="cms-col-asym-1" style="flex: 0 0 ${r1}%; width: ${r1}%; max-width: ${r1}%;">${col1}</div>
                    <div class="cms-col-asym-2" style="flex: 0 0 ${r2}%; width: ${r2}%; max-width: ${r2}%;">${col2}</div>
                </div>
            `.trim());
        }
        else {
            const cols = parseInt(colsAttr, 10);
            if (cols === 1) {
                rowHtmlList.push(colHtmlList.join('\n'));
            }
            else if (cols === 2) {
                rowHtmlList.push(`<div class="grid grid-cols-1 md:grid-cols-2 gap-4" style="display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 1rem;">${colHtmlList.join('\n')}</div>`);
            }
            else if (cols === 3) {
                rowHtmlList.push(`<div class="grid grid-cols-1 md:grid-cols-3 gap-4" style="display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 1rem;">${colHtmlList.join('\n')}</div>`);
            }
            else {
                rowHtmlList.push(`<div>${colHtmlList.join('\n')}</div>`);
            }
        }
    });
    rawTextarea.value = rowHtmlList.join('\n');
}
function initPreviewModal() {
    const modal = document.getElementById('article-preview-modal');
    const closeBtn = document.getElementById('btn-close-preview-modal');
    const btn1 = document.getElementById('btn-preview-article');
    const btn2 = document.getElementById('btn-preview-article-bottom');
    const openPreview = () => {
        syncCanvasToTextarea();
        const title = document.getElementById('art-title')?.value.trim() || 'Article sans titre';
        const catSelect = document.getElementById('art-category');
        const catName = catSelect?.options[catSelect.selectedIndex]?.text || 'Vie du club';
        const html = document.getElementById('art-content')?.value || '';
        const titleEl = document.getElementById('preview-modal-title');
        const catEl = document.getElementById('preview-modal-category');
        const bodyEl = document.getElementById('preview-modal-body');
        if (titleEl)
            titleEl.textContent = title;
        if (catEl)
            catEl.textContent = catName;
        if (bodyEl) {
            bodyEl.innerHTML = html;
            initCmsMediaInteractions(bodyEl);
        }
        modal?.classList.remove('hidden');
        modal?.classList.add('flex');
    };
    btn1?.addEventListener('click', openPreview);
    btn2?.addEventListener('click', openPreview);
    closeBtn?.addEventListener('click', () => {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
    });
}
/**
 * Initialise les filtres multi-critères sur l'onglet Catalogue des Publications (Exigence 5).
 */
function initArticlesFilters() {
    const statusSelect = document.getElementById('filter-article-status');
    const catSelect = document.getElementById('filter-article-category');
    const authorSelect = document.getElementById('filter-article-author');
    const resetBtn = document.getElementById('btn-reset-filters');
    statusSelect?.addEventListener('change', () => {
        filterStatus = statusSelect.value;
        renderArticlesList();
    });
    catSelect?.addEventListener('change', () => {
        filterCategory = catSelect.value;
        renderArticlesList();
    });
    authorSelect?.addEventListener('change', () => {
        filterAuthor = authorSelect.value;
        renderArticlesList();
    });
    resetBtn?.addEventListener('click', () => {
        filterStatus = 'all';
        filterCategory = 'all';
        filterAuthor = 'all';
        if (statusSelect)
            statusSelect.value = 'all';
        if (catSelect)
            catSelect.value = 'all';
        if (authorSelect)
            authorSelect.value = 'all';
        renderArticlesList();
    });
}
async function loadArticles() {
    const response = await fetchApi('/api/v1/articles');
    if (response.status !== 'success' || !response.data)
        return;
    allArticles = response.data;
    populateAuthorFilter();
    renderArticlesList();
}
function populateAuthorFilter() {
    const authorSelect = document.getElementById('filter-article-author');
    if (!authorSelect)
        return;
    const uniqueAuthors = new Set();
    allArticles.forEach((art) => {
        const name = (art.author_first_name && art.author_last_name)
            ? `${art.author_first_name} ${art.author_last_name}`
            : (art.created_by ? `Utilisateur #${art.created_by}` : 'Direction');
        uniqueAuthors.add(name);
    });
    const currentVal = authorSelect.value;
    authorSelect.innerHTML = '<option value="all">Tous les auteurs</option>';
    uniqueAuthors.forEach((name) => {
        const opt = document.createElement('option');
        opt.value = name;
        opt.textContent = name;
        authorSelect.appendChild(opt);
    });
    if (currentVal && Array.from(authorSelect.options).some((o) => o.value === currentVal)) {
        authorSelect.value = currentVal;
    }
}
function renderArticlesList() {
    const container = document.getElementById('articles-list');
    const countSpan = document.getElementById('cms-articles-count');
    if (!container)
        return;
    // Filtrage multi-critères
    const filtered = allArticles.filter((art) => {
        if (filterStatus !== 'all' && art.status !== filterStatus)
            return false;
        if (filterCategory !== 'all' && String(art.category_id) !== filterCategory)
            return false;
        if (filterAuthor !== 'all') {
            const authorName = (art.author_first_name && art.author_last_name)
                ? `${art.author_first_name} ${art.author_last_name}`
                : (art.created_by ? `Utilisateur #${art.created_by}` : 'Direction');
            if (authorName !== filterAuthor)
                return false;
        }
        return true;
    });
    if (countSpan)
        countSpan.textContent = String(filtered.length);
    container.innerHTML = '';
    if (filtered.length === 0) {
        container.innerHTML = `
            <div class="p-8 text-center text-slate-400 bg-slate-50 rounded-2xl border border-slate-200">
                <p class="text-sm font-semibold">Aucun article ne correspond aux critères de recherche sélectionnés.</p>
            </div>
        `;
        return;
    }
    const sorted = [...filtered].sort((a, b) => {
        const dateA = a.created_at || String(a.id);
        const dateB = b.created_at || String(b.id);
        if (dateA < dateB)
            return articlesSortOrder === 'asc' ? -1 : 1;
        if (dateA > dateB)
            return articlesSortOrder === 'asc' ? 1 : -1;
        return 0;
    });
    sorted.forEach((art) => {
        const card = document.createElement('div');
        card.className = 'p-5 border border-slate-200 rounded-2xl flex flex-col gap-2.5 bg-slate-50/70 shadow-2xs';
        const isPub = art.status === 'published';
        const statusPill = isPub
            ? '<span class="px-2.5 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-semibold">Publié</span>'
            : '<span class="px-2.5 py-0.5 rounded-full bg-amber-50 text-amber-700 font-semibold">Brouillon</span>';
        const header = document.createElement('div');
        header.className = 'flex items-center justify-between';
        header.innerHTML = `
            <h3 class="font-bold text-slate-900 text-base">${art.title}</h3>
            <span class="article-badge text-xs font-semibold px-2.5 py-0.5 rounded ${art.badge_color}">${art.category_name}</span>
        `;
        const meta = document.createElement('div');
        meta.className = 'flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500';
        meta.innerHTML = `<div class="flex items-center gap-2">${statusPill} <span>• Audience : <strong>${art.visibility}</strong></span></div>`;
        // Traçabilité Auteur et Dernier Modificateur (Exigence 5)
        const authorName = (art.author_first_name && art.author_last_name)
            ? `${art.author_first_name} ${art.author_last_name}`
            : (art.created_by ? `Utilisateur #${art.created_by}` : 'Direction');
        const authorDate = art.created_at ? new Date(art.created_at).toLocaleDateString('fr-FR') : '';
        const editorName = (art.editor_first_name && art.editor_last_name)
            ? `${art.editor_first_name} ${art.editor_last_name}`
            : (art.updated_by ? `Utilisateur #${art.updated_by}` : null);
        const editorDate = art.updated_at ? new Date(art.updated_at).toLocaleDateString('fr-FR') : '';
        const traceability = document.createElement('div');
        traceability.className = 'text-[11px] text-slate-500 flex flex-wrap items-center gap-x-4 gap-y-1 bg-white p-2 rounded-lg border border-slate-100';
        traceability.innerHTML = `
            <span data-testid="article-author" class="article-author font-medium text-slate-700">✍️ Rédigé par <strong>${authorName}</strong>${authorDate ? ` le ${authorDate}` : ''}</span>
            ${editorName ? `<span data-testid="article-editor" class="article-editor text-slate-500">✏️ Dernière modification par <strong>${editorName}</strong>${editorDate ? ` le ${editorDate}` : ''}</span>` : ''}
        `;
        const btnGroup = document.createElement('div');
        btnGroup.className = 'flex items-center gap-2';
        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'px-3 py-1 rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold';
        editBtn.textContent = '✏️ Modifier dans le Builder';
        editBtn.addEventListener('click', () => {
            document.getElementById('art-edit-id').value = String(art.id);
            document.getElementById('art-title').value = art.title;
            document.getElementById('art-category').value = String(art.category_id || 1);
            document.getElementById('art-visibility').value = art.visibility;
            document.getElementById('art-status').value = art.status;
            const canvas = document.getElementById('builder-blocks-canvas');
            if (canvas) {
                canvas.innerHTML = '';
                const col = addLayoutRow(1);
                if (col)
                    addNestedBlock('raw', art.content, col);
            }
            const formTitle = document.getElementById('article-form-title');
            if (formTitle)
                formTitle.textContent = `Modifier l'article #${art.id}`;
            document.getElementById('btn-cancel-edit-article')?.classList.remove('hidden');
            document.getElementById('cms-tab-btn-editor')?.click();
        });
        btnGroup.appendChild(editBtn);
        if (canPublishArticles) {
            const toggleBtn = document.createElement('button');
            toggleBtn.type = 'button';
            const nextStatus = isPub ? 'draft' : 'published';
            toggleBtn.className = 'px-3 py-1 rounded-lg bg-blue-50 hover:bg-blue-100 text-blue-700 font-semibold';
            toggleBtn.textContent = isPub ? 'Dépublier' : 'Publier';
            toggleBtn.addEventListener('click', async () => {
                const res = await fetchApi(`/api/v1/articles/${art.id}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ status: nextStatus })
                });
                if (res.status === 'success') {
                    showToast(`Article passé en "${nextStatus}".`, 'success');
                    await loadArticles();
                }
            });
            const delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'px-3 py-1 rounded-lg bg-red-50 hover:bg-red-100 text-red-600 font-semibold';
            delBtn.textContent = 'Supprimer';
            delBtn.addEventListener('click', () => {
                openConfirmModal('Supprimer l\'article', `Confirmez-vous la suppression définitive de "${art.title}" ?`, async () => {
                    const res = await fetchApi(`/api/v1/articles/${art.id}`, { method: 'DELETE' });
                    if (res.status === 'success') {
                        showToast('Article supprimé.', 'info');
                        await loadArticles();
                    }
                });
            });
            btnGroup.appendChild(toggleBtn);
            btnGroup.appendChild(delBtn);
        }
        meta.appendChild(btnGroup);
        const body = document.createElement('div');
        body.className = 'text-sm text-slate-700 pt-2 border-t border-slate-200/60';
        body.innerHTML = art.content;
        initCmsMediaInteractions(body);
        card.appendChild(header);
        card.appendChild(traceability);
        card.appendChild(meta);
        card.appendChild(body);
        container.appendChild(card);
    });
}
function initArticleForm() {
    const form = document.getElementById('article-form');
    const cancelBtn = document.getElementById('btn-cancel-edit-article');
    const saveBtn = document.getElementById('btn-save-article');
    if (!form)
        return;
    const resetEditMode = () => {
        form.reset();
        document.getElementById('art-edit-id').value = '';
        const canvas = document.getElementById('builder-blocks-canvas');
        if (canvas) {
            canvas.innerHTML = '';
            const col = addLayoutRow(1);
            if (col)
                addNestedBlock('p', 'Rédigez votre actualité ici...', col);
        }
        const formTitle = document.getElementById('article-form-title');
        if (formTitle)
            formTitle.textContent = 'Composer un Article';
        cancelBtn?.classList.add('hidden');
    };
    cancelBtn?.addEventListener('click', resetEditMode);
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        syncCanvasToTextarea();
        const editId = document.getElementById('art-edit-id').value.trim();
        const title = document.getElementById('art-title').value.trim();
        const categoryId = parseInt(document.getElementById('art-category').value, 10);
        const visibility = document.getElementById('art-visibility').value;
        const status = document.getElementById('art-status').value;
        const content = document.getElementById('art-content').value.trim();
        if (!title || title.length < 3) {
            showToast('Veuillez saisir un titre d\'article d\'au moins 3 caractères.', 'error');
            return;
        }
        if (!content) {
            showToast('Le contenu de l\'article ne peut pas être vide.', 'error');
            return;
        }
        setButtonLoading(saveBtn, true, 'Enregistrer l\'article');
        const endpoint = editId ? `/api/v1/articles/${editId}` : '/api/v1/articles';
        const method = editId ? 'PUT' : 'POST';
        try {
            const res = await fetchApi(endpoint, {
                method,
                body: JSON.stringify({ title, category_id: categoryId, visibility, status, content })
            });
            if (res.status === 'success') {
                showToast(editId ? 'Article mis à jour !' : 'Article enregistré !', 'success');
                resetEditMode();
                await loadArticles();
            }
        }
        finally {
            setButtonLoading(saveBtn, false, 'Enregistrer l\'article');
        }
    });
}
function initLegalPagesEditor() {
    const slugSelect = document.getElementById('page-slug-select');
    const titleInput = document.getElementById('page-title');
    const statusSelect = document.getElementById('page-status');
    const contentArea = document.getElementById('page-content');
    const previewLink = document.getElementById('link-preview-page');
    const form = document.getElementById('legal-page-form');
    const saveBtn = document.getElementById('btn-save-legal-page');
    if (!slugSelect || !titleInput || !statusSelect || !contentArea || !form)
        return;
    const loadSelectedPage = async () => {
        const slug = slugSelect.value;
        if (previewLink)
            previewLink.href = `/${slug}`;
        const res = await fetchApi(`/api/v1/pages/${slug}`);
        if (res.status === 'success' && res.data) {
            titleInput.value = res.data.title;
            statusSelect.value = res.data.status;
            contentArea.value = res.data.content;
        }
    };
    slugSelect.addEventListener('change', loadSelectedPage);
    loadSelectedPage();
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const titleVal = titleInput.value.trim();
        const contentVal = contentArea.value.trim();
        if (!titleVal) {
            showToast('Veuillez saisir un titre pour cette page.', 'error');
            return;
        }
        if (!contentVal) {
            showToast('Le contenu de la page ne peut pas être vide.', 'error');
            return;
        }
        setButtonLoading(saveBtn, true, 'Sauvegarder la page');
        const slug = slugSelect.value;
        try {
            const res = await fetchApi(`/api/v1/pages/${slug}`, {
                method: 'PUT',
                body: JSON.stringify({
                    title: titleVal,
                    status: statusSelect.value,
                    content: contentVal
                })
            });
            if (res.status === 'success') {
                showToast(`Page /${slug} mise à jour !`, 'success');
            }
        }
        finally {
            setButtonLoading(saveBtn, false, 'Sauvegarder la page');
        }
    });
}
function initMediaCleanup() {
    const btn = document.getElementById('btn-cleanup-orphans');
    if (!btn)
        return;
    btn.addEventListener('click', () => {
        openConfirmModal('Nettoyer les médias orphelins', 'Voulez-vous analyser et supprimer tous les fichiers de /uploads/ qui ne sont référencés dans aucun article ?', async () => {
            setButtonLoading(btn, true, 'Nettoyage...');
            try {
                const res = await fetchApi('/api/v1/articles/media/cleanup', {
                    method: 'POST'
                });
                if (res.status === 'success') {
                    const count = res.data?.cleaned_files_count ?? 0;
                    showToast(`Nettoyage terminé : ${count} média(s) orphelin(s) supprimé(s).`, 'success');
                }
            }
            finally {
                setButtonLoading(btn, false, '🧹 Nettoyer médias orphelins');
            }
        });
    });
}
function initArticlesSort() {
    const sortBtn = document.getElementById('btn-sort-articles');
    if (!sortBtn)
        return;
    sortBtn.addEventListener('click', () => {
        articlesSortOrder = articlesSortOrder === 'asc' ? 'desc' : 'asc';
        sortBtn.textContent = articlesSortOrder === 'asc' ? 'Tri : Plus anciens d\'abord ▲' : 'Tri : Plus récents d\'abord ▼';
        renderArticlesList();
    });
}
