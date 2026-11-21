<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

/**
 * Vue principale du module Facturation PDF Officielle (/factures).
 * - Adhérents : consultation & téléchargement sécurisé de leurs propres factures PDF.
 * - Bureau (finances.manage / Superadmin) : émission manuelle, renvoi par e-mail avec PJ PDF
 *   et personnalisation du template PDF spécifique + template d'e-mail dédié 'invoice_issued'.
 */

$pageTitle   = 'Factures Officielle PDF';
$activeNav   = 'facturation';
$requireAuth = true;
$pageScripts = ['/assets/modules/facturation/facturation.js'];

ob_start();
?>
<link rel="stylesheet" href="/assets/modules/facturation/facturation.css">

<div class="space-y-6">
    <!-- En-tête -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-brand-primary">Module Extension — Comptabilité & Documents</span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-1">🧾 Factures Officielles PDF</h1>
            <p class="text-xs text-slate-500 mt-1">Téléchargez vos factures officielles du club (cotisations, boutique, prestations) et gérez les modèles PDF & e-mail.</p>
        </div>
    </div>

    <!-- Barre d'onglets -->
    <div class="flex items-center gap-2 border-b border-slate-200 pb-2">
        <button type="button" id="facturation-tab-list" data-testid="facturation-tab-list" class="px-4 py-2 rounded-xl text-xs font-bold bg-brand-primary text-white shadow-xs transition">
            📄 Liste des Factures
        </button>
        <button type="button" id="facturation-tab-templates" data-testid="facturation-tab-templates" class="hidden px-4 py-2 rounded-xl text-xs font-bold bg-white border border-slate-200 text-slate-600 hover:bg-slate-50 transition">
            🎨 Templates PDF & E-mail
        </button>
    </div>

    <!-- ONGLET 1 : Émission manuelle (Bureau) + Liste des factures -->
    <div id="facturation-panel-list" class="space-y-6">
        <section id="facturation-create-section" class="hidden bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
            <h2 class="text-base font-bold text-slate-900">➕ Émettre une facture officielle PDF</h2>
            <form id="facturation-create-form" data-testid="facturation-create-form" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div>
                    <label for="invoice-recipient-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom du destinataire *</label>
                    <input type="text" id="invoice-recipient-name" data-testid="invoice-recipient-name" required placeholder="Ex: Marie Dupont" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label for="invoice-recipient-email" class="block text-xs font-semibold uppercase text-slate-600 mb-1">E-mail destinataire *</label>
                    <input type="email" id="invoice-recipient-email" data-testid="invoice-recipient-email" required placeholder="destinataire@mail.fr" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label for="invoice-source-type" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Origine *</label>
                    <select id="invoice-source-type" data-testid="invoice-source-type" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm bg-white">
                        <option value="cotisation">Cotisation / Adhésion</option>
                        <option value="boutique">Commande Boutique</option>
                        <option value="autre">Autre prestation</option>
                    </select>
                </div>
                <div>
                    <label for="invoice-line-label" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Désignation principale *</label>
                    <input type="text" id="invoice-line-label" data-testid="invoice-line-label" required placeholder="Ex: Cotisation saison 2026-2027" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div>
                    <label for="invoice-line-amount" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Montant (€) *</label>
                    <input type="number" step="0.01" min="0" id="invoice-line-amount" data-testid="invoice-line-amount" required value="150.00" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                </div>
                <div class="flex items-end">
                    <button type="submit" id="btn-issue-invoice" data-testid="btn-issue-invoice" class="w-full px-4 py-2.5 rounded-xl bg-brand-primary hover:opacity-90 text-white text-xs font-bold uppercase tracking-wider transition">
                        Générer PDF & Envoyer par mail
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-3">
            <h2 class="text-base font-bold text-slate-900">Historique des Factures Officielles</h2>
            <div class="overflow-x-auto rounded-xl border border-slate-200">
                <table data-testid="facturation-invoices-table" class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-50 text-slate-500 uppercase border-b border-slate-200">
                        <tr>
                            <th class="p-3">Numéro</th>
                            <th class="p-3">Destinataire</th>
                            <th class="p-3">Origine</th>
                            <th class="p-3">Montant Net</th>
                            <th class="p-3">Envoi Mail</th>
                            <th class="p-3">Date</th>
                            <th class="p-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody id="facturation-invoices-tbody" class="divide-y divide-slate-100">
                        <tr><td colspan="7" class="p-4 text-center text-slate-400 italic">Chargement des factures...</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    <!-- ONGLET 2 : Configuration Template PDF spécifique & Template E-mail dédié (Bureau) -->
    <div id="facturation-panel-templates" class="hidden">
        <section class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-6">
            <div>
                <h2 class="text-base font-bold text-slate-900">Paramétrage du Template PDF Officiel & du Modèle d'E-mail</h2>
                <p class="text-xs text-slate-500 mt-0.5">Personnalisez les mentions légales affichées sur vos factures PDF et l'e-mail automatique envoyé avec la pièce jointe.</p>
            </div>

            <form id="facturation-template-form" data-testid="facturation-template-form" class="space-y-6">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label for="pdf-tpl-prefix" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Préfixe numérotation</label>
                        <input type="text" id="pdf-tpl-prefix" value="FAC" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm font-mono">
                    </div>
                    <div>
                        <label for="pdf-tpl-header-title" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Titre du document PDF</label>
                        <input type="text" id="pdf-tpl-header-title" value="FACTURE OFFICIELLE" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                    </div>
                    <div>
                        <label for="pdf-tpl-org-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom de l'association *</label>
                        <input type="text" id="pdf-tpl-org-name" data-testid="pdf-tpl-org-name" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                    </div>
                    <div>
                        <label for="pdf-tpl-legal-id" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Identifiant légal (RNA / SIRET)</label>
                        <input type="text" id="pdf-tpl-legal-id" data-testid="pdf-tpl-legal-id" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="pdf-tpl-address" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Adresse du siège</label>
                        <input type="text" id="pdf-tpl-address" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="pdf-tpl-footer" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Mentions légales de pied de page PDF</label>
                        <input type="text" id="pdf-tpl-footer" data-testid="pdf-tpl-footer" class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm">
                    </div>
                </div>

                <div class="pt-4 border-t border-slate-200 space-y-4">
                    <h3 class="text-sm font-bold text-slate-900">📧 Template d'E-mail Dédié (<code>invoice_issued</code>)</h3>
                    <p class="text-[11px] text-slate-500">Variables disponibles : <code class="text-emerald-700">{{recipient_name}}, {{invoice_number}}, {{total_amount}}, {{club_name}}, {{source_label}}</code></p>
                    <div>
                        <label for="email-tpl-subject" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Sujet de l'e-mail *</label>
                        <input type="text" id="email-tpl-subject" data-testid="email-tpl-subject" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-sm font-semibold">
                    </div>
                    <div>
                        <label for="email-tpl-body" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Corps HTML du message *</label>
                        <textarea id="email-tpl-body" data-testid="email-tpl-body" rows="5" required class="w-full px-3.5 py-2 border border-slate-300 rounded-xl text-xs font-mono"></textarea>
                    </div>
                </div>

                <div class="flex justify-end">
                    <button type="submit" id="btn-save-invoice-templates" data-testid="btn-save-invoice-templates" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold uppercase tracking-wider transition">
                        Enregistrer les templates PDF & E-mail
                    </button>
                </div>
            </form>
        </section>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require dirname(__DIR__, 3) . '/Core/Ui/Templates/layout.php';
