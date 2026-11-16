/**
 * @file cms.ts
 * @description Studio Éditorial à 2 Onglets (Point 6) :
 * - Onglet 1 : Builder Multi-Colonnes (1, 2 ou 3 colonnes) avec Blocs imbriqués dans les colonnes,
 *   Glisser-Déposer (Drag & Drop) inter-colonnes et intra-colonne, Infobulles et Modale d'Aperçu Live
 * - Onglet 2 : Catalogue des Publications et Éditeur des Pages Légales.
 */

import { fetchApi } from './api.js';
import { AuthContext, openConfirmModal, setButtonLoading, showToast } from './main.js';

interface ArticleItem {
    id: number;
    title: string;
    content: string;
    category_id: number;
    category_name: string;
    badge_color: string;
    visibility: string;
    status: string;
    created_by?: number | null;
    updated_by?: number | null;
    author_first_name?: string | null;
    author_last_name?: string | null;
    editor_first_name?: string | null;
    editor_last_name?: string | null;
    created_at?: string;
    updated_at?: string;
}

interface PageItem {
    slug: string;
    title: string;
    content: string;
    status: string;
}

let draggedBlock: HTMLElement | null = null;
let activeColumnTarget: HTMLElement | null = null;
let canPublishArticles = true;
let allArticles: ArticleItem[] = [];
let articlesSortOrder: 'asc' | 'desc' = 'desc';

