/**
 * @file boutique.js
 * @description Pilotage interactif de la Boutique Club (/boutique — Lot 6 / Sprints 25 & 26) :
 * - Navigation 3 onglets (Catalogue & Panier, Suivi Commandes, Administration & Paramètres)
 * - Recalcul temps réel du prix lors de l'activation du flocage personnalisé
 * - Panier multi-articles (produits avec taille/flocage + lots) et soumission commande hors-ligne
 * - Suivi des commandes & transitions de statuts Bureau (déclenchant la facturation PDF sur 'payee')
 * - Paramétrage Paiements hors-ligne & Livraisons + création produits & lots.
 */

import { fetchApi } from '/assets/ts/api.js';
import { setButtonLoading, showToast } from '/assets/ts/main.js';

/**
 * Formate un montant numérique en euros (fr-FR).
 *
 * @param {number} amount Montant.
 * @returns {string}
 */
function formatEur(amount) {
    return `${Number(amount).toFixed(2).replace('.', ',')} €`;
}

/**
 * Échappe une chaîne HTML.
 *
 * @param {string} str Texte brut.
 * @returns {string}
 */
function escapeHtml(str) {
    return String(str ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/**
 * Parse la chaîne de tailles "S:5, M:10, L:8" en tableau d'objets variantes.
 *
 * @param {string} raw Chaîne brute saisie.
 * @returns {Array<{size_label: string, stock: number}>}
 */
function parseSizesInput(raw) {
    if (!raw.trim()) return [];
    return raw
        .split(',')
        .map((chunk) => chunk.trim())
        .filter(Boolean)
        .map((chunk) => {
            const parts = chunk.split(':');
            const size_label = (parts[0] || '').trim();
            const stock = parts[1] !== undefined ? Math.max(0, parseInt(parts[1].trim(), 10) || 0) : 5;
            return { size_label, stock };
        })
        .filter((v) => v.size_label !== '');
}

const STATUS_META = {
    en_attente_paiement: { label: 'En attente de paiement', cls: 'bg-amber-50 text-amber-800 border-amber-200' },
    payee: { label: 'Payée', cls: 'bg-emerald-50 text-emerald-700 border-emerald-200' },
    en_preparation: { label: 'En préparation', cls: 'bg-blue-50 text-blue-700 border-blue-200' },
    prete_ou_expediee: { label: 'Prête / Expédiée', cls: 'bg-indigo-50 text-indigo-700 border-indigo-200' },
    livree: { label: 'Livrée', cls: 'bg-emerald-100 text-emerald-800 border-emerald-300' },
    annulee: { label: 'Annulée', cls: 'bg-red-50 text-red-700 border-red-200' },
};

const PAYMENT_LABELS = {
    cheque: 'Chèque',
    especes: 'Espèces',
    virement: 'Virement',
};

const DELIVERY_LABELS = {
    retrait_dojo: 'Retrait au dojo',
    remise_main_propre: 'Main propre',
    livraison_postale: 'Livraison postale',
};

/** @type {Array<{item_type: 'product'|'bundle', item_id: number, label: string, size_label: string|null, flocking_text: string|null, unit_price: number, flocking_price: number, quantity: number}>} */
const cartItems = [];

/**
 * Active le recalcul temps réel du prix sur les cases à cocher de flocage.
 */
function initFlockingToggles() {
    document.querySelectorAll('[data-flocking-toggle]').forEach((checkbox) => {
        checkbox.addEventListener('change', () => {
            const pid = checkbox.getAttribute('data-flocking-toggle');
            if (!pid) return;
            const card = document.querySelector(`[data-product-card="${pid}"]`);
            const textInput = document.querySelector(`[data-flocking-text="${pid}"]`);
            const priceEl = document.querySelector(`[data-effective-price-display="${pid}"]`);

            const basePrice = Number(card?.getAttribute('data-base-price') || 0);
            const flockPrice = Number(card?.getAttribute('data-flocking-price') || 0);
            const checked = Boolean(checkbox.checked);

            if (textInput) {
                textInput.classList.toggle('hidden', !checked);
            }
            if (priceEl) {
                priceEl.textContent = formatEur(checked ? basePrice + flockPrice : basePrice);
            }
        });
    });
}

/**
 * Met à jour l'affichage du panier et du total TTC estimé (articles + flocage + livraison postale).
 *
 * @param {number} postalFee Frais de port actuels.
 */
function renderCart(postalFee) {
    const listEl = document.getElementById('cart-items-list');
    const totalEl = document.getElementById('cart-total-display');
    const delivSelect = document.getElementById('cart-delivery-select');
    const addrWrap = document.getElementById('cart-address-wrapper');
    const addrInput = document.getElementById('cart-buyer-address');

    const delivMethod = String(delivSelect?.value || 'retrait_dojo');
    const needsAddress = delivMethod === 'livraison_postale';
    addrWrap?.classList.toggle('hidden', !needsAddress);
    if (addrInput) {
        addrInput.required = needsAddress;
    }

    if (!listEl || !totalEl) return;

    if (cartItems.length === 0) {
        listEl.innerHTML = '<p class="italic text-slate-400">Votre panier est vide. Ajoutez un article ou un lot.</p>';
        totalEl.textContent = formatEur(0);
        return;
    }

    let subtotal = 0;
    listEl.innerHTML = cartItems.map((item, idx) => {
        const lineTotal = (item.unit_price + item.flocking_price) * item.quantity;
        subtotal += lineTotal;
        const metaParts = [];
        if (item.size_label) metaParts.push(`Taille ${escapeHtml(item.size_label)}`);
        if (item.flocking_text) metaParts.push(`Flocage « ${escapeHtml(item.flocking_text)} » (+${formatEur(item.flocking_price)})`);
        return `
            <div class="flex items-start justify-between gap-2 p-2.5 rounded-xl bg-slate-50 border border-slate-200">
                <div class="space-y-0.5">
                    <div class="font-bold text-slate-800">${item.quantity}× ${escapeHtml(item.label)}</div>
                    ${metaParts.length > 0 ? `<div class="text-[11px] text-slate-500">${metaParts.join(' · ')}</div>` : ''}
                </div>
                <div class="text-right shrink-0">
                    <div class="font-bold text-slate-900">${formatEur(lineTotal)}</div>
                    <button type="button" data-remove-cart-idx="${idx}" class="text-[10px] text-red-500 hover:underline">Retirer</button>
                </div>
            </div>
        `;
    }).join('');

    listEl.querySelectorAll('[data-remove-cart-idx]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const idx = Number(btn.getAttribute('data-remove-cart-idx'));
            if (!Number.isNaN(idx)) {
                cartItems.splice(idx, 1);
                renderCart(postalFee);
            }
        });
    });

    const shipping = needsAddress ? postalFee : 0;
    totalEl.textContent = formatEur(subtotal + shipping);
}

