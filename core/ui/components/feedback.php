<?php

declare(strict_types=1);

/**
 * Composant réutilisable : Conteneur de Toasts (Section 4.3)
 * et Modale de Confirmation pour Actions Destructrices (Section 4.4).
 */
?>
<!-- Conteneur de Toasts non-intrusifs (bas-droite, auto-dismiss 3s) -->
<div id="toast-container" class="fixed bottom-4 right-4 z-50 flex flex-col gap-2 pointer-events-none"></div>

<!-- Modale Globale de Confirmation Destructrice (Section 4.4 : bg-slate-900/60 + bouton bg-red-600) -->
<div id="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md p-6 transform transition-all">
        <div class="flex items-center gap-3 mb-3">
            <div class="w-10 h-10 rounded-full bg-red-50 text-red-600 flex items-center justify-center shrink-0 font-bold">!</div>
            <h3 id="confirm-modal-title" class="text-lg font-bold text-slate-900">Confirmer l'action</h3>
        </div>
        <p id="confirm-modal-message" class="text-sm text-slate-600 mb-6 leading-relaxed">
            Êtes-vous sûr de vouloir effectuer cette action ?
        </p>
        <div class="flex justify-end gap-3 pt-4 border-t border-slate-100">
            <button type="button" id="confirm-modal-cancel" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 transition">
                Annuler
            </button>
            <button type="button" id="confirm-modal-accept" class="px-4 py-2 rounded-lg text-sm font-semibold bg-red-600 text-white hover:bg-red-700 transition shadow-sm flex items-center gap-2">
                Confirmer la suppression
            </button>
        </div>
    </div>
</div>