document.addEventListener('DOMContentLoaded', () => {
    initCmsTabs();
    initMultiColumnBuilder();
    initPreviewModal();
    loadArticles();
    initArticleForm();
    initLegalPagesEditor();
    initMediaCleanup();
    initArticlesSort();

    window.addEventListener('kanso:auth-ready', (e: Event) => {
        const ctx = (e as CustomEvent<AuthContext>).detail;
        canPublishArticles = ctx.permissions.includes('articles.publish') || ctx.role === 'Superadmin';
        const statusSelect = document.getElementById('art-status') as HTMLSelectElement | null;
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

function initCmsTabs(): void {
    const btnEditor = document.getElementById('cms-tab-btn-editor');
    const btnPubs = document.getElementById('cms-tab-btn-publications');
    const panelEditor = document.getElementById('cms-tab-panel-editor');
    const panelPubs = document.getElementById('cms-tab-panel-publications');

    if (!btnEditor || !btnPubs || !panelEditor || !panelPubs) return;

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

/**
 * Initialise le Builder Multi-Colonnes (1, 2 ou 3 colonnes) et les Blocs Imbriqués Drag & Drop (Point 6).
 */
function initMultiColumnBuilder(): void {
    const canvas = document.getElementById('builder-blocks-canvas');
    const rawWrapper = document.getElementById('raw-html-wrapper');
    const rawTextarea = document.getElementById('art-content') as HTMLTextAreaElement | null;
    const toggleHtmlBtn = document.getElementById('btn-toggle-html-source');
    const mediaInput = document.getElementById('art-media-upload') as HTMLInputElement | null;

    if (!canvas || !rawTextarea) return;

    // Crée une première rangée 1 colonne avec un bloc paragraphe initial
    const firstCol = addLayoutRow(1);
    if (firstCol) {
        addNestedBlock('p', 'Rédigez votre actualité ici ou ajoutez des colonnes et des blocs via la barre d\'outils...', firstCol);
    }

    // Boutons "+ Disposition : 1 Colonne / 2 Colonnes / 3 Colonnes"
    document.querySelectorAll<HTMLButtonElement>('.btn-add-row').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cols = parseInt(btn.getAttribute('data-cols') || '1', 10);
            const newCol = addLayoutRow(cols);
            if (newCol) {
                addNestedBlock('p', 'Nouveau bloc dans cette colonne...', newCol);
            }
            showToast(`Rangée de ${cols} colonne(s) ajoutée au canevas.`, 'info');
        });
    });

    // Boutons "+ Bloc" (insère dans la colonne active ou la dernière colonne)
    document.querySelectorAll<HTMLButtonElement>('.btn-add-block').forEach((btn) => {
        btn.addEventListener('click', () => {
            const type = btn.getAttribute('data-add-block') || 'p';
            addNestedBlock(type, '', activeColumnTarget);
        });
    });

    // Commandes WYSIWYG
    document.querySelectorAll<HTMLButtonElement>('.wysiwyg-cmd').forEach((btn) => {
        btn.addEventListener('click', () => {
            const cmd = btn.getAttribute('data-cmd') || '';
            if (cmd === 'createLink') {
                const url = prompt('Adresse du lien (https://...) :', 'https://');
                if (url) document.execCommand('createLink', false, url);
            } else {
                document.execCommand(cmd, false);
            }
            syncCanvasToTextarea();
        });
    });

    mediaInput?.addEventListener('change', async () => {
        const file = mediaInput.files?.[0];
        if (!file) return;
        const fd = new FormData();
        fd.append('file', file);
        const res = await fetchApi<{ url: string }>('/api/v1/articles/media', { method: 'POST', body: fd });
        if (res.status === 'success' && res.data?.url) {
            addNestedBlock('image', res.data.url, activeColumnTarget);
            showToast('Image insérée dans la colonne active !', 'success');
        }
        mediaInput.value = '';
    });

    toggleHtmlBtn?.addEventListener('click', () => {
        rawWrapper?.classList.toggle('hidden');
    });

    rawTextarea.addEventListener('input', () => {
        canvas.innerHTML = '';
        const col = addLayoutRow(1);
        if (col) addNestedBlock('raw', rawTextarea.value, col);
    });
}

/**
 * Ajoute une rangée de 1, 2 ou 3 colonnes dans le canevas et retourne sa première colonne.
 */
function addLayoutRow(colCount: number): HTMLElement | null {
    const canvas = document.getElementById('builder-blocks-canvas');
    if (!canvas) return null;

    const rowWrapper = document.createElement('div');
    rowWrapper.className = 'builder-row p-3 rounded-2xl border-2 border-dashed border-slate-200 bg-slate-50/50 space-y-2';

    const rowHeader = document.createElement('div');
    rowHeader.className = 'flex items-center justify-between text-[11px] text-slate-400 font-bold uppercase px-1';
    rowHeader.innerHTML = `
        <span>Section ${colCount} Colonne(s) — Cliquez dans une colonne pour la cibler ou glissez-y des blocs</span>
        <div class="flex items-center gap-2">
            <button type="button" class="btn-row-up hover:text-slate-900" title="Monter toute cette rangée">▲</button>
            <button type="button" class="btn-row-down hover:text-slate-900" title="Descendre toute cette rangée">▼</button>
            <button type="button" class="btn-row-del hover:text-red-600" title="Supprimer cette rangée">✕ Supprimer la section</button>
        </div>
    `;

    const gridClass = colCount === 3
        ? 'grid grid-cols-1 md:grid-cols-3 gap-4'
        : (colCount === 2 ? 'grid grid-cols-1 md:grid-cols-2 gap-4' : 'grid grid-cols-1 gap-4');

    const gridEl = document.createElement('div');
    gridEl.className = `builder-row-grid ${gridClass}`;
    gridEl.setAttribute('data-cols', String(colCount));

    let firstCol: HTMLElement | null = null;

    for (let i = 0; i < colCount; i++) {
        const colEl = document.createElement('div');
        colEl.className = 'builder-column min-h-[90px] p-3 rounded-xl bg-white border border-slate-200 space-y-2.5 transition';
        colEl.innerHTML = `
            <div class="flex items-center justify-between text-[10px] font-bold uppercase text-slate-400 border-b border-slate-100 pb-1">
                <span>Colonne ${i + 1}</span>
                <button type="button" class="btn-quick-add-in-col text-emerald-600 hover:underline">+ Bloc ici</button>
            </div>
            <div class="column-dropzone space-y-2 min-h-[50px]"></div>
        `;

        const dropzone = colEl.querySelector('.column-dropzone') as HTMLElement;
        if (i === 0) firstCol = dropzone;

        // Sélection de la colonne active au clic
        colEl.addEventListener('click', () => {
            document.querySelectorAll('.builder-column').forEach((c) => c.classList.remove('ring-2', 'ring-emerald-500'));
            colEl.classList.add('ring-2', 'ring-emerald-500');
            activeColumnTarget = dropzone;
        });

        colEl.querySelector('.btn-quick-add-in-col')?.addEventListener('click', (ev: Event) => {
            ev.stopPropagation();
            addNestedBlock('p', 'Nouveau bloc imbriqué...', dropzone);
        });

        // Autorise le dépôt (Drop) d'un bloc glissé depuis n'importe quelle autre colonne
        colEl.addEventListener('dragover', (e: DragEvent) => {
            e.preventDefault();
            colEl.classList.add('bg-emerald-50/40');
        });
        colEl.addEventListener('dragleave', () => {
            colEl.classList.remove('bg-emerald-50/40');
        });
        colEl.addEventListener('drop', (e: DragEvent) => {
            e.preventDefault();
            colEl.classList.remove('bg-emerald-50/40');
            if (draggedBlock) {
                dropzone.appendChild(draggedBlock);
                syncCanvasToTextarea();
            }
        });

        gridEl.appendChild(colEl);
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

    if (firstCol) activeColumnTarget = firstCol;
    syncCanvasToTextarea();
    return firstCol;
}

/**
 * Ajoute un bloc imbriqué dans la colonne cible avec support du Glisser-Déposer (Point 6).
 */
function addNestedBlock(type: string, initialContent = '', targetDropzone: HTMLElement | null = null): void {
    let dropzone = targetDropzone;
    if (!dropzone || !document.body.contains(dropzone)) {
        dropzone = document.querySelector('.column-dropzone') as HTMLElement | null;
        if (!dropzone) {
            dropzone = addLayoutRow(1);
        }
    }
    if (!dropzone) return;

    const blockWrapper = document.createElement('div');
    blockWrapper.draggable = true;
    blockWrapper.className = 'builder-block group relative flex items-start gap-2 p-2.5 rounded-xl border border-slate-200 bg-slate-50/60 hover:bg-white hover:border-emerald-400 transition shadow-2xs';

    let innerHtml = '';
    let label = 'Texte';

    if (type === 'h2') {
        label = 'Sous-Titre';
        innerHtml = `<h2 contenteditable="true" class="block-editable text-lg font-bold text-slate-900 focus:outline-none w-full">${initialContent || 'Titre de la section'}</h2>`;
    } else if (type === 'callout') {
        label = 'Encadré Info';
        innerHtml = `<p contenteditable="true" class="block-editable p-3 bg-emerald-50 border-l-4 border-emerald-600 text-emerald-950 rounded-r-lg text-xs font-medium focus:outline-none w-full">${initialContent || 'Encadré important : pensez à vous munir de votre passeport sportif.'}</p>`;
    } else if (type === 'list') {
        label = 'Liste à puces';
        innerHtml = `<ul contenteditable="true" class="block-editable list-disc pl-5 text-xs text-slate-700 space-y-1 focus:outline-none w-full"><li>${initialContent || 'Premier point important'}</li><li>Second point</li></ul>`;
    } else if (type === 'quote') {
        label = 'Citation / Podium';
        innerHtml = `<blockquote contenteditable="true" class="block-editable pl-3 border-l-4 border-amber-500 italic text-slate-700 text-xs focus:outline-none w-full">${initialContent || '« Bravo à tous nos participants ! »'}</blockquote>`;
    } else if (type === 'image') {
        label = 'Photo';
        innerHtml = `<div class="block-editable w-full"><img src="${initialContent}" alt="Illustration" class="rounded-lg max-h-48 object-cover mb-1"><p contenteditable="true" class="text-[11px] text-slate-500 italic">Légende de la photo</p></div>`;
    } else if (type === 'raw') {
        label = 'Contenu Riche';
        innerHtml = `<div contenteditable="true" class="block-editable text-sm text-slate-700 space-y-2 focus:outline-none w-full">${initialContent}</div>`;
    } else {
        label = 'Paragraphe';
        innerHtml = `<p contenteditable="true" class="block-editable text-xs text-slate-700 leading-relaxed focus:outline-none w-full">${initialContent || 'Cliquez ici pour rédiger votre texte...'}</p>`;
    }

    blockWrapper.innerHTML = `
        <div class="flex flex-col items-center gap-0.5 text-slate-400 select-none shrink-0">
            <span class="cursor-grab active:cursor-grabbing text-sm font-bold px-1 hover:text-slate-800" title="Maintenez cliqué pour déplacer ce bloc dans une autre colonne">⠿</span>
            <button type="button" class="btn-move-up text-[9px] hover:text-slate-900" title="Monter">▲</button>
            <button type="button" class="btn-move-down text-[9px] hover:text-slate-900" title="Descendre">▼</button>
        </div>
        <div class="flex-1 space-y-1">
            <span class="text-[9px] font-bold uppercase tracking-wider text-slate-400 block">${label}</span>
            ${innerHtml}
        </div>
        <button type="button" class="btn-remove-block text-xs text-slate-300 hover:text-red-600 font-bold px-1" title="Supprimer ce bloc">✕</button>
    `;

    blockWrapper.addEventListener('dragstart', (ev: DragEvent) => {
        ev.stopPropagation();
        draggedBlock = blockWrapper;
        blockWrapper.classList.add('opacity-50', 'border-dashed');
    });

    blockWrapper.addEventListener('dragend', () => {
        blockWrapper.classList.remove('opacity-50', 'border-dashed');
        draggedBlock = null;
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

    dropzone.appendChild(blockWrapper);
    syncCanvasToTextarea();
}

/**
 * Convertit l'ensemble des rangées multi-colonnes et blocs imbriqués en HTML propre dans #art-content.
 */
function syncCanvasToTextarea(): void {
    const canvas = document.getElementById('builder-blocks-canvas');
    const rawTextarea = document.getElementById('art-content') as HTMLTextAreaElement | null;
    if (!canvas || !rawTextarea) return;

    const rowHtmlList: string[] = [];

    canvas.querySelectorAll<HTMLElement>('.builder-row-grid').forEach((grid) => {
        const cols = parseInt(grid.getAttribute('data-cols') || '1', 10);
        const colHtmlList: string[] = [];

        grid.querySelectorAll<HTMLElement>('.column-dropzone').forEach((dz) => {
            const blocksHtml: string[] = [];
            dz.querySelectorAll<HTMLElement>('.block-editable').forEach((el) => {
                const tag = el.tagName.toLowerCase();
                if (tag === 'div') {
                    blocksHtml.push(el.innerHTML.trim());
                } else {
                    blocksHtml.push(`<${tag}>${el.innerHTML.trim()}</${tag}>`);
                }
            });
            colHtmlList.push(`<div>${blocksHtml.join('\n')}</div>`);
        });

        if (cols === 1) {
            rowHtmlList.push(colHtmlList.join('\n'));
        } else {
            rowHtmlList.push(`<div>${colHtmlList.join('\n')}</div>`);
        }
    });

    rawTextarea.value = rowHtmlList.join('\n');
}

function initPreviewModal(): void {
    const modal = document.getElementById('article-preview-modal');
    const closeBtn = document.getElementById('btn-close-preview-modal');
    const btn1 = document.getElementById('btn-preview-article');
    const btn2 = document.getElementById('btn-preview-article-bottom');

    const openPreview = (): void => {
        syncCanvasToTextarea();
        const title = (document.getElementById('art-title') as HTMLInputElement)?.value.trim() || 'Article sans titre';
        const catSelect = document.getElementById('art-category') as HTMLSelectElement | null;
        const catName = catSelect?.options[catSelect.selectedIndex]?.text || 'Vie du club';
        const html = (document.getElementById('art-content') as HTMLTextAreaElement)?.value || '';

        const titleEl = document.getElementById('preview-modal-title');
        const catEl = document.getElementById('preview-modal-category');
        const bodyEl = document.getElementById('preview-modal-body');

        if (titleEl) titleEl.textContent = title;
        if (catEl) catEl.textContent = catName;
        if (bodyEl) bodyEl.innerHTML = html;

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

async function loadArticles(): Promise<void> {
    const response = await fetchApi<ArticleItem[]>('/api/v1/articles');
    if (response.status !== 'success' || !response.data) return;

    allArticles = response.data;
    renderArticlesList();
}

function renderArticlesList(): void {
    const container = document.getElementById('articles-list');
    const countSpan = document.getElementById('cms-articles-count');
    if (!container) return;

    if (countSpan) countSpan.textContent = String(allArticles.length);
    container.innerHTML = '';

    const sorted = [...allArticles].sort((a, b) => {
        const dateA = a.created_at || String(a.id);
        const dateB = b.created_at || String(b.id);
        if (dateA < dateB) return articlesSortOrder === 'asc' ? -1 : 1;
        if (dateA > dateB) return articlesSortOrder === 'asc' ? 1 : -1;
        return 0;
    });

    sorted.forEach((art) => {
        const card = document.createElement('div');
        card.className = 'p-5 border border-slate-200 rounded-2xl flex flex-col gap-2 bg-slate-50/70';

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

        const authorName = (art.author_first_name && art.author_last_name)
            ? `${art.author_first_name} ${art.author_last_name}`
            : (art.created_by ? `Utilisateur #${art.created_by}` : 'Direction');
        const authorDate = art.created_at ? new Date(art.created_at).toLocaleDateString('fr-FR') : '';

        const editorName = (art.editor_first_name && art.editor_last_name)
            ? `${art.editor_first_name} ${art.editor_last_name}`
            : (art.updated_by ? `Utilisateur #${art.updated_by}` : null);
        const editorDate = art.updated_at ? new Date(art.updated_at).toLocaleDateString('fr-FR') : '';

        const traceability = document.createElement('div');
        traceability.className = 'text-[11px] text-slate-500 flex flex-wrap items-center gap-x-3 gap-y-1';
        traceability.innerHTML = `
            <span data-testid="article-author" class="article-author font-medium text-slate-600">✍️ Rédigé par <strong>${authorName}</strong>${authorDate ? ` le ${authorDate}` : ''}</span>
            ${editorName ? `<span data-testid="article-editor" class="article-editor text-slate-400">✏️ Dernière modification par <strong>${editorName}</strong>${editorDate ? ` le ${editorDate}` : ''}</span>` : ''}
        `;

        const btnGroup = document.createElement('div');
        btnGroup.className = 'flex items-center gap-2';

        const editBtn = document.createElement('button');
        editBtn.type = 'button';
        editBtn.className = 'px-3 py-1 rounded-lg bg-slate-200 hover:bg-slate-300 text-slate-800 font-semibold';
        editBtn.textContent = '✏️ Modifier dans le Builder';
        editBtn.addEventListener('click', () => {
            (document.getElementById('art-edit-id') as HTMLInputElement).value = String(art.id);
            (document.getElementById('art-title') as HTMLInputElement).value = art.title;
            (document.getElementById('art-category') as HTMLSelectElement).value = String(art.category_id || 1);
            (document.getElementById('art-visibility') as HTMLSelectElement).value = art.visibility;
            (document.getElementById('art-status') as HTMLSelectElement).value = art.status;

            const canvas = document.getElementById('builder-blocks-canvas');
            if (canvas) {
                canvas.innerHTML = '';
                const col = addLayoutRow(1);
                if (col) addNestedBlock('raw', art.content, col);
            }

            const formTitle = document.getElementById('article-form-title');
            if (formTitle) formTitle.textContent = `Modifier l'article #${art.id}`;
            document.getElementById('btn-cancel-edit-article')?.classList.remove('hidden');
            // Bascule automatiquement sur l'onglet 1 (Builder)
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
                openConfirmModal(
                    'Supprimer l\'article',
                    `Confirmez-vous la suppression définitive de "${art.title}" ?`,
                    async () => {
                        const res = await fetchApi(`/api/v1/articles/${art.id}`, { method: 'DELETE' });
                        if (res.status === 'success') {
                            showToast('Article supprimé.', 'info');
                            await loadArticles();
                        }
                    }
                );
            });

            btnGroup.appendChild(toggleBtn);
            btnGroup.appendChild(delBtn);
        }

        meta.appendChild(btnGroup);

        const body = document.createElement('div');
        body.className = 'text-sm text-slate-700 pt-2 border-t border-slate-200/60';
        body.innerHTML = art.content;

        card.appendChild(header);
        card.appendChild(traceability);
        card.appendChild(meta);
        card.appendChild(body);
        container.appendChild(card);
    });
}

function initArticleForm(): void {
    const form = document.getElementById('article-form') as HTMLFormElement | null;
    const cancelBtn = document.getElementById('btn-cancel-edit-article');
    const saveBtn = document.getElementById('btn-save-article') as HTMLButtonElement | null;
    if (!form) return;

    const resetEditMode = (): void => {
        form.reset();
        (document.getElementById('art-edit-id') as HTMLInputElement).value = '';
        const canvas = document.getElementById('builder-blocks-canvas');
        if (canvas) {
            canvas.innerHTML = '';
            const col = addLayoutRow(1);
            if (col) addNestedBlock('p', 'Rédigez votre actualité ici...', col);
        }
        const formTitle = document.getElementById('article-form-title');
        if (formTitle) formTitle.textContent = 'Composer un Article';
        cancelBtn?.classList.add('hidden');
    };

    cancelBtn?.addEventListener('click', resetEditMode);

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        syncCanvasToTextarea();
        setButtonLoading(saveBtn, true, 'Enregistrer l\'article');

        const editId = (document.getElementById('art-edit-id') as HTMLInputElement).value.trim();
        const title = (document.getElementById('art-title') as HTMLInputElement).value.trim();
        const categoryId = parseInt((document.getElementById('art-category') as HTMLSelectElement).value, 10);
        const visibility = (document.getElementById('art-visibility') as HTMLSelectElement).value;
        const status = (document.getElementById('art-status') as HTMLSelectElement).value;
        const content = (document.getElementById('art-content') as HTMLTextAreaElement).value.trim();

        const endpoint = editId ? `/api/v1/articles/${editId}` : '/api/v1/articles';
        const method = editId ? 'PUT' : 'POST';

        try {
            const res = await fetchApi<ArticleItem>(endpoint, {
                method,
                body: JSON.stringify({ title, category_id: categoryId, visibility, status, content })
            });

            if (res.status === 'success') {
                showToast(editId ? 'Article mis à jour !' : 'Article enregistré !', 'success');
                resetEditMode();
                await loadArticles();
            }
        } finally {
            setButtonLoading(saveBtn, false, 'Enregistrer l\'article');
        }
    });
}

function initLegalPagesEditor(): void {
    const slugSelect = document.getElementById('page-slug-select') as HTMLSelectElement | null;
    const titleInput = document.getElementById('page-title') as HTMLInputElement | null;
    const statusSelect = document.getElementById('page-status') as HTMLSelectElement | null;
    const contentArea = document.getElementById('page-content') as HTMLTextAreaElement | null;
    const previewLink = document.getElementById('link-preview-page') as HTMLAnchorElement | null;
    const form = document.getElementById('legal-page-form') as HTMLFormElement | null;
    const saveBtn = document.getElementById('btn-save-legal-page') as HTMLButtonElement | null;

    if (!slugSelect || !titleInput || !statusSelect || !contentArea || !form) return;

    const loadSelectedPage = async (): Promise<void> => {
        const slug = slugSelect.value;
        if (previewLink) previewLink.href = `/${slug}`;

        const res = await fetchApi<PageItem>(`/api/v1/pages/${slug}`);
        if (res.status === 'success' && res.data) {
            titleInput.value = res.data.title;
            statusSelect.value = res.data.status;
            contentArea.value = res.data.content;
        }
    };

    slugSelect.addEventListener('change', loadSelectedPage);
    loadSelectedPage();

    form.addEventListener('submit', async (e: Event) => {
        e.preventDefault();
        setButtonLoading(saveBtn, true, 'Sauvegarder la page');
        const slug = slugSelect.value;

        try {
            const res = await fetchApi(`/api/v1/pages/${slug}`, {
                method: 'PUT',
                body: JSON.stringify({
                    title: titleInput.value.trim(),
                    status: statusSelect.value,
                    content: contentArea.value.trim()
                })
            });

            if (res.status === 'success') {
                showToast(`Page /${slug} mise à jour !`, 'success');
            }
        } finally {
            setButtonLoading(saveBtn, false, 'Sauvegarder la page');
        }
    });
}

function initMediaCleanup(): void {
    const btn = document.getElementById('btn-cleanup-orphans') as HTMLButtonElement | null;
    if (!btn) return;
    btn.addEventListener('click', () => {
        openConfirmModal(
            'Nettoyer les médias orphelins',
            'Voulez-vous analyser et supprimer tous les fichiers de /uploads/ qui ne sont référencés dans aucun article ?',
            async () => {
                setButtonLoading(btn, true, 'Nettoyage...');
                try {
                    const res = await fetchApi<{ cleaned_files_count: number }>('/api/v1/articles/media/cleanup', {
                        method: 'POST'
                    });
                    if (res.status === 'success') {
                        const count = res.data?.cleaned_files_count ?? 0;
                        showToast(`Nettoyage terminé : ${count} média(s) orphelin(s) supprimé(s).`, 'success');
                    }
                } finally {
                    setButtonLoading(btn, false, '🧹 Nettoyer médias orphelins');
                }
            }
        );
    });
}

function initArticlesSort(): void {
    const sortBtn = document.getElementById('btn-sort-articles') as HTMLButtonElement | null;
    if (!sortBtn) return;
    sortBtn.addEventListener('click', () => {
        articlesSortOrder = articlesSortOrder === 'asc' ? 'desc' : 'asc';
        sortBtn.textContent = articlesSortOrder === 'asc' ? 'Tri : Plus anciens d\'abord ▲' : 'Tri : Plus récents d\'abord ▼';
        renderArticlesList();
    });
}