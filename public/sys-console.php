<?php

declare(strict_types=1);

$pageTitle     = 'Administration Technique & Design';
$activeNav     = 'sys-console';
$requireAuth   = true;
$requiredPerms = ['system.admin'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-8 text-slate-100">
    <!-- En-tête épuré sans la mention inutile (Point 1) -->
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-extrabold text-white">Administration Technique, Design & Messagerie SMTP</h1>
            <p class="text-xs text-slate-400 mt-1">
                Personnalisez l'identité visuelle du site (Nom, Logo, Filigrane, Couleurs), configurez le serveur d'envoi d'emails et consultez les journaux techniques.
            </p>
        </div>
    </div>

    <!-- =====================================================================
         1. IDENTITÉ VISUELLE : NOM DU SITE, LOGO & FILIGRANE DE FOND (Point 1)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <h2 class="text-lg font-bold text-white border-b border-slate-800 pb-3">1. Identité du Site, Logo & Filigrane de Fond</h2>

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

            <!-- 2. PALETTE DE COULEURS ET RÉGLAGES MULTI-POINTS (Point 1) -->
            <div class="md:col-span-3 pt-4 border-t border-slate-800 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-white">Palette de Couleurs du Site (Réglage Multi-Zones)</h3>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="text-slate-400 self-center mr-1">Préréglages :</span>
                        <button type="button" data-preset='{"primary":"#059669","sidebar":"#0f172a","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-emerald-900/60 text-emerald-300 border border-emerald-700 font-semibold">Émeraude Dojo</button>
                        <button type="button" data-preset='{"primary":"#dc2626","sidebar":"#18181b","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-red-900/60 text-red-300 border border-red-700 font-semibold">Rouge Shogun</button>
                        <button type="button" data-preset='{"primary":"#2563eb","sidebar":"#0f172a","header":"#f8fafc"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-blue-900/60 text-blue-300 border border-blue-700 font-semibold">Bleu Océan</button>
                        <button type="button" data-preset='{"primary":"#d97706","sidebar":"#1c1917","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-amber-900/60 text-amber-300 border border-amber-700 font-semibold">Or Martial</button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur Primaire & Boutons</span>
                            <span class="text-[11px] text-slate-400">Actions principales, menus actifs</span>
                        </div>
                        <input type="color" id="sys-color-primary" name="theme_primary_color" value="#059669" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur de la Sidebar</span>
                            <span class="text-[11px] text-slate-400">Fond de la barre latérale gauche</span>
                        </div>
                        <input type="color" id="sys-color-sidebar" name="theme_sidebar_color" value="#0f172a" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur du Bandeau Supérieur</span>
                            <span class="text-[11px] text-slate-400">Fond du Header fixe en haut</span>
                        </div>
                        <input type="color" id="sys-color-header" name="theme_header_color" value="#ffffff" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>
                </div>
            </div>

            <div class="md:col-span-3 flex justify-end pt-2">
                <button type="submit" id="btn-save-branding" class="bg-emerald-600 hover:bg-emerald-500 text-white px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition shadow">
                    Enregistrer l'identité visuelle & les couleurs
                </button>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         2. CONFIGURATION DU COMPTE MAIL & SERVEUR SMTP + TRAÇABILITÉ (Point 1)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-lg font-bold text-white">2. Configuration Messagerie & Serveur SMTP (Envois Automatiques)</h2>
                <p class="text-xs text-slate-400">Paramétrez le compte d'expédition des invitations OTP et suivez la délivrabilité de chaque email.</p>
            </div>
            <div class="flex items-center gap-2">
                <input type="email" id="test-smtp-recipient" placeholder="destinataire@test.fr" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-1.5 text-xs text-white">
                <button type="button" id="btn-send-test-email" class="bg-blue-600 hover:bg-blue-500 text-white px-3.5 py-1.5 rounded-xl text-xs font-bold transition shrink-0">
                    ✉️ Envoyer un mail de test
                </button>
            </div>
        </div>

        <form id="smtp-settings-form" class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Serveur Hôte SMTP *</label>
                <input type="text" id="smtp-host" name="smtp_host" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
            </div>
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Port SMTP & Chiffrement *</label>
                <div class="grid grid-cols-2 gap-2">
                    <input type="number" id="smtp-port" name="smtp_port" value="587" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white">
                    <select id="smtp-encryption" name="smtp_encryption" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white">
                        <option value="tls">STARTTLS (587)</option>
                        <option value="ssl">SSL/TLS (465)</option>
                        <option value="none">Aucun (25)</option>
                    </select>
                </div>
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
            <div class="flex items-end justify-end">
                <button type="submit" id="btn-save-smtp" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white py-2.5 px-4 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                    Sauvegarder la configuration SMTP
                </button>
            </div>
        </form>

        <!-- Traçabilité des envois d'emails -->
        <div class="pt-4 border-t border-slate-800 space-y-3">
            <h3 class="text-sm font-bold text-slate-300">Historique & Traçabilité des Emails Envoyés (`email_logs`)</h3>
            <div class="overflow-x-auto rounded-xl border border-slate-800 max-h-48">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-2.5">Destinataire</th>
                            <th class="p-2.5">Sujet</th>
                            <th class="p-2.5">Type</th>
                            <th class="p-2.5">Réponse Serveur SMTP</th>
                            <th class="p-2.5">Date d'envoi</th>
                        </tr>
                    </thead>
                    <tbody id="email-logs-tbody" class="divide-y divide-slate-800 font-mono"></tbody>
                </table>
            </div>
        </div>
    </section>

    <!-- =====================================================================
         3. MAINTENANCE & BASCULEMENT DE SAISON (ROLLOVER ANNUEL - US 5.1)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-3">
            <div>
                <h2 class="text-lg font-bold text-white">3. Basculement de Saison Sportive (Rollover Annuel - US 5.1)</h2>
                <p class="text-xs text-slate-400">Recalcule automatiquement les catégories d'âge (grades) pour tous les membres actifs et archive les dossiers antérieurs.</p>
            </div>
            <button type="button" id="btn-open-sys-rollover-modal" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-xs font-bold rounded-xl transition shrink-0">
                🔄 Basculer de saison
            </button>
        </div>
        <p class="text-xs text-slate-400">
            L'opération applique une transaction SQLite immédiate, préserve strictement les surclassements entraîneur (<code>is_category_forced = 1</code>) et inscrit une trace indélébile dans <code>app_logs</code> et <code>sys_logs</code>.
        </p>
    </section>

    <!-- Modale Superadmin Rollover (US 5.1) -->
    <div id="sys-rollover-modal" class="fixed inset-0 bg-black/70 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
        <div class="bg-slate-900 border-2 border-red-500 rounded-2xl max-w-lg w-full p-6 space-y-4 shadow-2xl">
            <h3 class="text-base font-bold text-red-400">⚠️ Confirmation Rollover Superadmin</h3>
            <p class="text-xs text-slate-300">Indiquez la saison cible et la date de référence pour déclencher la moulinette :</p>
            <div class="space-y-3">
                <input type="text" id="sys-target-season" value="2027-2028" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-xs text-white">
                <input type="date" id="sys-ref-date" value="2027-09-01" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-xs text-white">
            </div>
            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="btn-cancel-sys-rollover" class="px-4 py-2 bg-slate-800 text-slate-300 text-xs rounded-xl font-semibold">Annuler</button>
                <button type="button" id="btn-confirm-sys-rollover" class="px-4 py-2 bg-red-600 hover:bg-red-500 text-white text-xs rounded-xl font-bold">Exécuter le Rollover</button>
            </div>
        </div>
    </div>

    <!-- =====================================================================
         4. JOURNAL D'AUDIT TECHNIQUE & SÉCURITÉ RÉEL (sys_logs)
         ===================================================================== -->
    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h2 class="text-lg font-bold text-white">4. Journal Technique & Sécurité en Temps Réel (`sys_logs`)</h2>
                <p class="text-xs text-slate-400">Chaque modification de configuration ou tentative d'accès non autorisée (403) s'inscrit ici en direct.</p>
            </div>
            <input type="search" id="sys-logs-search" placeholder="Filtrer par action, détail ou IP..." class="bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-xs text-white w-full sm:w-64">
        </div>

        <div class="overflow-x-auto rounded-xl border border-slate-800 max-h-64">
            <table class="w-full text-left border-collapse text-xs">
                <thead class="sticky top-0 bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                    <tr>
                        <th class="p-3">ID</th>
                        <th class="p-3">Action</th>
                        <th class="p-3">Détails Techniques</th>
                        <th class="p-3">IP</th>
                        <th class="p-3">Horodatage</th>
                    </tr>
                </thead>
                <tbody id="sys-logs-tbody" class="divide-y divide-slate-800/80 font-mono"></tbody>
            </table>
        </div>
    </section>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';