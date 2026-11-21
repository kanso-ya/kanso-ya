<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle   = 'Questionnaire de Santé QS-Sport';
$activeNav   = 'health-check';
$requireAuth = false;
$pageScripts = ['/assets/ts/health-check.js'];

ob_start();
?>
<div class="max-w-3xl mx-auto space-y-6" data-testid="health-check-container">
    <div class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 md:p-8 space-y-4">
        <div class="flex items-center gap-3">
            <span class="p-3 bg-emerald-50 text-emerald-600 rounded-xl">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
            </span>
            <div>
                <h1 class="text-xl font-extrabold text-slate-900">Auto-évaluation Médicale & Questionnaire de Santé</h1>
                <p class="text-xs text-slate-500">Conformément au Code du Sport (Cerfa QS-Sport), répondez aux questions pour savoir si un certificat médical est nécessaire.</p>
            </div>
        </div>

        <!-- Alerte RGPD stricte -->
        <div class="p-4 bg-blue-50 border border-blue-200 rounded-xl text-xs text-blue-900 space-y-1">
            <p class="font-bold flex items-center gap-1.5">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Protection absolue de vos données de santé (RGPD)
            </p>
            <p>Ce questionnaire relève du secret médical. <strong>Aucune de vos réponses individuelles aux questions n'est transmise ni enregistrée sur nos serveurs.</strong> Seul le résultat de votre attestation finale ("Toutes les réponses sont NON") est conservé pour valider votre adhésion.</p>
        </div>

        <!-- Sélecteur Mineur / Majeur -->
        <div class="flex items-center gap-3 p-1 bg-slate-100 rounded-xl" id="health-mode-selector" data-testid="health-mode-selector">
            <button type="button" id="btn-tab-minor" class="flex-1 py-2 text-xs font-bold rounded-lg transition-colors bg-white text-slate-900 shadow-xs">
                Adhérent Mineur (< 18 ans)
            </button>
            <button type="button" id="btn-tab-adult" class="flex-1 py-2 text-xs font-bold rounded-lg transition-colors text-slate-600 hover:text-slate-900">
                Adhérent Majeur (18 ans et +)
            </button>
        </div>

        <!-- Formulaire Mineurs (Cerfa 15699*01) -->
        <div id="cerfa-minor-form" data-testid="cerfa-minor-form" class="space-y-4">
            <h2 class="text-sm font-bold text-slate-800">Questionnaire relatif à l'état de santé du sportif mineur (rempli avec le représentant légal)</h2>
            <div class="space-y-3 text-xs text-slate-700">
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-minor-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Un membre de votre famille a-t-il fait un arrêt cardiaque ou est-il décédé subitement avant l'âge de 50 ans ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-minor-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous ressenti une douleur dans la poitrine ou des palpitations pendant ou après l'effort ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-minor-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous fait un malaise ou perdu connaissance pendant ou après un effort ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-minor-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous eu un problème articulaire, osseux ou musculaire (entorse, fracture...) vous empêchant de pratiquer ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-minor-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous commencé ou changé un traitement médical de longue durée récemment ?</span>
                </label>
            </div>
        </div>

        <!-- Formulaire Majeurs (Cerfa 15699*01 Majeurs) -->
        <div id="cerfa-adult-form" data-testid="cerfa-adult-form" class="hidden space-y-4">
            <h2 class="text-sm font-bold text-slate-800">Questionnaire de santé pour les sportifs majeurs (renouvellement de licence)</h2>
            <div class="space-y-3 text-xs text-slate-700">
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-adult-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Durant les 12 derniers mois, un médecin a-t-il constaté chez vous une pression artérielle trop élevée ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-adult-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Ressentez-vous une oppression, une douleur dans la poitrine ou un essoufflement anormal à l'effort ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-adult-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous eu des vertiges, étourdissements ou pertes de connaissance durant un effort physique ?</span>
                </label>
                <label class="flex items-start gap-3 p-3 rounded-xl border border-slate-200 hover:bg-slate-50 cursor-pointer">
                    <input type="checkbox" class="qs-adult-checkbox mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                    <span>Avez-vous eu une blessure ou intervention chirurgicale non consolidée à ce jour ?</span>
                </label>
            </div>
        </div>

        <!-- Résultat & Attestation sur l'honneur -->
        <div id="health-result-banner" class="p-4 rounded-xl border border-emerald-200 bg-emerald-50 text-xs text-emerald-900 space-y-2">
            <p class="font-bold flex items-center gap-1.5">
                <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                Auto-évaluation Favorable : Aucun certificat médical requis
            </p>
            <p>Si vous avez répondu NON à l'ensemble des questions, aucun certificat médical n'est exigé pour cette saison sportive.</p>
            <label class="flex items-start gap-2.5 pt-2 border-t border-emerald-200/60 cursor-pointer">
                <input type="checkbox" id="health-attestation-checkbox" data-testid="health-attestation-checkbox" class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                <span class="font-semibold">J'atteste sur l'honneur avoir répondu par la négative à l'ensemble des rubriques du questionnaire Cerfa QS-Sport.</span>
            </label>
        </div>

        <!-- Alerte si OUI coché -->
        <div id="health-warning-banner" class="hidden p-4 rounded-xl border border-amber-200 bg-amber-50 text-xs text-amber-900 space-y-2">
            <p class="font-bold flex items-center gap-1.5">
                <svg class="w-4 h-4 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                Certificat médical de non-contre-indication obligatoire
            </p>
            <p>Vous avez répondu OUI à au moins une question. Vous devez obligatoirement consulter votre médecin traitant et fournir un certificat médical datant de moins de 6 mois attestant de l'absence de contre-indication à la pratique sportive.</p>
        </div>

        <div class="flex justify-between items-center pt-4 border-t border-slate-200">
            <a href="/register" class="text-xs font-semibold text-slate-500 hover:text-slate-800">← Retour à l'inscription</a>
            <button type="button" id="btn-confirm-health-attestation" data-testid="btn-confirm-health-attestation" class="px-5 py-2.5 rounded-xl font-bold text-xs text-white bg-emerald-600 hover:bg-emerald-700 shadow-xs">
                Valider mon attestation de santé
            </button>
        </div>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
