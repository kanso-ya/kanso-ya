<?php

declare(strict_types=1);

$pageTitle     = 'Console Système & Sauvegardes';
$activeNav     = 'sys-console';
$currentSysNav = 'console';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'settings.manage'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <!-- Barre de sous-navigation Superadmin (Point 27) -->
    <?php require __DIR__ . '/../core/ui/templates/sys-nav.php'; ?>

    <!-- En-tête -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Console Technique & Sauvegardes</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Supervision Système & Sauvegarde SQLite</h1>
            <p class="text-xs text-slate-400 mt-1">Supervisez l'état du serveur, déclenchez une sauvegarde à chaud de la base et créez des Superadmins secondaires.</p>
        </div>
        <div class="flex items-center gap-3">
            <button type="button" id="btn-backup-db" data-testid="btn-backup-db" class="bg-emerald-600 hover:bg-emerald-500 text-white px-4 py-2.5 rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-2">
                💾 Sauvegarder la BDD SQLite
            </button>
        </div>
    </div>

    <!-- Métriques Système & Santé -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800">
            <span class="text-[11px] font-bold uppercase tracking-wider text-slate-400 block">Comptes Utilisateurs</span>
            <span id="metric-users-count" class="text-2xl font-black text-white mt-1 block">...</span>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800">
            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-400 block">Total Adhérents CRM</span>
            <span id="metric-members-count" class="text-2xl font-black text-emerald-400 mt-1 block">...</span>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800">
            <span class="text-[11px] font-bold uppercase tracking-wider text-blue-400 block">Taille BDD SQLite (WAL)</span>
            <span id="metric-db-size" class="text-2xl font-black text-blue-400 mt-1 block">...</span>
        </div>
        <div class="p-5 rounded-2xl bg-slate-900 border border-slate-800">
            <span class="text-[11px] font-bold uppercase tracking-wider text-purple-400 block">Articles Publiés</span>
            <span id="metric-articles-count" class="text-2xl font-black text-purple-400 mt-1 block">...</span>
        </div>
    </div>

    <!-- =====================================================================
         1. IDENTITÉ VISUELLE : NOM DU SITE, LOGO & FILIGRANE DE FOND
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-lg font-bold text-white">1. Identité du Site, Logo & Filigrane de Fond</h2>
            <a href="/sys-branding" class="text-xs font-semibold text-emerald-400 hover:underline">Page dédiée →</a>
        </div>

        <form id="branding-form" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label for="sys-site-name" class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nom officiel du site (Centré en haut) *</label>
                <input type="text" id="sys-site-name" name="site_name" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-white">
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Logo du site (À cheval Header/Sidebar)</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="sys-logo-url" name="site_logo_url" placeholder="/uploads/branding/logo.png ou URL" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white">
                    <label class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-semibold cursor-pointer shrink-0">
                        📁 Uploader
                        <input type="file" id="upload-logo-input" accept="image/*" class="hidden">
                    </label>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Filigrane de fond du site (Watermark)</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="sys-watermark-url" name="site_watermark_url" placeholder="/uploads/branding/watermark.png" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white">
                    <label class="px-3 py-2 bg-slate-800 hover:bg-slate-700 text-white rounded-xl text-xs font-semibold cursor-pointer shrink-0">
                        📁 Uploader
                        <input type="file" id="upload-watermark-input" accept="image/*" class="hidden">
                    </label>
                </div>
                <div class="mt-2 flex items-center gap-2 text-xs text-slate-400">
                    <span>Opacité :</span>
                    <input type="range" id="sys-watermark-opacity" name="site_watermark_opacity" min="0.02" max="0.25" step="0.01" value="0.05" class="w-32">
                </div>
            </div>

            <!-- Palette de Couleurs -->
            <div class="md:col-span-3 pt-4 border-t border-slate-800 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-white">Palette de Couleurs du Site (Réglage Multi-Zones)</h3>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="text-slate-400 self-center mr-1">Préréglages :</span>
                        <button type="button" data-preset='{"primary":"#059669","sidebar":"#0f172a","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-emerald-900/60 text-emerald-300 border border-emerald-700 font-semibold">Émeraude Dojo</button>
                        <button type="button" data-preset='{"primary":"#dc2626","sidebar":"#18181b","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-red-900/60 text-red-300 border border-red-700 font-semibold">Rouge Shogun</button>
                        <button type="button" data-preset='{"primary":"#2563eb","sidebar":"#0f172a","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-blue-900/60 text-blue-300 border border-blue-700 font-semibold">Bleu Océan</button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Zone 1 : Boutons & Accents</span>
                            <span class="text-[11px] text-slate-400">Couleur d'action principale</span>
                        </div>
                        <input type="color" id="sys-color-primary" name="theme_primary_color" value="#059669" class="w-10 h-10 rounded-lg cursor-pointer bg-transparent border-0">
                    </div>
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Zone 2 : Barre latérale (Sidebar)</span>
                            <span class="text-[11px] text-slate-400">Arrière-plan du menu gauche</span>
                        </div>
                        <input type="color" id="sys-color-sidebar" name="theme_sidebar_color" value="#0f172a" class="w-10 h-10 rounded-lg cursor-pointer bg-transparent border-0">
                    </div>
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Zone 3 : Bandeau haut (Header)</span>
                            <span class="text-[11px] text-slate-400">Fond de la barre supérieure</span>
                        </div>
                        <input type="color" id="sys-color-header" name="theme_header_color" value="#ffffff" class="w-10 h-10 rounded-lg cursor-pointer bg-transparent border-0">
                    </div>
                </div>
            </div>

            <div class="md:col-span-3 flex justify-end">
                <button type="submit" id="btn-save-branding" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                    Enregistrer l'identité visuelle & les couleurs
                </button>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         2. SERVEUR SMTP
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <div class="flex items-center justify-between border-b border-slate-800 pb-3">
            <h2 class="text-lg font-bold text-white">2. Serveur d'Envoi d'Emails (Configuration SMTP)</h2>
            <a href="/sys-smtp" class="text-xs font-semibold text-emerald-400 hover:underline">Page dédiée →</a>
        </div>

        <form id="smtp-settings-form" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Hôte SMTP *</label>
                <input type="text" id="smtp-host" name="smtp_host" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Port SMTP *</label>
                <input type="number" id="smtp-port" name="smtp_port" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Protocole de chiffrement</label>
                <select id="smtp-encryption" name="smtp_encryption" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                    <option value="tls">TLS (Standard)</option>
                    <option value="ssl">SSL</option>
                    <option value="none">Aucun (Développement local)</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Identifiant / Compte SMTP *</label>
                <input type="text" id="smtp-username" name="smtp_username" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email Expéditeur (From) *</label>
                <input type="email" id="smtp-from-email" name="smtp_from_email" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nom affiché de l'expéditeur *</label>
                <input type="text" id="smtp-from-name" name="smtp_from_name" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div class="md:col-span-3 flex justify-end">
                <button type="submit" id="btn-save-smtp" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                    Sauvegarder la configuration SMTP
                </button>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         3. CHANGEMENT DE SAISON / ROLLOVER (US 5.1)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-white">3. Bascule Annuelle de Changement de Saison (Rollover)</h2>
                <p class="text-xs text-slate-400">Recalcule automatiquement les catégories d'âge (U10, U14, etc.) et clôture les inscriptions.</p>
            </div>
            <button id="btn-open-sys-rollover-modal" type="button" class="px-4 py-2.5 bg-amber-600 hover:bg-amber-500 text-white rounded-xl text-xs font-bold shadow-xs transition flex items-center gap-2 self-start sm:self-auto">
                <span>🔄 Déclencher la bascule de saison</span>
            </button>
        </div>
    </section>

    <!-- Modale Rollover Système -->
    <div id="sys-rollover-modal" class="fixed inset-0 bg-slate-950/80 backdrop-blur-sm hidden items-center justify-center z-50 p-4">
        <div class="bg-slate-900 border border-slate-800 rounded-3xl max-w-lg w-full p-6 text-slate-100 space-y-5 shadow-2xl">
            <div class="flex items-center gap-3 text-amber-400 border-b border-slate-800 pb-3">
                <span class="text-2xl">⚠️</span>
                <h3 class="text-lg font-bold">Bascule Annuelle de Saison</h3>
            </div>
            <p class="text-xs text-slate-300 leading-relaxed">
                Cette opération archivera tous les dossiers validés de la saison en cours et recalculera les catégories d'âge de l'ensemble des adhérents actifs (sauf mentions forcées).
            </p>
            <div class="space-y-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Nouvelle Saison Cible *</label>
                    <input type="text" id="sys-target-season" placeholder="2027-2028" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-400 mb-1">Date de Référence du Recalcul</label>
                    <input type="date" id="sys-ref-date" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-mono">
                </div>
            </div>
            <div class="flex justify-end gap-3 pt-3 border-t border-slate-800">
                <button type="button" id="btn-cancel-sys-rollover" class="px-4 py-2 rounded-xl text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-300">Annuler</button>
                <button type="button" id="btn-confirm-sys-rollover" class="px-5 py-2 rounded-xl text-xs font-bold bg-amber-600 hover:bg-amber-500 text-white">Confirmer le Rollover</button>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         4. MOTEUR DE MODULES & EXTENSIONS SDK (Lot 3)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-white">4. Moteur de Modules & Extensions SDK</h2>
                <p class="text-xs text-slate-400">Détection automatique dans <code>/modules/</code>, cycle de vie complet et Safe Mode.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="/sys-modules" class="text-xs font-semibold text-emerald-400 hover:underline mr-2">Page dédiée →</a>
                <button id="btn-scan-modules" type="button" class="px-4 py-2 bg-indigo-600 hover:bg-indigo-500 text-white rounded-xl text-xs font-semibold shadow-sm transition flex items-center gap-2">
                    <span>🔄 Scanner les modules</span>
                </button>
            </div>
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">Module</th>
                        <th class="p-3">Version & Auteur</th>
                        <th class="p-3">Compatibilité Core</th>
                        <th class="p-3">Statut</th>
                        <th class="p-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody id="modules-tbody" class="divide-y divide-slate-800/80">
                    <tr>
                        <td colspan="5" class="p-4 text-center text-slate-500">Chargement des modules...</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>

    <!-- Section Superadmins Secondaires (Point 5 / US 1.1) -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-lg font-bold text-white">Comptes Superadmin Déclarés (`sys_superadmins`)</h2>
                <p class="text-xs text-slate-400">Gérez les accès techniques d'administration d'infrastructure.</p>
            </div>
            <button type="button" id="btn-toggle-add-admin" class="bg-slate-800 hover:bg-slate-700 text-white px-3.5 py-1.5 rounded-xl text-xs font-semibold transition">
                + Nouveau Superadmin
            </button>
        </div>

        <!-- Formulaire d'ajout d'un Superadmin -->
        <form id="form-create-superadmin" class="hidden p-5 rounded-xl bg-slate-950 border border-slate-800 space-y-4">
            <h3 class="text-sm font-bold text-emerald-400">Créer un Superadmin secondaire</h3>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Identifiant *</label>
                    <input type="text" id="new-admin-username" name="username" required placeholder="admin_technique" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email *</label>
                    <input type="email" id="new-admin-email" name="email" required placeholder="admin@kanso-ya.local" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Mot de passe (8+ car.) *</label>
                    <input type="password" id="new-admin-password" name="password" required minlength="8" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="btn-cancel-add-admin" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs rounded-xl font-semibold">Annuler</button>
                <button type="submit" id="btn-submit-add-admin" class="px-5 py-2 bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold rounded-xl transition">Créer le compte</button>
            </div>
        </form>

        <!-- Formulaire de modification de son propre mot de passe Superadmin (Point 2) -->
        <form id="form-update-sys-password" class="p-5 rounded-xl bg-slate-950 border border-slate-800 space-y-4">
            <h3 class="text-sm font-bold text-amber-400">Modifier mon mot de passe Superadmin</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Mot de passe actuel *</label>
                    <input type="password" id="sys-current-password" name="current_password" required placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nouveau mot de passe (8+ car., Maj, Min, Chiffre, Symbole) *</label>
                    <input type="password" id="sys-new-password" name="new_password" required minlength="8" placeholder="••••••••" class="w-full bg-slate-900 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
            </div>
            <div class="flex justify-end pt-1">
                <button type="submit" id="btn-submit-sys-password" class="px-5 py-2 bg-amber-600 hover:bg-amber-500 text-white text-xs font-bold rounded-xl transition">
                    Mettre à jour mon mot de passe
                </button>
            </div>
        </form>

        <div class="overflow-x-auto rounded-xl border border-slate-800">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Identifiant</th>
                        <th class="p-3">Email Technique</th>
                        <th class="p-3">Date de création</th>
                        <th class="p-3 text-right">Statut</th>
                    </tr>
                </thead>
                <tbody id="superadmins-tbody" class="divide-y divide-slate-800 font-mono"></tbody>
            </table>
        </div>
    </section>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
