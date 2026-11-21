/**
 * @file facturation.js
 * @description Pilotage de la page /factures (liste cloisonnée, émission PDF + e-mail auto,
 * téléchargement binaire sécurisé et personnalisation des templates PDF & e-mail).
 */

import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

/**
 * Échappe les caractères HTML pour prévenir toute injection XSS.
 *
 * @param {string} str Chaîne brute.
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

/**
 * Bascule entre les onglets Liste et Templates.
 *
 * @param {'list'|'templates'} tab Onglet cible.
 */
function switchTab(tab) {
    const tabList = document.getElementById('facturation-tab-list');
    const tabTpl = document.getElementById('facturation-tab-templates');
    const panelList = document.getElementById('facturation-panel-list');
    const panelTpl = document.getElementById('facturation-panel-templates');

    const isList = tab === 'list';
    if (panelList) panelList.classList.toggle('hidden', !isList);
    if (panelTpl) panelTpl.classList.toggle('hidden', isList);

    if (tabList) {
        tabList.className = `px-4 py-2 rounded-xl text-xs font-bold transition ${isList ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`;
    }
    if (tabTpl && !tabTpl.classList.contains('hidden')) {
        tabTpl.className = `px-4 py-2 rounded-xl text-xs font-bold transition ${!isList ? 'bg-brand-primary text-white shadow-xs' : 'bg-white border border-slate-200 text-slate-600 hover:bg-slate-50'}`;
    }
}

/**
 * Charge la liste des factures et configure l'affichage gestionnaire si autorisé.
 *
 * @returns {Promise<void>}
 */
async function loadInvoices() {
    const res = await fetchApi('/api/v1/modules/facturation/invoices');
    if (res.status !== 'success' || !res.data) return;

    const canManage = Boolean(res.data.can_manage);
    const invoices = Array.isArray(res.data.invoices) ? res.data.invoices : [];

    const createSec = document.getElementById('facturation-create-section');
    const tabTpl = document.getElementById('facturation-tab-templates');
    if (createSec) createSec.classList.toggle('hidden', !canManage);
    if (tabTpl) tabTpl.classList.toggle('hidden', !canManage);

    if (canManage) {
        await loadTemplates();
    }

    const tbody = document.getElementById('facturation-invoices-tbody');
    if (!tbody) return;

    if (invoices.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-slate-400 italic">Aucune facture disponible pour le moment.</td></tr>';
        return;
    }

    tbody.innerHTML = invoices.map((inv) => {
        const id = Number(inv.id);
        const mailBadge = inv.email_status === 'sent'
            ? '<span class="px-2 py-0.5 rounded-full bg-emerald-50 text-emerald-700 font-bold">Envoyé</span>'
            : (inv.email_status === 'failed'
                ? '<span class="px-2 py-0.5 rounded-full bg-red-50 text-red-700 font-bold">Échec</span>'
                : '<span class="px-2 py-0.5 rounded-full bg-slate-100 text-slate-600 font-bold">En attente</span>');
        const resendBtn = canManage
            ? `<button type="button" data-resend-invoice="${id}" data-testid="btn-resend-invoice-${id}" class="px-2.5 py-1 rounded-lg bg-amber-50 hover:bg-amber-100 text-amber-700 font-bold transition">📧 Renvoyer</button>`
            : '';

        return `
            <tr class="hover:bg-slate-50">
                <td class="p-3 font-bold text-slate-900 invoice-number-badge">${escapeHtml(inv.invoice_number)}</td>
                <td class="p-3">
                    <div class="font-semibold text-slate-800">${escapeHtml(inv.recipient_name)}</div>
                    <div class="text-[11px] text-slate-400">${escapeHtml(inv.recipient_email)}</div>
                </td>
                <td class="p-3"><span class="px-2 py-0.5 rounded bg-slate-100 text-slate-700 uppercase text-[10px] font-bold">${escapeHtml(inv.source_type)}</span></td>
                <td class="p-3 font-extrabold text-slate-900">${Number(inv.total_amount || 0).toFixed(2)} €</td>
                <td class="p-3">${mailBadge}</td>
                <td class="p-3 text-slate-500 whitespace-nowrap">${escapeHtml(String(inv.issued_at || '').slice(0, 10))}</td>
                <td class="p-3 text-right space-x-1 whitespace-nowrap">
                    <button type="button" data-download-invoice="${id}" data-testid="btn-download-invoice-${id}" class="px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white font-bold transition">⬇ PDF</button>
                    ${resendBtn}
                </td>
            </tr>
        `;
    }).join('');

    tbody.querySelectorAll('[data-download-invoice]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = Number(btn.getAttribute('data-download-invoice'));
            if (id > 0) await downloadInvoicePdf(id);
        });
    });

    tbody.querySelectorAll('[data-resend-invoice]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const id = Number(btn.getAttribute('data-resend-invoice'));
            if (id > 0) await resendInvoice(id, btn);
        });
    });
}

/**
 * Charge les templates PDF & e-mail dans l'onglet Paramètres.
 *
 * @returns {Promise<void>}
 */
