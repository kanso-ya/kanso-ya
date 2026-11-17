<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Interdiction stricte de l'espace éditorial pour le Superadmin (Point 1 / Architecture)
if (!empty($_SESSION['is_superadmin']) || isset($_SESSION['sys_admin_id'])) {
    http_response_code(403);
    $accessDeniedReason = "Accès refusé (403) : Le compte Superadmin technique n'a pas accès au module éditorial du club.";
    $pageTitle = 'Accès Refusé - Kanso-ya';
    $pageContent = '';
    require_once __DIR__ . '/../core/ui/templates/layout.php';
    exit;
}

$pageTitle     = 'Articles & Publications (CMS)';
$activeNav     = 'cms';
$requireAuth   = true;
$requiredPerms = ['articles.write', 'articles.publish', 'pages.manage'];
$pageScripts   = ['/assets/ts/cms.js'];

ob_start();
?>
<div class="space-y-6">
    <!-- En-tête avec Navigation à 2 Onglets (Point 6) -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">Moteur Éditorial Kanso-ya</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Articles & Pages du Club</h1>
            <p class="text-xs text-slate-500">Basculez entre le Studio de création visuelle et la gestion des publications existantes.</p>
        </div>

        <!-- Les 2 Onglets demandés au Point 6 -->
        <div class="flex items-center bg-slate-100 p-1.5 rounded-xl text-xs font-bold shrink-0">
            <button type="button" id="cms-tab-btn-editor" class="px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition">
                ✏️ 1. Rédaction d'Article (Builder)
            </button>
            <button type="button" id="cms-tab-btn-publications" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                📚 2. Publications & Pages (<span id="cms-articles-count">0</span>)
            </button>
        </div>
    </div>

    <!-- =====================================================================
         ONGLET 1 : STUDIO DE RÉDACTION D'ARTICLE (MULTI-COLONNES, BLOCS IMBRIQUÉS, INFOBULLES & APERÇU)
         ===================================================================== -->
    <section id="cms-tab-panel-editor" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
            <div>
                <h2 id="article-form-title" class="text-xl font-extrabold text-slate-900">Composer un Article</h2>
                <p class="text-xs text-slate-500">Survolez les icônes ⓘ pour obtenir de l'aide sur la mise en page en colonnes et le glisser-déposer.</p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" id="btn-preview-article" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition" title="Visualiser le rendu final de l'article avant publication">
                    👁️ Aperçu de l'article
                </button>
                <button type="button" id="btn-cancel-edit-article" class="hidden text-xs bg-red-50 hover:bg-red-100 text-red-600 px-3.5 py-2 rounded-xl font-semibold">
                    Annuler la modification
                </button>
            </div>
        </div>

        <!-- Guide / Infobulle d'accompagnement pour les rédacteurs peu technophiles (Point 6) -->
        <div class="p-4 rounded-xl bg-blue-50/70 border border-blue-200 text-xs text-blue-950 flex items-start gap-3">
            <span class="text-lg leading-none">💡</span>
            <div class="space-y-1">
                <strong class="font-bold block">Comment utiliser le Studio de mise en page ?</strong>
                <p class="text-blue-800 leading-relaxed">
                    1️⃣ Choisissez d'abord une <strong>disposition de rangée</strong> (1 colonne pleine largeur, 2 colonnes côte à côte ou 3 colonnes).<br>
                    2️⃣ Dans n'importe quelle colonne, cliquez sur <strong>+ Ajouter un bloc</strong> (Titre, Texte, Encadré, Liste, Image) pour imbriquer du contenu.<br>
                    3️⃣ Maintenez la poignée <strong>⠿</strong> d'un bloc avec la souris pour le <strong>glisser-déposer d'une colonne à une autre</strong>, puis cliquez sur <strong>👁️ Aperçu</strong> pour vérifier le résultat !
                </p>
            </div>
        </div>

        <form id="article-form" class="space-y-5">
            <input type="hidden" id="art-edit-id" value="">

            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
                <div class="md:col-span-2">
                    <label for="art-title" class="block text-xs font-semibold uppercase text-slate-600 mb-1">
                        Titre de l'article *
                        <span class="text-slate-400 cursor-help ml-1" title="Saisissez un titre court et accrocheur (entre 3 et 150 caractères).">ⓘ</span>
                    </label>
                    <input type="text" id="art-title" name="title" required placeholder="Ex: Résultats du Tournoi Régional..." class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-semibold">
                </div>
                <div>
                    <label for="art-category" class="block text-xs font-semibold uppercase text-slate-600 mb-1">
                        Catégorie (Badge couleur)
                        <span class="text-slate-400 cursor-help ml-1" title="Définit l'étiquette colorée qui apparaîtra sur la carte de l'article.">ⓘ</span>
                    </label>
                    <select id="art-category" name="category_id" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                        <option value="1">Vie du club (Vert)</option>
                        <option value="2">Compétition (Rouge)</option>
                        <option value="3">Événements (Ambre)</option>
                    </select>
                </div>
                <div>
                    <label for="art-visibility" class="block text-xs font-semibold uppercase text-slate-600 mb-1">
                        Qui peut lire cet article ?
                        <span class="text-slate-400 cursor-help ml-1" title="Public : tout le monde. Adhérents : uniquement les membres connectés. Bureau : interne aux dirigeants.">ⓘ</span>
                    </label>
                    <select id="art-visibility" name="visibility" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                        <option value="public">🌐 Public (Tous les visiteurs)</option>
                        <option value="members">🔒 Adhérents connectés</option>
                        <option value="board">👑 Membres du Bureau uniquement</option>
                    </select>
                </div>
            </div>

            <!-- STUDIO MULTI-COLONNES & BLOCS IMBRIQUÉS (Point 6) -->
            <div class="border border-slate-200 rounded-2xl overflow-hidden bg-slate-50">
                <!-- Barre 1 : Ajout de Rangées Multi-Colonnes + Blocs rapides -->
                <div class="p-3 bg-slate-900 text-white flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 mr-1" title="Ajoute une nouvelle section divisée en 1, 2 ou 3 colonnes">+ Disposition :</span>
                        <button type="button" data-cols="1" class="btn-add-row px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-emerald-600 text-xs font-semibold transition" title="Ajouter une section pleine largeur (1 colonne)">
                            ▭ 1 Colonne
                        </button>
                        <button type="button" data-cols="2" class="btn-add-row px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-emerald-600 text-xs font-semibold transition" title="Ajouter une section divisée en 2 colonnes égales (50% / 50%)">
                            ◫ 2 Colonnes
                        </button>
                        <button type="button" data-cols="3" class="btn-add-row px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-emerald-600 text-xs font-semibold transition" title="Ajouter une section divisée en 3 colonnes (33% / 33% / 33%)">
                            ▤ 3 Colonnes
                        </button>

                        <span class="text-slate-600 mx-1">|</span>
                        <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 mr-1" title="Ajoute un bloc dans la colonne sélectionnée">+ Bloc :</span>
                        <button type="button" data-add-block="h2" class="btn-add-block px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-blue-600 text-xs font-semibold transition" title="Insérer un sous-titre de section">
                            + Titre
                        </button>
                        <button type="button" data-add-block="p" class="btn-add-block px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-blue-600 text-xs font-semibold transition" title="Insérer un bloc de texte / paragraphe">
                            + Texte
                        </button>
                        <button type="button" data-add-block="callout" class="btn-add-block px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-blue-600 text-xs font-semibold transition" title="Insérer un encadré vert mis en valeur">
                            + Encadré Info
                        </button>
                        <button type="button" data-add-block="list" class="btn-add-block px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-blue-600 text-xs font-semibold transition" title="Insérer une liste à puces">
                            + Liste
                        </button>
                        <button type="button" data-add-block="quote" class="btn-add-block px-2.5 py-1.5 rounded-lg bg-slate-800 hover:bg-blue-600 text-xs font-semibold transition" title="Insérer une citation ou un podium">
                            + Citation
                        </button>
                        <label class="px-2.5 py-1.5 rounded-lg bg-emerald-700 hover:bg-emerald-600 text-xs font-semibold transition cursor-pointer" title="Téléverser une photo depuis votre ordinateur">
                            📷 + Photo
                            <input type="file" id="art-media-upload" accept="image/jpeg,image/png,image/webp" class="hidden">
                        </label>
                    </div>

                    <button type="button" id="btn-toggle-html-source" class="text-[11px] font-mono px-2.5 py-1 rounded bg-slate-800 text-slate-300 hover:text-white" title="Afficher ou masquer le code HTML brut (avancé)">
                        &lt;/&gt; HTML
                    </button>
                </div>

                <!-- Barre 2 : Formatage de texte WYSIWYG -->
                <div class="px-3 py-2 bg-slate-100 border-b border-slate-200 flex flex-wrap items-center gap-1 text-xs">
                    <span class="text-[11px] text-slate-500 mr-2 font-semibold">Formatage du texte sélectionné :</span>
                    <button type="button" data-cmd="bold" class="wysiwyg-cmd px-2.5 py-1 rounded bg-white hover:bg-slate-200 font-bold text-slate-800 border border-slate-300" title="Mettre le texte sélectionné en Gras">Gras (B)</button>
                    <button type="button" data-cmd="italic" class="wysiwyg-cmd px-2.5 py-1 rounded bg-white hover:bg-slate-200 italic text-slate-800 border border-slate-300" title="Mettre le texte sélectionné en Italique">Italique (I)</button>
                    <button type="button" data-cmd="underline" class="wysiwyg-cmd px-2.5 py-1 rounded bg-white hover:bg-slate-200 underline text-slate-800 border border-slate-300" title="Souligner le texte sélectionné">Souligné (U)</button>
                    <button type="button" data-cmd="insertUnorderedList" class="wysiwyg-cmd px-2.5 py-1 rounded bg-white hover:bg-slate-200 text-slate-700 border border-slate-300" title="Transformer en liste à puces">• Liste</button>
                    <button type="button" data-cmd="createLink" class="wysiwyg-cmd px-2.5 py-1 rounded bg-white hover:bg-slate-200 text-slate-700 border border-slate-300" title="Ajouter un lien cliquable">🔗 Lien web</button>
                </div>

                <!-- Canevas Multi-Colonnes & Blocs Imbriqués Drag & Drop -->
                <div id="builder-blocks-canvas" class="p-5 space-y-4 min-h-[260px] bg-white"></div>

                <!-- Source HTML synchronisée (#art-content) -->
                <div id="raw-html-wrapper" class="p-3 bg-slate-900 border-t border-slate-800 hidden">
                    <label for="art-content" class="block text-[11px] font-mono uppercase text-emerald-400 mb-1">Code HTML Synchronisé (#art-content)</label>
                    <textarea id="art-content" name="content" rows="4" required class="w-full bg-slate-950 text-emerald-300 font-mono border border-slate-700 rounded-xl p-3 text-xs"></textarea>
                </div>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2">
                <div class="flex items-center gap-3">
                    <label for="art-status" class="text-xs font-semibold uppercase text-slate-600">Statut à l'enregistrement :</label>
                    <select id="art-status" name="status" class="border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-bold bg-white">
                        <option value="published">Publier immédiatement en ligne</option>
                        <option value="draft">Enregistrer comme Brouillon</option>
                    </select>
                </div>
                <div class="flex gap-3">
                    <button type="button" id="btn-preview-article-bottom" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition">
                        👁️ Aperçu avant publication
                    </button>
                    <button type="submit" id="btn-save-article" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-xl text-sm font-bold shadow-sm transition">
                        Enregistrer l'article
                    </button>
                </div>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         ONGLET 2 : PUBLICATIONS EXISTANTES & PAGES LÉGALES (Point 6)
         ===================================================================== -->
    <div id="cms-tab-panel-publications" class="hidden space-y-8">
        <section class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-slate-100 pb-3">
                <div>
                    <h2 class="text-xl font-extrabold text-slate-900">Catalogue des Articles (Publiés & Brouillons)</h2>
                    <p class="text-xs text-slate-500">Modifiez un article dans le Builder, changez son statut de publication ou supprimez-le.</p>
                </div>
                <div class="flex items-center gap-2">
                    <button type="button" id="btn-sort-articles" data-testid="btn-sort-articles" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition flex items-center gap-1.5" title="Basculer l'ordre chronologique des articles">
                        Tri : Plus récents d'abord ▼
                    </button>
                    <button type="button" id="btn-cleanup-orphans" data-testid="btn-cleanup-orphans" class="px-3.5 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition flex items-center gap-1.5" title="Supprime les images de /uploads/ qui ne sont plus utilisées dans aucun article">
                        🧹 Nettoyer médias orphelins
                    </button>
                </div>
            </div>
            <div id="articles-list" class="space-y-3"></div>
        </section>

        <section id="legal-pages-section" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <div>
                <span class="text-xs font-bold uppercase text-purple-600">Pages Institutionnelles (US 4.1)</span>
                <h2 class="text-xl font-bold text-slate-900">Éditeur des Pages Fixes (/rgpd, /cgv, /mentions-legales, /horaires)</h2>
                <p class="text-xs text-slate-500">Une page placée en statut "Brouillon" est automatiquement masquée (404) aux visiteurs.</p>
            </div>

            <form id="legal-page-form" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="page-slug-select" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Page cible</label>
                        <select id="page-slug-select" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                            <option value="rgpd">Politique RGPD (/rgpd)</option>
                            <option value="cgv">Conditions de Vente (/cgv)</option>
                            <option value="mentions-legales">Mentions Légales (/mentions-legales)</option>
                            <option value="horaires">Horaires & Tarifs (/horaires)</option>
                        </select>
                    </div>
                    <div>
                        <label for="page-title" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Titre *</label>
                        <input type="text" id="page-title" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                    </div>
                    <div>
                        <label for="page-status" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Statut</label>
                        <select id="page-status" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                            <option value="published">Publié (Visible)</option>
                            <option value="draft">Brouillon (Masqué 404)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label for="page-content" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Contenu HTML *</label>
                    <textarea id="page-content" rows="4" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm"></textarea>
                </div>
                <div class="flex items-center justify-between">
                    <a id="link-preview-page" href="/rgpd" target="_blank" class="text-xs font-semibold text-emerald-700 hover:underline">
                        Ouvrir la page publique dans un nouvel onglet ↗
                    </a>
                    <button type="submit" id="btn-save-legal-page" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-sm font-semibold transition">
                        Sauvegarder la page
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>

<!-- Modale d'Aperçu en Direct de l'Article (Point 6) -->
<div id="article-preview-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-3xl p-6 md:p-8 max-h-[88vh] overflow-y-auto space-y-5">
        <div class="flex items-center justify-between border-b border-slate-100 pb-3">
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-1 rounded bg-slate-900 text-white text-xs font-bold uppercase">Mode Aperçu Client</span>
                <span id="preview-modal-category" class="text-xs font-semibold px-2.5 py-0.5 rounded bg-emerald-100 text-emerald-800"></span>
            </div>
            <button type="button" id="btn-close-preview-modal" class="text-slate-400 hover:text-slate-700 text-lg font-bold">✕</button>
        </div>
        <h2 id="preview-modal-title" class="text-2xl font-extrabold text-slate-900"></h2>
        <div id="preview-modal-body" class="prose max-w-none text-slate-700 text-sm leading-relaxed space-y-4"></div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';