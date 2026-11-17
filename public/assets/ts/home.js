/**
 * @file home.ts
 * @description Charge dynamiquement les actualités publiées sur la vitrine d'accueil (/),
 * permet le filtrage par catégorie et l'ouverture d'un article en modale de lecture.
 */
import { fetchApi } from './api.js';
import { initCmsMediaInteractions } from './cms-media.js';
let allPublishedArticles = [];
document.addEventListener('DOMContentLoaded', () => {
    loadHomeArticles();
    initCategoryFilters();
    initReaderModal();
});
async function loadHomeArticles() {
    const grid = document.getElementById('home-articles-grid');
    if (!grid)
        return;
    const res = await fetchApi('/api/v1/articles');
    if (res.status !== 'success' || !Array.isArray(res.data))
        return;
    allPublishedArticles = res.data.filter((a) => a.status === 'published');
    renderArticlesGrid(allPublishedArticles);
}
function renderArticlesGrid(articles) {
    const grid = document.getElementById('home-articles-grid');
    if (!grid)
        return;
    if (articles.length === 0) {
        grid.innerHTML = `
            <div class="col-span-full p-8 text-center text-sm text-slate-500 bg-slate-50 rounded-xl border border-slate-200">
                Aucun article publié dans cette catégorie pour le moment.
            </div>
        `;
        return;
    }
    grid.innerHTML = '';
    articles.forEach((art) => {
        const card = document.createElement('article');
        card.className = 'p-6 rounded-2xl border border-slate-200 bg-white flex flex-col justify-between hover:shadow-md hover:border-emerald-300 transition cursor-pointer group';
        const visibilityLabel = art.visibility === 'public'
            ? 'Public'
            : (art.visibility === 'members' ? 'Espace Adhérents' : 'Interne Bureau');
        card.innerHTML = `
            <div>
                <div class="flex items-center justify-between gap-2 mb-3">
                    <span class="article-badge text-xs font-semibold px-2.5 py-0.5 rounded ${art.badge_color}">
                        ${art.category_name}
                    </span>
                    <span class="text-[11px] font-semibold px-2 py-0.5 rounded bg-slate-100 text-slate-600">${visibilityLabel}</span>
                </div>
                <h3 class="font-bold text-slate-900 text-lg mb-2 group-hover:text-emerald-700 transition">${art.title}</h3>
                <div class="text-sm text-slate-600 line-clamp-3 space-y-1">${art.content}</div>
            </div>
            <div class="pt-4 mt-4 border-t border-slate-100 flex items-center justify-between text-xs font-semibold text-emerald-700">
                <span>Lire l'article complet</span>
                <span>→</span>
            </div>
        `;
        card.addEventListener('click', () => openArticleReader(art));
        grid.appendChild(card);
    });
}
function initCategoryFilters() {
    const container = document.getElementById('home-category-filters');
    if (!container)
        return;
    const buttons = container.querySelectorAll('button[data-filter]');
    buttons.forEach((btn) => {
        btn.addEventListener('click', () => {
            buttons.forEach((b) => {
                b.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-100 text-slate-700 hover:bg-slate-200';
            });
            btn.className = 'px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-900 text-white';
            const filter = btn.getAttribute('data-filter') || 'all';
            if (filter === 'all') {
                renderArticlesGrid(allPublishedArticles);
            }
            else {
                renderArticlesGrid(allPublishedArticles.filter((a) => a.category_name === filter));
            }
        });
    });
}
function openArticleReader(art) {
    const modal = document.getElementById('article-reader-modal');
    const badge = document.getElementById('reader-badge');
    const title = document.getElementById('reader-title');
    const content = document.getElementById('reader-content');
    if (!modal || !badge || !title || !content)
        return;
    badge.className = `text-xs font-semibold px-2.5 py-0.5 rounded ${art.badge_color}`;
    badge.textContent = art.category_name;
    title.innerHTML = art.title;
    content.innerHTML = art.content;
    initCmsMediaInteractions(content);
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function initReaderModal() {
    const modal = document.getElementById('article-reader-modal');
    const closeBtn = document.getElementById('btn-close-reader');
    closeBtn?.addEventListener('click', () => {
        modal?.classList.add('hidden');
        modal?.classList.remove('flex');
    });
}
