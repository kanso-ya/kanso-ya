<?php

declare(strict_types=1);

$pageTitle     = 'Cotisations & Validation des Dossiers';
$activeNav     = 'registrations';
$requireAuth   = true;
$requiredPerms = ['finances.manage'];
$pageScripts   = ['/assets/ts/registrations.js'];

ob_start();
?>
<div class="space-y-6">
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-amber-600">Espace Trésorerie & Secrétariat</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Cotisations & Dossiers d'Adhésion</h1>
            <p class="text-xs text-slate-500 mt-1">Inspectez les dossiers, validez les règlements ou radiez un dossier (avec retour arrière possible en cas d'erreur).</p>
        </div>
        <div class="flex items-center gap-3">
            <select id="filter-reg-status" class="border border-slate-300 rounded-xl px-3.5 py-2 text-xs font-semibold bg-white">
                <option value="all">Tous les dossiers</option>
                <option value="pending">En attente de validation</option>
                <option value="validated">Dossiers validés</option>
                <option value="radiated">Dossiers radiés (Archivés)</option>
            </select>
            <a href="/register" class="bg-emerald-600 hover:bg-emerald-700 text-white px-4 py-2 rounded-xl text-xs font-bold shadow-xs transition shrink-0">
                + Saisir un dossier
            </a>
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-sm">
                <thead class="bg-slate-50 text-slate-600 text-xs uppercase border-b border-slate-200">
                    <tr>
                        <th class="p-4 cursor-pointer select-none hover:text-slate-900 transition" data-sort="id">Dossier <span class="sort-icon ml-1 opacity-50">⇅</span></th>
                        <th class="p-4 cursor-pointer select-none hover:text-slate-900 transition" data-sort="payer">Payeur / Tuteur <span class="sort-icon ml-1 opacity-50">⇅</span></th>
                        <th class="p-4">Pratiquants inscrits</th>
                        <th class="p-4 cursor-pointer select-none hover:text-slate-900 transition" data-sort="amount">Montant & Règlement <span class="sort-icon ml-1 opacity-50">⇅</span></th>
                        <th class="p-4 cursor-pointer select-none hover:text-slate-900 transition" data-sort="status">Statut <span class="sort-icon ml-1 opacity-50">⇅</span></th>
                        <th class="p-4 text-right">Inspection, Documents & Validation</th>
                    </tr>
                </thead>
                <tbody id="registrations-tbody" class="divide-y divide-slate-100"></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Modale d'Affichage / Téléchargement Reçu & Attestation CSE (Point 20) -->
<div id="document-preview-modal" data-testid="document-preview-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-xl p-6 md:p-8 space-y-5">
        <div class="flex items-start justify-between border-b border-slate-100 pb-3">
            <div>
                <span id="doc-modal-type-badge" class="text-xs font-bold px-2.5 py-0.5 rounded-full bg-blue-50 text-blue-700 uppercase">Document Officiel</span>
                <h3 id="doc-modal-title" class="text-lg font-black text-slate-900 mt-1">Reçu de Cotisation</h3>
            </div>
            <button type="button" id="btn-close-doc-modal" class="text-slate-400 hover:text-slate-700 text-lg font-bold">✕</button>
        </div>
        <div id="doc-modal-body" class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs font-mono text-slate-800 whitespace-pre-wrap leading-relaxed max-h-80 overflow-y-auto"></div>
        <div class="flex items-center justify-between pt-3 border-t border-slate-100">
            <button type="button" id="btn-print-doc" class="px-4 py-2 rounded-xl bg-slate-900 hover:bg-slate-800 text-white text-xs font-bold shadow-xs transition">
                🖨️ Imprimer / Sauvegarder
            </button>
            <button type="button" id="btn-dismiss-doc-modal" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold">
                Fermer
            </button>
        </div>
    </div>
</div>

<!-- Modale d'inspection complète du dossier -->
<div id="inspect-registration-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-2xl p-6 md:p-8 max-h-[90vh] overflow-y-auto space-y-6">
        <div class="flex items-start justify-between border-b border-slate-100 pb-4">
            <div>
                <span id="inspect-reg-status-badge" class="text-xs font-bold px-2.5 py-1 rounded-full"></span>
                <h2 id="inspect-reg-title" class="text-xl font-extrabold text-slate-900 mt-2">Inspection du Dossier</h2>
                <p id="inspect-reg-meta" class="text-xs text-slate-500 mt-0.5"></p>
            </div>
            <button type="button" id="btn-close-inspect-modal" class="text-slate-400 hover:text-slate-700 text-lg font-bold">✕</button>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 p-4 bg-slate-50 rounded-xl border border-slate-200 text-xs">
            <div>
                <span class="text-slate-400 uppercase font-bold block">Compte Payeur / Tuteur</span>
                <strong id="inspect-payer-email" class="text-slate-900 text-sm break-all"></strong>
            </div>
            <div>
                <span class="text-slate-400 uppercase font-bold block">Montant Total Cotisation</span>
                <strong id="inspect-total-amount" class="text-emerald-700 text-base font-black"></strong>
            </div>
            <div>
                <span class="text-slate-400 uppercase font-bold block">Modalité & Échéancier</span>
                <strong id="inspect-payment-info" class="text-slate-900 text-sm"></strong>
            </div>
        </div>

        <div class="space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">
                Pratiquants rattachés & Contacts d'Urgence déclarés
            </h3>
            <div id="inspect-members-list" class="space-y-2.5"></div>
        </div>

        <div class="flex items-center justify-between pt-4 border-t border-slate-100">
            <button type="button" id="btn-cancel-inspect" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-semibold">
                Fermer l'inspection
            </button>
            <button type="button" id="btn-modal-validate-reg" class="px-5 py-2.5 rounded-xl bg-brand-primary text-white text-xs font-bold shadow-sm transition">
                ✓ Valider ce dossier & Envoyer le lien d'activation (48h)
            </button>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';