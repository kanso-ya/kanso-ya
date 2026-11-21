<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentUserId = (int) ($_SESSION['user_id'] ?? 0);
$currentRole   = (string) ($_SESSION['role_name'] ?? 'Visiteur');
$isSuperadmin  = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));

if ($isSuperadmin) {
    header('Location: /sys-console');
    exit;
}

$pageTitle   = 'Mon profil';
$activeNav   = 'dashboard';
$requireAuth = true;
$pageScripts = ['/assets/ts/dashboard.js'];

$settings   = [];
$seasonType = '';
$basePrice  = '';
$regEnabled = '';
$confirmTxt = '';

$isUnauthenticatedVisitor = !$isSuperadmin && ($currentUserId <= 0 || $currentRole === 'Visiteur');
if (!$isUnauthenticatedVisitor) {
    $db = Database::getInstance();
    $settings = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
    $seasonType = (string) ($settings['season_type'] ?? 'Sept-Juin');
    $basePrice  = (string) ($settings['membership_base_price'] ?? '150');
    $regEnabled = (string) ($settings['public_registration_enabled'] ?? '1');
    $confirmTxt = (string) ($settings['registration_confirmation_text'] ?? '');
}

ob_start();
?>
<div class="space-y-8">
    <!-- En-tête Mon Profil & Navigation à 2 Onglets -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-brand-primary">Compte Personnel — <?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Mon profil</h1>
            <p class="text-xs text-slate-500">Gérez vos coordonnées personnelles, vos champs complémentaires et la sécurité de votre compte (double authentification et mot de passe).</p>
        </div>

        <!-- Sélecteur des 2 onglets -->
        <div class="flex items-center bg-slate-100 p-1.5 rounded-xl text-xs font-bold shrink-0">
            <button type="button" id="tab-btn-profile-info" class="px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition">
                👤 1. Informations du profil
            </button>
            <button type="button" id="tab-btn-profile-security" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                🔐 2. Sécurité & Connexion
            </button>
        </div>
    </div>

    <!-- =====================================================================
         ONGLET 1 : INFORMATIONS DU PROFIL & CHAMPS DYNAMIQUES
         ===================================================================== -->
    <section id="tab-panel-profile-info" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-3">
            <h2 class="text-lg font-bold text-slate-900">Informations Personnelles</h2>
            <p class="text-xs text-slate-500">Modifiez vos coordonnées principales ou ajoutez/supprimez des champs personnalisés sur votre fiche.</p>
        </div>

        <form id="form-my-profile" class="space-y-6" novalidate>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="prof-first-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom *</label>
                    <input type="text" id="prof-first-name" name="first_name" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label for="prof-last-name" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom *</label>
                    <input type="text" id="prof-last-name" name="last_name" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label for="prof-email" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email de connexion *</label>
                    <input type="email" id="prof-email" name="email" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label for="prof-phone" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone</label>
                    <input type="tel" id="prof-phone" name="phone" placeholder="06 12 34 56 78" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label for="prof-address" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Adresse postale complète</label>
                    <input type="text" id="prof-address" name="address" placeholder="Numéro, rue, code postal et ville" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
            </div>

            <!-- Champs personnalisés ajoutables / modifiables / supprimables -->
            <div class="pt-4 border-t border-slate-100 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Champs Complémentaires Personnalisés</h3>
                        <p class="text-xs text-slate-500">Ajoutez, modifiez ou supprimez librement des informations (ex: N° de Licence, Allergie, Profession...).</p>
                    </div>
                    <button type="button" id="btn-add-custom-field" class="px-3.5 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-brand-primary border border-brand-primary text-xs font-bold transition">
                        + Ajouter un champ
                    </button>
                </div>

                <div id="custom-fields-container" class="space-y-2.5"></div>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-100">
                <button type="submit" id="btn-save-profile" class="bg-brand-primary text-white px-6 py-2.5 rounded-xl text-sm font-bold shadow-xs transition">
                    Enregistrer les informations de mon profil
                </button>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         ONGLET 2 : ÉLÉMENTS DE SÉCURITÉ, DOUBLE AUTHENTIFICATION (3 OPTIONS) & MOT DE PASSE
         ===================================================================== -->
    <section id="tab-panel-profile-security" class="hidden space-y-6">
        <!-- A. Double Authentification Multi-Choix (QR Code 30s / Clé manuelle / Code Email) -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-slate-900">Double Authentification (Sécurité renforcée)</h2>
                        <span id="badge-2fa-status" class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">Désactivée</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Choisissez librement votre méthode de vérification en deux étapes : application mobile, clé secrète manuelle ou code reçu par e-mail.</p>
                </div>
            </div>

            <!-- Sélecteur des 3 méthodes 2FA -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5" role="tablist" aria-label="Méthode de double authentification">
                <button type="button" id="btn-2fa-mode-qr" data-testid="2fa-method-totp" data-2fa-mode="qr" role="tab" aria-selected="true"
                        class="p-3 rounded-xl border-2 border-brand-primary bg-emerald-50 text-left transition">
                    <span class="block text-xs font-extrabold text-slate-900">📱 Application mobile (QR Code)</span>
                    <span class="block text-[11px] text-slate-600 mt-0.5">QR Code dynamique renouvelé toutes les 30s</span>
                </button>
                <button type="button" id="btn-2fa-mode-manual" data-testid="2fa-method-manual" data-2fa-mode="manual" role="tab" aria-selected="false"
                        class="p-3 rounded-xl border border-slate-200 bg-slate-50 hover:bg-white text-left transition">
                    <span class="block text-xs font-extrabold text-slate-900">🔑 Clé secrète manuelle</span>
                    <span class="block text-[11px] text-slate-600 mt-0.5">Saisie directe de la clé dans votre application</span>
                </button>
                <button type="button" id="btn-2fa-mode-email" data-testid="2fa-method-email" data-2fa-mode="email" role="tab" aria-selected="false"
                        class="p-3 rounded-xl border border-slate-200 bg-slate-50 hover:bg-white text-left transition">
                    <span class="block text-xs font-extrabold text-slate-900">📧 Code reçu par e-mail</span>
                    <span class="block text-[11px] text-slate-600 mt-0.5">Réception d'un code à 6 chiffres dans votre boîte mail</span>
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <!-- Panneau gauche adaptatif selon l'option choisie -->
                <div>
                    <!-- Option 1 : QR Code 30s -->
                    <div id="panel-2fa-qr" class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 flex flex-col items-center text-center">
                        <span class="font-bold uppercase text-[11px] text-slate-500 tracking-wider">Scannez ce QR Code avec votre application :</span>

                        <div id="totp-qrcode-container" class="my-1 flex items-center justify-center">
                            <div class="w-44 h-44 bg-slate-200 animate-pulse rounded-xl flex items-center justify-center text-xs text-slate-400 font-mono">Génération...</div>
                        </div>

                        <div class="flex items-center gap-1.5 text-xs text-slate-600">
                            <span class="w-2 h-2 rounded-full bg-brand-accent animate-pulse"></span>
                            <span>Renouvellement dans <strong id="totp-timer" data-testid="totp-timer" class="font-mono text-slate-800">30s</strong></span>
                        </div>

                        <button type="button" id="btn-toggle-secret" data-testid="btn-toggle-secret" class="text-xs font-semibold text-brand-primary hover:underline transition pt-1">
                            Afficher la clé secrète textuelle
                        </button>

                        <div id="totp-secret-wrapper" class="hidden w-full pt-1">
                            <code id="totp-secret-display" class="block p-2.5 bg-slate-900 text-brand-accent font-mono text-xs rounded-lg tracking-widest text-center select-all">KANSO2FA...</code>
                            <span class="text-[10px] text-slate-400 mt-1 block">À saisir manuellement si vous ne pouvez pas scanner le QR Code.</span>
                        </div>
                    </div>

                    <!-- Option 2 : Clé secrète manuelle -->
                    <div id="panel-2fa-manual" class="hidden p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 text-center">
                        <span class="font-bold uppercase text-[11px] text-slate-500 tracking-wider block">Clé de configuration manuelle :</span>
                        <p class="text-xs text-slate-600">Copiez cette clé et collez-la dans votre application d'authentification (Google Authenticator, Authy, FreeOTP) en choisissant l'ajout par clé de configuration.</p>
                        <code id="totp-secret-manual-display" class="block p-3 bg-slate-900 text-brand-accent font-mono text-sm rounded-xl tracking-widest text-center select-all">KANSO2FA...</code>
                        <button type="button" id="btn-copy-totp-secret" data-testid="btn-copy-totp-secret" class="px-4 py-2 rounded-xl bg-white hover:bg-slate-100 text-slate-800 border border-slate-300 text-xs font-bold transition shadow-2xs">
                            📋 Copier la clé secrète
                        </button>
                    </div>

                    <!-- Option 3 : Code reçu par e-mail -->
                    <div id="panel-2fa-email" class="hidden p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 text-left">
                        <span class="font-bold uppercase text-[11px] text-slate-500 tracking-wider block">Vérification par adresse e-mail :</span>
                        <p class="text-xs text-slate-600 leading-relaxed">
                            Cliquez sur le bouton ci-dessous pour recevoir immédiatement un code temporaire à 6 chiffres (valable 10 minutes) sur votre adresse <strong id="twofa-user-email-label" class="text-slate-900">de connexion</strong>.
                        </p>
                        <button type="button" id="btn-send-2fa-email-code" data-testid="btn-send-2fa-email-code" class="w-full py-2.5 px-4 rounded-xl bg-brand-primary text-white text-xs font-bold shadow-xs transition">
                            ✉️ Envoyer un code de vérification par e-mail
                        </button>
                    </div>
                </div>

                <!-- Panneau droit : Saisie du code et activation/désactivation -->
                <form id="form-toggle-2fa" class="space-y-4" novalidate>
                    <div>
                        <label id="label-2fa-code-input" for="totp-code-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Code de vérification à 6 chiffres *</label>
                        <input type="text" id="totp-code-input" data-testid="input-2fa-email-code" maxlength="6" inputmode="numeric" placeholder="Ex: 123456" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-mono tracking-widest focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div class="flex gap-3">
                        <button type="button" id="btn-enable-2fa" class="bg-brand-primary text-white px-5 py-2.5 rounded-xl text-xs font-bold transition">
                            Activer la double authentification
                        </button>
                        <button type="button" id="btn-disable-2fa" class="hidden bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-5 py-2.5 rounded-xl text-xs font-bold transition">
                            Désactiver la double authentification
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- B. Changement du mot de passe personnel avec Règles Explicites et Modale de Double Saisie -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 mb-1">Modifier mon mot de passe</h2>
                <p class="text-xs text-slate-500">Pour votre sécurité, votre mot de passe actuel est exigé, suivi d'une confirmation de votre nouveau mot de passe.</p>
            </div>

            <div id="password-requirements-card" data-testid="pwd-requirements-card" class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1">
                <span class="font-bold block">Règles de sécurité requises pour le mot de passe :</span>
                <ul class="list-disc list-inside space-y-0.5 text-[11px] text-amber-800">
                    <li>Minimum 8 caractères</li>
                    <li>Au moins une lettre majuscule (A-Z) et une minuscule (a-z)</li>
                    <li>Au moins un chiffre (0-9)</li>
                    <li>Au moins un caractère spécial (!@#$%^&*...)</li>
                </ul>
            </div>

            <form id="form-change-password" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end" novalidate>
                <div>
                    <label for="pwd-current" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ancien mot de passe *</label>
                    <input type="password" id="pwd-current" name="current_password" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label for="pwd-new" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nouveau mot de passe *</label>
                    <input type="password" id="pwd-new" name="new_password" required minlength="8" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <button type="submit" id="btn-submit-password" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition">
                    Mettre à jour mon mot de passe
                </button>
            </form>
        </div>
    </section>
</div>

<!-- Modale Dédiée : Double Saisie de Confirmation du Changement de Mot de Passe -->
<div id="password-confirm-modal" data-testid="password-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="pwd-modal-title"
     class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs hidden items-center justify-center z-50 p-4">
    <div class="bg-white rounded-2xl shadow-2xl border border-slate-200 w-full max-w-md p-6 space-y-5">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-full bg-emerald-50 text-brand-primary flex items-center justify-center shrink-0 font-bold text-lg">🔐</div>
            <div>
                <h3 id="pwd-modal-title" class="text-lg font-bold text-slate-900">Confirmer votre nouveau mot de passe</h3>
                <p class="text-xs text-slate-500">Veuillez saisir une seconde fois votre nouveau mot de passe pour valider la modification.</p>
            </div>
        </div>

        <form id="form-modal-confirm-password" class="space-y-4" novalidate>
            <div>
                <label for="modal-confirm-new-password" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Confirmation du nouveau mot de passe *</label>
                <input type="password" id="modal-confirm-new-password" data-testid="modal-confirm-new-password" required placeholder="Saisissez de nouveau votre nouveau mot de passe"
                       class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm focus:ring-2 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div class="flex justify-end gap-3 pt-3 border-t border-slate-100">
                <button type="button" id="btn-cancel-password-modal" class="px-4 py-2 rounded-lg text-sm font-medium text-slate-600 hover:bg-slate-100 transition">
                    Annuler
                </button>
                <button type="submit" id="btn-confirm-password-change" data-testid="btn-confirm-password-change"
                        class="px-5 py-2 rounded-lg text-sm font-semibold bg-brand-primary text-white transition shadow-sm">
                    Confirmer la modification
                </button>
            </div>
        </form>
    </div>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';