/**
 * Charge et affiche le tableau de suivi des commandes (GET /api/v1/modules/boutique/orders).
 */
async function loadOrders() {
    const tbody = document.getElementById('boutique-orders-tbody');
    if (!tbody) return;

    const res = await fetchApi('/api/v1/modules/boutique/orders');
    if (res.status !== 'success' || !res.data) {
        tbody.innerHTML = '<tr><td colspan="7" class="py-6 text-center text-red-500">Impossible de charger les commandes.</td></tr>';
        return;
    }

    const canManage = Boolean(res.data.can_manage);
    const orders = Array.isArray(res.data.orders) ? res.data.orders : [];

    if (orders.length === 0) {
        tbody.innerHTML = `<tr><td colspan="${canManage ? 7 : 6}" class="py-6 text-center text-slate-400 italic">Aucune commande enregistrée pour le moment.</td></tr>`;
        return;
    }

    tbody.innerHTML = orders.map((order) => {
        const oid = Number(order.id);
        const st = STATUS_META[order.status] || { label: order.status, cls: 'bg-slate-100 text-slate-700 border-slate-200' };
        const itemsHtml = (Array.isArray(order.items) ? order.items : []).map((it) => {
            const details = [];
            if (it.size_label) details.push(`T.${escapeHtml(it.size_label)}`);
            if (it.flocking_text) details.push(`Flocage: ${escapeHtml(it.flocking_text)}`);
            return `<div>${Number(it.quantity)}× ${escapeHtml(it.item_label)}${details.length ? ` <span class="text-slate-400">(${details.join(', ')})</span>` : ''}</div>`;
        }).join('');

        const payLabel = PAYMENT_LABELS[order.payment_method] || escapeHtml(order.payment_method);
        const delivLabel = DELIVERY_LABELS[order.delivery_method] || escapeHtml(order.delivery_method);

        const adminCell = canManage ? `
            <td class="py-3">
                <div class="flex items-center gap-1.5">
                    <select data-order-status-select="${oid}" data-testid="order-status-select-${oid}" class="px-2 py-1 border border-slate-300 rounded-lg text-[11px] bg-white">
                        ${Object.entries(STATUS_META).map(([key, meta]) =>
                            `<option value="${key}" ${order.status === key ? 'selected' : ''}>${escapeHtml(meta.label)}</option>`
                        ).join('')}
                    </select>
                    <button type="button" data-update-order-status="${oid}" data-testid="btn-update-order-status-${oid}" class="px-2.5 py-1 rounded-lg bg-slate-900 hover:bg-slate-800 text-white text-[11px] font-bold transition">
                        OK
                    </button>
                </div>
            </td>
        ` : '';

        return `
            <tr data-testid="order-row-${oid}">
                <td class="py-3 pr-3 font-mono font-bold text-slate-900">${escapeHtml(order.order_number)}</td>
                <td class="py-3 pr-3">
                    <div class="font-semibold text-slate-800">${escapeHtml(order.buyer_name)}</div>
                    <div class="text-[11px] text-slate-400">${escapeHtml(order.buyer_email)}</div>
                </td>
                <td class="py-3 pr-3 text-slate-700">${itemsHtml}</td>
                <td class="py-3 pr-3">
                    <div>${payLabel}</div>
                    <div class="text-[11px] text-slate-500">${delivLabel}${Number(order.shipping_amount) > 0 ? ` (+${formatEur(order.shipping_amount)})` : ''}</div>
                </td>
                <td class="py-3 pr-3 font-extrabold text-emerald-700">${formatEur(order.total_amount)}</td>
                <td class="py-3 pr-3">
                    <span data-testid="order-status-badge-${oid}" class="inline-block px-2 py-0.5 rounded-full text-[10px] font-bold border ${st.cls}">
                        ${escapeHtml(st.label)}
                    </span>
                </td>
                ${adminCell}
            </tr>
        `;
    }).join('');

    // Liaison boutons de mise à jour statut (Bureau)
    tbody.querySelectorAll('[data-update-order-status]').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const oid = Number(btn.getAttribute('data-update-order-status'));
            const select = tbody.querySelector(`[data-order-status-select="${oid}"]`);
            const newStatus = String(select?.value || '');
            if (!oid || !newStatus) return;

            setButtonLoading(btn, true, 'OK');
            try {
                const patchRes = await fetchApi(`/api/v1/modules/boutique/orders/${oid}/status`, {
                    method: 'PATCH',
                    body: JSON.stringify({ status: newStatus }),
                });
                if (patchRes.status === 'success') {
                    showToast('Statut de commande mis à jour !', 'success');
                    await loadOrders();
                }
            } finally {
                setButtonLoading(btn, false, 'OK');
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('boutique-app-root');
    let postalFee = Number(root?.getAttribute('data-postal-fee') || 6.5);
    const isAuthenticated = root?.getAttribute('data-is-authenticated') === '1';

    initFlockingToggles();

    // Navigation 3 onglets
    const sectionCatalog = document.getElementById('boutique-section-catalog');
    const sectionOrders = document.getElementById('boutique-section-orders');
    const sectionAdmin = document.getElementById('boutique-admin-panel');
    const tabBtns = document.querySelectorAll('[data-boutique-tab]');
    let bundleProductsLoaded = false;

    /**
     * Charge la liste des produits pour le sélecteur de création de lot.
     */
    async function ensureAdminProductsLoaded() {
        if (bundleProductsLoaded) return;
        bundleProductsLoaded = true;
        const sel = document.getElementById('bundle-existing-product-select');
        if (sel) {
            const res = await fetchApi('/api/v1/modules/boutique/products');
            if (res.status === 'success' && Array.isArray(res.data)) {
                sel.innerHTML = '<option value="">— Aucun article existant —</option>'
                    + res.data.map((p) => `<option value="${Number(p.id)}">${escapeHtml(p.name)}</option>`).join('');
            }
        }
    }

    /**
     * Active un onglet Boutique ('catalog' | 'orders' | 'admin').
     *
     * @param {string} target Onglet cible.
     */
    function switchTab(target) {
        sectionCatalog?.classList.toggle('hidden', target !== 'catalog');
        sectionOrders?.classList.toggle('hidden', target !== 'orders');
        sectionAdmin?.classList.toggle('hidden', target !== 'admin');

        tabBtns.forEach((btn) => {
            const active = btn.getAttribute('data-boutique-tab') === target;
            btn.classList.toggle('bg-slate-900', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('bg-white', !active);
            btn.classList.toggle('border', !active);
            btn.classList.toggle('border-slate-200', !active);
            btn.classList.toggle('text-slate-700', !active);
        });

        if (target === 'orders' && isAuthenticated) {
            void loadOrders();
        } else if (target === 'admin') {
            void ensureAdminProductsLoaded();
        }
    }

    tabBtns.forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-boutique-tab') || 'catalog';
            switchTab(target);
        });
    });

    // Compatibilité Sprint 25 : bouton header btn-toggle-boutique-admin
    document.getElementById('btn-toggle-boutique-admin')?.addEventListener('click', () => {
        const isAdminVisible = sectionAdmin && !sectionAdmin.classList.contains('hidden');
        switchTab(isAdminVisible ? 'catalog' : 'admin');
    });

    document.getElementById('btn-refresh-orders')?.addEventListener('click', () => {
        void loadOrders();
    });

    // Ajout Produit au Panier
    document.querySelectorAll('[data-add-product]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const pid = Number(btn.getAttribute('data-add-product'));
            const card = document.querySelector(`[data-product-card="${pid}"]`);
            if (!card) return;

            const name = card.getAttribute('data-product-name') || `Produit #${pid}`;
            const basePrice = Number(card.getAttribute('data-base-price') || 0);
            const flockPriceCfg = Number(card.getAttribute('data-flocking-price') || 0);
            const sizeEl = card.querySelector(`[data-size-select="${pid}"]`);
            const flockToggle = card.querySelector(`[data-flocking-toggle="${pid}"]`);
            const flockInput = card.querySelector(`[data-flocking-text="${pid}"]`);

            const sizeLabel = sizeEl ? String(sizeEl.value || '').trim() || null : null;
            const wantsFlock = Boolean(flockToggle?.checked);
            const flockText = wantsFlock ? String(flockInput?.value || '').trim() : '';

            if (wantsFlock && flockText === '') {
                showToast('Veuillez saisir le texte à floquer avant d\'ajouter au panier.', 'error');
                flockInput?.focus();
                return;
            }

            cartItems.push({
                item_type: 'product',
                item_id: pid,
                label: name,
                size_label: sizeLabel,
                flocking_text: wantsFlock ? flockText : null,
                unit_price: basePrice,
                flocking_price: wantsFlock ? flockPriceCfg : 0,
                quantity: 1,
            });
            renderCart(postalFee);
            showToast(`${name} ajouté au panier.`, 'success');
        });
    });

    // Ajout Lot / Pack au Panier
    document.querySelectorAll('[data-add-bundle]').forEach((btn) => {
        btn.addEventListener('click', () => {
            const bid = Number(btn.getAttribute('data-add-bundle'));
            const card = document.querySelector(`[data-bundle-card="${bid}"]`);
            if (!card) return;

            const name = card.getAttribute('data-bundle-name') || `Lot #${bid}`;
            const price = Number(card.getAttribute('data-bundle-price') || 0);

            cartItems.push({
                item_type: 'bundle',
                item_id: bid,
                label: name,
                size_label: null,
                flocking_text: null,
                unit_price: price,
                flocking_price: 0,
                quantity: 1,
            });
            renderCart(postalFee);
            showToast(`${name} ajouté au panier.`, 'success');
        });
    });

    document.getElementById('btn-clear-cart')?.addEventListener('click', () => {
        cartItems.length = 0;
        renderCart(postalFee);
    });

    document.getElementById('cart-delivery-select')?.addEventListener('change', () => {
        renderCart(postalFee);
    });
    renderCart(postalFee);

    // Soumission Commande (POST /api/v1/modules/boutique/orders)
    const checkoutForm = document.getElementById('boutique-checkout-form');
    const submitOrderBtn = document.getElementById('btn-submit-order');
    const confirmBox = document.getElementById('cart-order-confirmation');
    checkoutForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (cartItems.length === 0) {
            showToast('Ajoutez au moins un article au panier.', 'error');
            return;
        }
        setButtonLoading(submitOrderBtn, true, 'Valider la commande');
        try {
            const payload = {
                buyer_name: String(document.getElementById('cart-buyer-name')?.value || '').trim(),
                buyer_email: String(document.getElementById('cart-buyer-email')?.value || '').trim(),
                buyer_address: String(document.getElementById('cart-buyer-address')?.value || '').trim(),
                payment_method: String(document.getElementById('cart-payment-select')?.value || 'cheque'),
                delivery_method: String(document.getElementById('cart-delivery-select')?.value || 'retrait_dojo'),
                items: cartItems.map((it) => ({
                    item_type: it.item_type,
                    item_id: it.item_id,
                    size_label: it.size_label,
                    flocking_text: it.flocking_text,
                    quantity: it.quantity,
                })),
            };

            const res = await fetchApi('/api/v1/modules/boutique/orders', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (res.status === 'success' && res.data?.order) {
                const ord = res.data.order;
                cartItems.length = 0;
                renderCart(postalFee);
                if (confirmBox) {
                    confirmBox.classList.remove('hidden');
                    confirmBox.innerHTML = `
                        <div class="font-bold">✅ Commande enregistrée : <span class="font-mono">${escapeHtml(ord.order_number)}</span></div>
                        <div>Total TTC : <strong>${formatEur(ord.total_amount)}</strong> — Statut : En attente de paiement.</div>
                    `;
                }
                showToast(`Commande ${ord.order_number} confirmée !`, 'success');
            }
        } finally {
            setButtonLoading(submitOrderBtn, false, 'Valider la commande');
        }
    });

    // Formulaire Paramètres Boutique (PUT /api/v1/modules/boutique/settings)
    const settingsForm = document.getElementById('boutique-settings-form');
    const saveSettingsBtn = document.getElementById('btn-save-boutique-settings');
    settingsForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(saveSettingsBtn, true, 'Enregistrer les paramètres Boutique');
        try {
            const payment_methods = [];
            if (document.getElementById('setting-pay-cheque')?.checked) payment_methods.push('cheque');
            if (document.getElementById('setting-pay-especes')?.checked) payment_methods.push('especes');
            if (document.getElementById('setting-pay-virement')?.checked) payment_methods.push('virement');

            const delivery_methods = [];
            if (document.getElementById('setting-deliv-dojo')?.checked) delivery_methods.push('retrait_dojo');
            if (document.getElementById('setting-deliv-hand')?.checked) delivery_methods.push('remise_main_propre');
            if (document.getElementById('setting-deliv-postal')?.checked) delivery_methods.push('livraison_postale');

            const shipping_postal_fee = Number(document.getElementById('setting-postal-fee')?.value || 0);

            const res = await fetchApi('/api/v1/modules/boutique/settings', {
                method: 'PUT',
                body: JSON.stringify({ payment_methods, delivery_methods, shipping_postal_fee }),
            });
            if (res.status === 'success') {
                postalFee = shipping_postal_fee;
                showToast('Paramètres Boutique enregistrés !', 'success');
                setTimeout(() => window.location.reload(), 300);
            }
        } finally {
            setButtonLoading(saveSettingsBtn, false, 'Enregistrer les paramètres Boutique');
        }
    });

    // Formulaire création de produit (Bureau — Sprint 25)
    const prodForm = document.getElementById('boutique-create-product-form');
    const prodBtn = document.getElementById('btn-create-product');
    prodForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(prodBtn, true, 'Ajouter le produit');
        try {
            const variants = parseSizesInput(String(document.getElementById('prod-sizes-input')?.value || ''));
            const payload = {
                name: String(document.getElementById('prod-name-input')?.value || '').trim(),
                category: String(document.getElementById('prod-category-input')?.value || 'Équipement').trim(),
                price: Number(document.getElementById('prod-price-input')?.value || 0),
                stock: variants.length === 0 ? 10 : 0,
                availability: String(document.getElementById('prod-availability-select')?.value || 'en_stock'),
                member_discount_type: String(document.getElementById('prod-discount-type-select')?.value || 'none'),
                member_discount_value: Number(document.getElementById('prod-discount-value-input')?.value || 0),
                members_only: Boolean(document.getElementById('prod-members-only-check')?.checked),
                flocking_enabled: Boolean(document.getElementById('prod-flocking-check')?.checked),
                flocking_price: Number(document.getElementById('prod-flocking-price-input')?.value || 0),
                variants,
            };

            const res = await fetchApi('/api/v1/modules/boutique/products', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            if (res.status === 'success') {
                showToast('Produit ajouté au catalogue !', 'success');
                setTimeout(() => window.location.reload(), 300);
            }
        } finally {
            setButtonLoading(prodBtn, false, 'Ajouter le produit');
        }
    });

    // Formulaire création de lot / pack (Bureau — Sprint 25)
    const bundleForm = document.getElementById('boutique-create-bundle-form');
    const bundleBtn = document.getElementById('btn-create-bundle');
    bundleForm?.addEventListener('submit', async (e) => {
        e.preventDefault();
        setButtonLoading(bundleBtn, true, 'Créer le lot');
        try {
            const existingPid = Number(document.getElementById('bundle-existing-product-select')?.value || 0);
            const customName = String(document.getElementById('bundle-custom-item-input')?.value || '').trim();
            const items = [];
            if (existingPid > 0) {
                items.push({ product_id: existingPid, quantity: 1 });
            }
            if (customName !== '') {
                items.push({ custom_item_name: customName, quantity: 1 });
            }

            const payload = {
                name: String(document.getElementById('bundle-name-input')?.value || '').trim(),
                price: Number(document.getElementById('bundle-price-input')?.value || 0),
                members_only: Boolean(document.getElementById('bundle-members-only-check')?.checked),
                items,
            };

            const res = await fetchApi('/api/v1/modules/boutique/bundles', {
                method: 'POST',
                body: JSON.stringify(payload),
            });
            if (res.status === 'success') {
                showToast('Lot créé avec succès !', 'success');
                setTimeout(() => window.location.reload(), 300);
            }
        } finally {
            setButtonLoading(bundleBtn, false, 'Créer le lot');
        }
    });
});