async function loadTemplates() {
    const res = await fetchApi('/api/v1/modules/facturation/templates');
    if (res.status !== 'success' || !res.data) return;

    const pdf = res.data.pdf_template || {};
    const mail = res.data.email_template || {};

    const setVal = (id, val) => {
        const el = document.getElementById(id);
        if (el && val !== undefined) el.value = String(val);
    };

    setVal('pdf-tpl-prefix', pdf.invoice_prefix);
    setVal('pdf-tpl-header-title', pdf.header_title);
    setVal('pdf-tpl-org-name', pdf.org_name);
    setVal('pdf-tpl-legal-id', pdf.org_legal_id);
    setVal('pdf-tpl-address', pdf.org_address);
    setVal('pdf-tpl-footer', pdf.footer_mentions);
    setVal('email-tpl-subject', mail.subject);
    setVal('email-tpl-body', mail.body_html);
}

/**
 * Télécharge le fichier PDF binaire depuis l'endpoint sécurisé.
 *
 * @param {number} invoiceId Identifiant de la facture.
 * @returns {Promise<void>}
 */
async function downloadInvoicePdf(invoiceId) {
    const res = await fetchApi(`/api/v1/modules/facturation/invoices/${invoiceId}/pdf`);
    if (res.status !== 'success' || !res.data?.pdf_base64) return;

    const raw = atob(String(res.data.pdf_base64));
    const bytes = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }
    const blob = new Blob([bytes], { type: 'application/pdf' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = String(res.data.filename || `facture-${invoiceId}.pdf`);
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
}

/**
 * Renvoie la facture PDF par e-mail via POST /api/v1/modules/facturation/invoices/{id}/send-email.
 *
 * @param {number}            invoiceId Identifiant de la facture.
 * @param {HTMLButtonElement} btn       Bouton déclencheur.
 * @returns {Promise<void>}
 */
async function resendInvoice(invoiceId, btn) {
    setButtonLoading(btn, true, '📧 Renvoyer');
    try {
        const res = await fetchApi(`/api/v1/modules/facturation/invoices/${invoiceId}/send-email`, { method: 'POST' });
        if (res.status === 'success') {
            showToast(res.message || 'Facture renvoyée par e-mail.', 'success');
        }
        await loadInvoices();
    } finally {
        setButtonLoading(btn, false, '📧 Renvoyer');
    }
}

document.addEventListener('DOMContentLoaded', () => {
    loadInvoices();

    document.getElementById('facturation-tab-list')?.addEventListener('click', () => switchTab('list'));
    document.getElementById('facturation-tab-templates')?.addEventListener('click', () => switchTab('templates'));

    const createForm = document.getElementById('facturation-create-form');
    const createBtn = document.getElementById('btn-issue-invoice');
    createForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(createBtn, true, 'Générer PDF & Envoyer par mail');
        try {
            const payload = {
                recipient_name: String(document.getElementById('invoice-recipient-name')?.value || '').trim(),
                recipient_email: String(document.getElementById('invoice-recipient-email')?.value || '').trim(),
                source_type: String(document.getElementById('invoice-source-type')?.value || 'cotisation'),
                items: [
                    {
                        label: String(document.getElementById('invoice-line-label')?.value || '').trim(),
                        qty: 1,
                        unit_price: Number(document.getElementById('invoice-line-amount')?.value || 0)
                    }
                ],
                send_email: true
            };
            const res = await fetchApi('/api/v1/modules/facturation/invoices', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast(res.message || 'Facture générée et envoyée.', 'success');
                createForm.reset();
                await loadInvoices();
            }
        } finally {
            setButtonLoading(createBtn, false, 'Générer PDF & Envoyer par mail');
        }
    });

    const tplForm = document.getElementById('facturation-template-form');
    const tplBtn = document.getElementById('btn-save-invoice-templates');
    tplForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(tplBtn, true, 'Enregistrer les templates PDF & E-mail');
        try {
            const payload = {
                pdf_template: {
                    invoice_prefix: String(document.getElementById('pdf-tpl-prefix')?.value || 'FAC').trim(),
                    header_title: String(document.getElementById('pdf-tpl-header-title')?.value || 'FACTURE OFFICIELLE').trim(),
                    org_name: String(document.getElementById('pdf-tpl-org-name')?.value || '').trim(),
                    org_legal_id: String(document.getElementById('pdf-tpl-legal-id')?.value || '').trim(),
                    org_address: String(document.getElementById('pdf-tpl-address')?.value || '').trim(),
                    footer_mentions: String(document.getElementById('pdf-tpl-footer')?.value || '').trim()
                },
                email_template: {
                    subject: String(document.getElementById('email-tpl-subject')?.value || '').trim(),
                    body_html: String(document.getElementById('email-tpl-body')?.value || '').trim()
                }
            };
            const res = await fetchApi('/api/v1/modules/facturation/templates', {
                method: 'PUT',
                body: JSON.stringify(payload)
            });
            if (res.status === 'success') {
                showToast('Templates PDF & e-mail enregistrés !', 'success');
            }
        } finally {
            setButtonLoading(tplBtn, false, 'Enregistrer les templates PDF & E-mail');
        }
    });
});
