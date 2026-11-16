<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle   = 'Inscription en Ligne';
$activeNav   = 'register';
$requireAuth = false;
$pageScripts = ['/assets/ts/register.js'];

$db = Database::getInstance();
$settings = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$basePrice  = (float) ($settings['membership_base_price'] ?? 150);
$confirmTxt = (string) ($settings['registration_confirmation_text'] ?? 'Votre dossier a bien été enregistré. Après validation de votre règlement par le Trésorier, vous recevrez un email contenant votre lien d\'activation OTP (valable 48h).');

ob_start();
?>
<div class="max-w-4xl mx-auto space-y-6">

    <!-- Bandeau Restauration de Brouillon Local (Sprint 8 - Point 16) -->
    <div id="draft-restore-banner" class="hidden p-4 rounded-2xl bg-amber-50 border border-amber-200 text-amber-900 flex flex-col sm:flex-row sm:items-center justify-between gap-3 text-xs" data-testid="draft-restore-banner">
        <div>
            <span class="font-bold block">Brouillon d'inscription détecté</span>
            <span>Vous avez un dossier d'adhésion en cours de saisie sur cet appareil. Souhaitez-vous reprendre là où vous en étiez ?</span>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <button type="button" id="btn-restore-draft" data-testid="btn-restore-draft" class="px-3.5 py-1.5 rounded-lg bg-amber-600 hover:bg-amber-700 text-white font-bold transition">
                Restaurer le dossier
            </button>
            <button type="button" id="btn-clear-draft" data-testid="btn-clear-draft" class="px-3.5 py-1.5 rounded-lg bg-white border border-amber-300 text-amber-800 font-bold hover:bg-amber-50 transition">
                Effacer
            </button>
        </div>
    </div>

    <!-- Bandeau Mode Connecté : Réinscription en 1 clic ou Nouvel Adhérent -->
    <div id="connected-re-enrollment-banner" class="hidden bg-emerald-900 text-white p-5 rounded-2xl shadow-sm border border-emerald-700 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-[11px] font-bold uppercase tracking-wider text-emerald-300 block">Espace Famille Connecté</span>
            <h2 class="text-base font-extrabold">Souhaitez-vous réinscrire un membre existant ou inscrire un nouvel adhérent ?</h2>
            <p class="text-xs text-emerald-200 mt-0.5">Sélectionnez un membre rattaché à votre compte pour pré-remplir automatiquement sa fiche et ses disciplines.</p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <select id="select-existing-family-member" class="bg-slate-900 text-white border border-emerald-600 rounded-xl px-3 py-2 text-xs font-semibold">
                <option value="">+ Inscrire un nouvel adhérent</option>
            </select>
        </div>
    </div>

    <!-- Conteneur Principal du Wizard -->
    <div id="registration-wizard-card" class="bg-white rounded-2xl shadow-xs border border-slate-200 p-6 md:p-8" 
         data-base-price="<?= $basePrice ?>"
         data-discount-threshold="<?= (int) ($settings['discount_threshold'] ?? 2) ?>"
         data-discount-type="<?= htmlspecialchars((string) ($settings['discount_type'] ?? 'percent'), ENT_QUOTES, 'UTF-8') ?>"
         data-discount-value="<?= (float) ($settings['discount_value'] ?? 10) ?>">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 pb-4 border-b border-slate-100">
            <div>
                <span class="text-xs font-bold uppercase tracking-wider text-emerald-600">Saison Sportive 2026-2027</span>
                <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Formulaire d'Adhésion en Ligne</h1>
                <p class="text-slate-500 text-xs mt-1">Cotisation de base : <?= number_format($basePrice, 2, ',', ' ') ?> € (-10% dès le 2ème membre inscrit).</p>
            </div>
            <div class="bg-emerald-50 border border-emerald-200 px-4 py-2 rounded-xl text-right shrink-0">
                <span class="text-[11px] font-semibold uppercase text-emerald-700 block">Total Dossier</span>
                <span class="text-xl font-black text-emerald-800"><span id="reg-header-total-price"><?= number_format($basePrice, 2, '.', '') ?></span> €</span>
            </div>
        </div>

        <!-- Fil d'Ariane Dynamique -->
        <ol id="registration-breadcrumb" class="grid grid-cols-3 gap-2 mb-8">
            <li id="bc-step-identity" data-target="identity" class="bc-item cursor-pointer p-3 rounded-xl border-2 border-emerald-600 bg-emerald-50/60 text-emerald-900 transition">
                <span class="text-[11px] font-bold uppercase tracking-wider block text-emerald-600">Étape <span class="bc-num">1</span></span>
                <strong class="text-xs sm:text-sm font-bold">Pratiquant & Cours</strong>
            </li>
            <li id="breadcrumb-tutors" data-target="tutors" class="bc-item cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-400 hidden transition">
                <span class="text-[11px] font-bold uppercase tracking-wider block">Étape <span class="bc-num">2</span></span>
                <strong class="text-xs sm:text-sm font-bold">Responsables Légaux</strong>
            </li>
            <li id="bc-step-emergency" data-target="emergency" class="bc-item cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition">
                <span class="text-[11px] font-bold uppercase tracking-wider block">Étape <span class="bc-num">2</span></span>
                <strong class="text-xs sm:text-sm font-bold">Contact d'Urgence</strong>
            </li>
            <li id="bc-step-payment" data-target="payment" class="bc-item cursor-pointer p-3 rounded-xl border border-slate-200 bg-slate-50 text-slate-500 transition">
                <span class="text-[11px] font-bold uppercase tracking-wider block">Étape <span class="bc-num">3</span></span>
                <strong class="text-xs sm:text-sm font-bold">Panier & Règlement</strong>
            </li>
        </ol>

        <form id="public-registration-form" class="space-y-6" novalidate>

            <!-- =============================================================
                 ÉTAPE 1 : IDENTITÉ DU PRATIQUANT & DISCIPLINES MULTIPLES (GRADE PRÉ-REMPLI PAR ÂGE + NIVEAU)
                 ============================================================= -->
            <section id="step-panel-identity" class="space-y-6">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-bold text-slate-900">1. Identité du Pratiquant</h2>
                    <span id="age-indicator-badge" class="text-xs font-semibold px-3 py-1 rounded-full bg-slate-100 text-slate-600">
                        Saisissez la date de naissance
                    </span>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label for="reg-first-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom du pratiquant *</label>
                        <input type="text" id="reg-first-name" name="first_name" required placeholder="Ex: Lucas" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                    <div>
                        <label for="reg-last-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom du pratiquant *</label>
                        <input type="text" id="reg-last-name" name="last_name" required placeholder="Ex: Martin" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                    <div>
                        <label for="reg-birth-date" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date de naissance *</label>
                        <input type="date" id="reg-birth-date" name="birth_date" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                </div>

                <div>
                    <div id="adult-email-wrapper">
                        <label for="reg-email" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email personnel (Pratiquant Majeur) *</label>
                        <input type="email" id="reg-email" name="email" placeholder="lucas.martin@mail.com" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                    <div id="minor-email-notice" class="hidden p-3.5 rounded-xl bg-amber-50 border border-amber-200 text-xs text-amber-900 flex items-center">
                        <span>👶 <strong>Pratiquant mineur :</strong> L'adresse email de contact et de facturation sera demandée à l'étape suivante (Responsables Légaux).</span>
                    </div>
                </div>

                <!-- Sélection d'une ou plusieurs Disciplines avec Grade (pré-rempli selon l'âge) et Niveau technique -->
                <div class="pt-4 border-t border-slate-100 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div>
                            <h3 class="text-sm font-bold text-slate-900">Disciplines, Grades (Catégories d'âge) & Niveaux souhaités *</h3>
                            <p class="text-xs text-slate-500">Le grade est automatiquement pré-sélectionné selon la date de naissance, mais vous pouvez l'ajuster ou ajouter plusieurs disciplines.</p>
                        </div>
                        <button type="button" id="btn-add-practice-row" class="px-3.5 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold transition shrink-0">
                            + Suivre une autre discipline
                        </button>
                    </div>

                    <!-- Conteneur des lignes de pratiques (Discipline + Grade d'âge + Niveau Ceinture) -->
                    <div id="reg-practices-container" class="space-y-3"></div>

                    <!-- Champ caché #reg-discipline maintenu synchronisé pour compatibilité rétroactive -->
                    <select id="reg-discipline" name="discipline_id" class="hidden">
                        <option value="1">Judo</option>
                        <option value="2">Karaté</option>
                        <option value="3">Aïkido</option>
                    </select>
                </div>

                <div class="flex justify-end pt-4 border-t border-slate-100">
                    <button type="button" id="btn-next-from-identity" class="px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold rounded-xl transition">
                        Étape suivante →
                    </button>
                </div>
            </section>

            <!-- =============================================================
                 ÉTAPE 2 (CONDITIONNELLE) : RESPONSABLES LÉGAUX COMPLETS
                 ============================================================= -->
            <div id="tutor-section" class="hidden">
                <section id="step-panel-tutors" class="hidden space-y-6 p-6 bg-amber-50/50 border border-amber-200 rounded-2xl">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-amber-200/80 pb-4">
                        <div>
                            <span class="text-xs font-bold uppercase text-amber-700">Étape 2 — Pratiquant Mineur (&lt; 18 ans)</span>
                            <h2 class="text-lg font-bold text-slate-900">Informations des Responsables Légaux</h2>
                            <p class="text-xs text-slate-600">Le Responsable Légal 1 doit être majeur (18 ans minimum) et recevra le lien d'activation OTP.</p>
                        </div>
                        <button type="button" id="btn-copy-adult-as-tutor" class="hidden px-3.5 py-2 rounded-xl bg-amber-200/80 hover:bg-amber-300 text-amber-950 text-xs font-bold transition shrink-0">
                            ⚡ Reprendre le majeur du dossier comme Responsable Légal 1
                        </button>
                    </div>

                    <div class="space-y-4 bg-white p-5 rounded-xl border border-amber-200">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-slate-700">Responsable Légal 1 (Référent Principal & Payeur) *</h3>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom *</label>
                                <input type="text" id="tutor1-first-name" placeholder="Ex: Thomas" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom *</label>
                                <input type="text" id="tutor1-last-name" placeholder="Ex: Martin" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Lien de parenté *</label>
                                <select id="tutor1-relation" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                                    <option value="Père">Père</option>
                                    <option value="Mère">Mère</option>
                                    <option value="Tuteur légal">Tuteur / Tutrice légal(e)</option>
                                </select>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label for="tutor1-email" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email Tuteur 1 *</label>
                                <input type="email" id="tutor1-email" name="tutor1_email" placeholder="parent1@mail.com" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone Tuteur 1 *</label>
                                <input type="tel" id="tutor1-phone" placeholder="06 11 22 33 44" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label for="tutor1-birth" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Date de naissance Tuteur 1 *</label>
                                <input type="date" id="tutor1-birth" name="tutor1_birth_date" value="" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Adresse postale du Responsable Légal 1</label>
                            <input type="text" id="tutor1-address" placeholder="Numéro, rue, code postal et ville" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                        </div>
                    </div>

                    <div class="space-y-4 bg-white p-5 rounded-xl border border-slate-200">
                        <div class="flex items-center justify-between">
                            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Second Responsable Légal (Optionnel — Co-tuteur isolé RGPD)</h3>
                            <span class="text-[11px] text-slate-400">Ses coordonnées seront masquées par défaut au Tuteur 1</span>
                        </div>
                        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom & Nom</label>
                                <input type="text" id="tutor2-name" placeholder="Ex: Hélène Martin" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label for="tutor2-email" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email Second Parent</label>
                                <input type="email" id="tutor2-email" name="tutor2_email" placeholder="parent2@mail.com" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone Second Parent</label>
                                <input type="tel" id="tutor2-phone" placeholder="06 22 33 44 55" class="w-full border border-slate-300 rounded-xl px-3.5 py-2 text-sm">
                            </div>
                        </div>
                    </div>

                    <div class="flex justify-between pt-4 border-t border-amber-200/80">
                        <button type="button" data-prev="identity" class="btn-step-prev px-5 py-2.5 bg-white border border-slate-300 hover:bg-slate-100 text-slate-700 text-sm font-semibold rounded-xl transition">
                            ← Étape précédente
                        </button>
                        <button type="button" id="btn-next-from-tutors" class="px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold rounded-xl transition">
                            Continuer vers Contact d'Urgence →
                        </button>
                    </div>
                </section>
            </div>

            <!-- =============================================================
                 ÉTAPE 3 : CONTACT D'URGENCE & SANTÉ
                 ============================================================= -->
            <section id="step-panel-emergency" class="space-y-5 hidden">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Personne à prévenir en cas d'urgence</h2>
                        <p class="text-xs text-slate-500">Accessible aux enseignants en cas d'accident pendant les cours.</p>
                    </div>
                    <button type="button" id="btn-copy-tutor-to-emergency" class="hidden text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg font-semibold">
                        Reprendre le Responsable Légal 1
                    </button>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="reg-emerg-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom complet du contact *</label>
                        <input type="text" id="reg-emerg-name" name="emergency_contact_name" required placeholder="Ex: Thomas Martin" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                    <div>
                        <label for="reg-emerg-phone" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone d'urgence principal *</label>
                        <input type="tel" id="reg-emerg-phone" name="emergency_contact_phone" required placeholder="06 11 22 33 44" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="reg-emerg-relation" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Lien de parenté / Relation</label>
                        <input type="text" id="reg-emerg-relation" name="emergency_contact_relation" placeholder="Ex: Père, Mère, Conjoint, Grand-parent" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                    <div>
                        <label for="reg-emerg-phone-secondary" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone alternatif (Secondaire - Facultatif)</label>
                        <input type="tel" id="reg-emerg-phone-secondary" data-testid="reg-emerg-phone-secondary" name="emergency_contact_secondary_phone" placeholder="06 99 88 77 66" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                    </div>
                </div>

                <!-- Bloc Option Médicale & Cerfa QS-Sport (Sprint 8 - Points 9, 12 & 13) -->
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3" id="health-mode-container">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-bold uppercase tracking-wider text-slate-600 flex items-center gap-1.5">
                            <svg class="w-4 h-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"/></svg>
                            Santé & Aptitude Sportive
                        </span>
                        <a href="/questionnaire-sante" target="_blank" class="text-[11px] text-emerald-700 hover:text-emerald-900 font-semibold underline">
                            Remplir le questionnaire Cerfa en ligne ↗
                        </a>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                        <label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 bg-white hover:border-emerald-400 cursor-pointer">
                            <input type="radio" name="health_cert_choice" id="choice-health-attestation" value="attestation" checked class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="font-bold text-slate-900 block">Attestation Cerfa QS-Sport</span>
                                <span class="text-slate-500 text-[11px]">J'atteste avoir répondu NON à toutes les rubriques de l'auto-évaluation médicale.</span>
                            </div>
                        </label>
                        <label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-200 bg-white hover:border-emerald-400 cursor-pointer">
                            <input type="radio" name="health_cert_choice" id="choice-health-cert" value="medical_cert" class="mt-0.5 text-emerald-600 focus:ring-emerald-500">
                            <div>
                                <span class="font-bold text-slate-900 block">Certificat Médical</span>
                                <span class="text-slate-500 text-[11px]">Un certificat médical me sera demandé lors de mon premier cours.</span>
                            </div>
                        </label>
                    </div>
                </div>

                <div class="flex justify-between pt-4 border-t border-slate-100">
                    <button type="button" id="btn-prev-from-emergency" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold rounded-xl transition">
                        ← Étape précédente
                    </button>
                    <button type="button" id="btn-next-from-emergency" class="px-6 py-2.5 bg-slate-900 hover:bg-slate-800 text-white text-sm font-semibold rounded-xl transition">
                        Continuer vers Panier & Paiement →
                    </button>
                </div>
            </section>

            <!-- =============================================================
                 ÉTAPE 4 : PANIER FAMILIAL & RÈGLEMENT
                 ============================================================= -->
            <section id="step-panel-payment" class="space-y-6 hidden">
                <div class="p-5 bg-slate-50 rounded-2xl border border-slate-200 space-y-4">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                        <div>
                            <h2 class="text-base font-bold text-slate-900">Composition du Panier Familial</h2>
                            <p class="text-xs text-slate-500">Vous pouvez ajouter d'autres membres de la famille dans ce même dossier (-10% dès le 2ème membre).</p>
                        </div>
                        <div class="flex items-center gap-3">
                            <span id="family-cart-count" class="text-xs font-bold px-3 py-1 rounded-full bg-emerald-100 text-emerald-800">
                                Panier : 1 adhérent(s)
                            </span>
                            <button type="button" id="btn-add-family-member" class="bg-white border border-slate-300 hover:bg-slate-100 text-slate-800 px-3.5 py-2 rounded-xl text-xs font-bold transition shadow-2xs">
                                + Ajouter un autre membre au panier
                            </button>
                        </div>
                    </div>

                    <ul id="family-cart-list" class="space-y-2 text-sm text-slate-700"></ul>

                    <!-- Récapitulatif Tarifaire & Remises Paramétrables (Points 7 & 8) -->
                    <div class="pt-3 border-t border-slate-200/80 flex flex-col sm:flex-row sm:items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span id="reg-discount-badge" data-testid="reg-discount-badge" class="hidden px-2.5 py-1 rounded-full text-xs font-bold bg-amber-100 text-amber-800">
                                Remise Famille
                            </span>
                            <span id="reg-discount-amount" data-testid="reg-discount-amount" class="hidden text-xs font-bold text-amber-700">
                                -0.00 €
                            </span>
                        </div>
                        <div class="text-right">
                            <span class="text-xs text-slate-500 block">Total Cotisation TTC :</span>
                            <span class="text-xl font-black text-slate-900"><span id="reg-total-price">150.00</span> €</span>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label for="reg-payment-method" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Moyen de règlement choisi</label>
                        <select id="reg-payment-method" name="payment_method" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                            <option value="cheque">Chèque bancaire (Éligible 1x, 2x ou 3x)</option>
                            <option value="virement">Virement bancaire (Comptant 1x uniquement)</option>
                            <option value="especes">Espèces au bureau (Comptant 1x uniquement)</option>
                        </select>
                    </div>
                    <div>
                        <label for="reg-installments" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Échéancier de paiement</label>
                        <select id="reg-installments" name="installments" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                            <option value="1">Paiement comptant en 1 fois</option>
                            <option value="2" class="opt-multi-installment">Paiement échelonné en 2 fois (Chèque)</option>
                            <option value="3" class="opt-multi-installment">Paiement échelonné en 3 fois (Chèque)</option>
                        </select>
                        <p id="installment-restriction-hint" class="hidden text-[11px] text-amber-700 mt-1 font-medium">
                            ℹ️ Le paiement en plusieurs fois est réservé au règlement par chèque.
                        </p>
                    </div>
                </div>

                <div class="flex flex-wrap justify-between items-center gap-3 pt-4 border-t border-slate-100">
                    <button type="button" id="btn-prev-from-payment" class="px-5 py-2.5 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm font-semibold rounded-xl transition">
                        ← Étape précédente
                    </button>
                    <div class="flex items-center gap-3">
                        <button type="button" id="btn-open-confirm-modal" data-testid="btn-open-confirm-modal" class="bg-white border border-slate-300 hover:bg-slate-50 text-slate-700 px-5 py-3 rounded-xl font-bold shadow-xs transition">
                            Vérifier le récapitulatif
                        </button>
                        <button type="submit" id="btn-submit-registration" data-testid="btn-submit-registration" class="bg-emerald-600 hover:bg-emerald-700 text-white px-7 py-3 rounded-xl font-bold shadow-md transition">
                            Valider définitivement mon dossier d'inscription →
                        </button>
                    </div>
                </div>
            </section>

            <!-- Modale de Confirmation Récapitulative Pré-Soumission (Point 17) -->
            <div id="pre-submit-modal" data-testid="pre-submit-modal" class="hidden fixed inset-0 z-50 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4">
                <div class="bg-white rounded-2xl shadow-xl border border-slate-200 max-w-lg w-full p-6 space-y-5 animate-in fade-in zoom-in-95 duration-150">
                    <div class="flex items-center justify-between border-b border-slate-100 pb-3">
                        <h3 class="text-base font-extrabold text-slate-900">Récapitulatif & Confirmation de votre Adhésion</h3>
                        <button type="button" id="btn-close-pre-submit" class="text-slate-400 hover:text-slate-600 font-bold text-lg">✕</button>
                    </div>
                    <div id="modal-summary-content" class="space-y-3 text-xs text-slate-700 max-h-60 overflow-y-auto"></div>
                    <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-xs space-y-2">
                        <label class="flex items-start gap-2 cursor-pointer">
                            <input type="checkbox" id="modal-accept-rules" required class="mt-0.5 rounded text-emerald-600 focus:ring-emerald-500">
                            <span>J'atteste avoir pris connaissance du règlement intérieur et m'engage à régler la cotisation convenue.</span>
                        </label>
                    </div>
                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" id="btn-cancel-confirm" class="px-4 py-2 rounded-xl text-xs font-semibold text-slate-600 hover:bg-slate-100">
                            Modifier mon dossier
                        </button>
                        <button type="button" id="btn-confirm-registration" data-testid="btn-confirm-registration" class="px-5 py-2.5 rounded-xl text-xs font-bold text-white bg-emerald-600 hover:bg-emerald-700 shadow-xs">
                            Confirmer définitivement
                        </button>
                    </div>
                </div>
            </div>

        </form>
    </div>

    <!-- Page Explicative Post-Validation -->
    <section id="registration-success-screen" class="hidden bg-white rounded-2xl shadow-sm border border-emerald-200 p-8 md:p-10 text-center space-y-6">
        <div class="w-16 h-16 rounded-full bg-emerald-100 text-emerald-700 flex items-center justify-center mx-auto text-3xl font-black">✓</div>
        <div class="space-y-2">
            <span class="px-3 py-1 rounded-full bg-emerald-50 text-emerald-700 text-xs font-bold uppercase tracking-wider">
                Dossier #<span id="confirm-screen-reg-id"></span> enregistré
            </span>
            <h2 class="text-2xl font-extrabold text-slate-900">Votre demande d'adhésion est bien enregistrée !</h2>
            <p class="text-sm font-semibold text-emerald-800">Montant total retenu : <span id="confirm-screen-amount"></span> €</p>
        </div>

        <div class="max-w-2xl mx-auto p-6 bg-slate-50 rounded-2xl border border-slate-200 text-left space-y-3">
            <h3 class="text-xs font-bold uppercase tracking-wider text-slate-500">Procédure de validation & Connexion par mail OTP :</h3>
            <p class="text-sm text-slate-700 leading-relaxed">
                <?= nl2br(htmlspecialchars($confirmTxt, ENT_QUOTES, 'UTF-8')) ?>
            </p>
            <ol class="list-decimal pl-5 text-xs text-slate-600 space-y-1.5 pt-2">
                <li><strong>Étape 1 (Règlement) :</strong> Remettez votre règlement au Trésorier ou Secrétariat du club lors de votre premier cours.</li>
                <li><strong>Étape 2 (Vérification du cours/grade & Envoi OTP) :</strong> Le bureau vérifie vos cours/grades choisis et vous envoie un email automatique avec un <strong>lien d'activation à usage unique valable 48h</strong>.</li>
                <li><strong>Étape 3 (Création du mot de passe) :</strong> Cliquez sur ce lien pour définir votre mot de passe et accéder à votre espace personnel.</li>
            </ol>
        </div>

        <div class="flex justify-center gap-4 pt-2">
            <a href="/" class="px-5 py-2.5 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold transition">
                Retour à l'accueil du club
            </a>
            <button type="button" id="btn-new-registration-again" class="px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white text-xs font-bold transition">
                Saisir un autre dossier
            </button>
        </div>
    </section>

</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';