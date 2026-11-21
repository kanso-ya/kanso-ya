<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$pageTitle     = 'Identité Visuelle & Couleurs';
$activeNav     = 'sys-branding';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'settings.manage'];
$pageScripts   = ['/assets/ts/sys-console.js'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Personnalisation Graphique</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Identité Visuelle, Logo & Palette Dynamique</h1>
            <p class="text-xs text-slate-400 mt-1">Configurez le nom officiel, le logo (à cheval Header/Sidebar), le filigrane de fond et les couleurs d'accentuation.</p>
        </div>
    </div>

    <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-6">
        <form id="branding-form" class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
                <label for="sys-site-name" class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nom officiel du site (Centré en haut) *</label>
                <input type="text" id="sys-site-name" name="site_name" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2.5 text-sm text-white">
            </div>

            <div>
                <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Logo du site (À cheval Header/Sidebar)</label>
                <div class="flex items-center gap-2">
                    <input type="text" id="sys-logo-url" name="site_logo_url" placeholder="/uploads/branding/logo.png" class="flex-1 bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white">
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

            <!-- PALETTE DE COULEURS ET RÉGLAGES MULTI-POINTS -->
            <div class="md:col-span-3 pt-4 border-t border-slate-800 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                    <h3 class="text-sm font-bold text-white">Palette de Couleurs Dynamique</h3>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span class="text-slate-400 self-center mr-1">Préréglages :</span>
                        <button type="button" data-preset='{"primary":"#059669","accent":"#10b981","sidebar":"#0f172a","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-emerald-900/60 text-emerald-300 border border-emerald-700 font-semibold">Émeraude Dojo</button>
                        <button type="button" data-preset='{"primary":"#dc2626","accent":"#f87171","sidebar":"#18181b","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-red-900/60 text-red-300 border border-red-700 font-semibold">Rouge Shogun</button>
                        <button type="button" data-preset='{"primary":"#2563eb","accent":"#3b82f6","sidebar":"#0f172a","header":"#f8fafc"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-blue-900/60 text-blue-300 border border-blue-700 font-semibold">Bleu Océan</button>
                        <button type="button" data-preset='{"primary":"#d97706","accent":"#f59e0b","sidebar":"#1c1917","header":"#ffffff"}' class="preset-palette-btn px-3 py-1 rounded-lg bg-amber-900/60 text-amber-300 border border-amber-700 font-semibold">Or Martial</button>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur Primaire & Boutons</span>
                            <span class="text-[11px] text-slate-400">Actions principales, menus actifs</span>
                        </div>
                        <input type="color" id="sys-color-primary" name="theme_primary_color" value="#059669" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur d'Accentuation</span>
                            <span class="text-[11px] text-slate-400">Badges, liens d'inscription</span>
                        </div>
                        <input type="color" id="sys-color-accent" name="theme_accent_color" value="#10b981" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur du Menu Latéral</span>
                            <span class="text-[11px] text-slate-400">Fond de la barre latérale gauche</span>
                        </div>
                        <input type="color" id="sys-color-sidebar" name="theme_sidebar_color" value="#0f172a" class="w-10 h-10 rounded cursor-pointer bg-transparent border-0">
                    </div>

                    <div class="p-3.5 rounded-xl bg-slate-950 border border-slate-800 flex items-center justify-between">
                        <div>
                            <span class="text-xs font-bold text-white block">Couleur du Bandeau Supérieur</span>
                            <span class="text-[11px] text-slate-400">Fond du bandeau fixe en haut</span>
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
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
