<?php

declare(strict_types=1);

require_once __DIR__ . '/../core/Api/Database/Database.php';
use Core\Api\Database\Database;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$currentRole  = (string) ($_SESSION['role_name'] ?? 'Président');
$isSuperadmin = (bool) ($_SESSION['is_superadmin'] ?? isset($_SESSION['sys_admin_id']));

if ($isSuperadmin) {
    header('Location: /sys-console');
    exit;
}

$pageTitle   = 'Mon profil';
$activeNav   = 'dashboard';
$requireAuth = true;
$pageScripts = ['/assets/ts/dashboard.js'];

$db = Database::getInstance();
$settings = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];
$seasonType = (string) ($settings['season_type'] ?? 'Sept-Juin');
$basePrice  = (string) ($settings['membership_base_price'] ?? '150');
$regEnabled = (string) ($settings['public_registration_enabled'] ?? '1');
$confirmTxt = (string) ($settings['registration_confirmation_text'] ?? '');

ob_start();
?>
<div class="space-y-8">
    <!-- En-tête Mon Profil & Navigation à 2 Onglets (Point 4) -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">Compte Personnel — <?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Mon profil</h1>
            <p class="text-xs text-slate-500">Gérez vos coordonnées personnelles, vos champs complémentaires et la sécurité de votre compte (2FA & Mot de passe).</p>
        </div>

        <!-- Sélecteur des 2 onglets (Point 4) -->
        <div class="flex items-center bg-slate-100 p-1.5 rounded-xl text-xs font-bold shrink-0">
            <button type="button" id="tab-btn-profile-info" class="px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition">
                👤 1. Informations du profil
            </button>
            <button type="button" id="tab-btn-profile-security" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                🔐 2. Sécurité & 2FA
            </button>
        </div>
    </div>

    <!-- =====================================================================
         ONGLET 1 : INFORMATIONS DU PROFIL & CHAMPS DYNAMIQUES (Point 4)
         ===================================================================== -->
    <section id="tab-panel-profile-info" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-3">
            <h2 class="text-lg font-bold text-slate-900">Informations Personnelles</h2>
            <p class="text-xs text-slate-500">Modifiez vos coordonnées principales ou ajoutez/supprimez des champs personnalisés sur votre fiche.</p>
        </div>

        <form id="form-my-profile" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Prénom *</label>
                    <input type="text" id="prof-first-name" name="first_name" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nom *</label>
                    <input type="text" id="prof-last-name" name="last_name" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Email de connexion *</label>
                    <input type="email" id="prof-email" name="email" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Téléphone</label>
                    <input type="tel" id="prof-phone" name="phone" placeholder="06 12 34 56 78" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div class="md:col-span-2">
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Adresse postale complète</label>
                    <input type="text" id="prof-address" name="address" placeholder="Numéro, rue, code postal et ville" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
            </div>

            <!-- Champs personnalisés ajoutables / modifiables / supprimables (Point 4) -->
            <div class="pt-4 border-t border-slate-100 space-y-3">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-sm font-bold text-slate-800">Champs Complémentaires Personnalisés</h3>
                        <p class="text-xs text-slate-500">Ajoutez, modifiez ou supprimez librement des informations (ex: N° de Licence, Allergie, Profession...).</p>
                    </div>
                    <button type="button" id="btn-add-custom-field" class="px-3.5 py-1.5 rounded-xl bg-emerald-50 hover:bg-emerald-100 text-emerald-700 border border-emerald-200 text-xs font-bold transition">
                        + Ajouter un champ
                    </button>
                </div>

                <div id="custom-fields-container" class="space-y-2.5"></div>
            </div>

            <div class="flex justify-end pt-4 border-t border-slate-100">
                <button type="submit" id="btn-save-profile" class="bg-emerald-600 hover:bg-emerald-700 text-white px-6 py-2.5 rounded-xl text-sm font-bold shadow-xs transition">
                    Enregistrer les informations de mon profil
                </button>
            </div>
        </form>
    </section>

    <!-- =====================================================================
         ONGLET 2 : ÉLÉMENTS DE SÉCURITÉ, DOUBLE AUTHENTIFICATION (2FA) & MOT DE PASSE (Point 4)
         ===================================================================== -->
    <section id="tab-panel-profile-security" class="hidden space-y-6">
        <!-- A. Double Authentification (2FA TOTP) -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-5">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-100 pb-4">
                <div>
                    <div class="flex items-center gap-2">
                        <h2 class="text-lg font-bold text-slate-900">Double Authentification (2FA / A2F)</h2>
                        <span id="badge-2fa-status" class="px-2.5 py-0.5 rounded-full text-xs font-bold bg-slate-100 text-slate-600">Désactivée</span>
                    </div>
                    <p class="text-xs text-slate-500 mt-1">Renforcez la sécurité de votre compte avec une application d'authentification (Google Authenticator, Authy, FreeOTP).</p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
                <!-- Zone QR Code SVG & Clé secrète -->
                <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-3 flex flex-col items-center text-center">
                    <span class="font-bold uppercase text-[11px] text-slate-500 tracking-wider">Scannez ce QR Code avec votre application :</span>
                    
                    <div id="totp-qrcode-container" class="my-1 flex items-center justify-center">
                        <div class="w-44 h-44 bg-slate-200 animate-pulse rounded-xl flex items-center justify-center text-xs text-slate-400 font-mono">Génération...</div>
                    </div>

                    <div class="flex items-center gap-1.5 text-xs text-slate-600">
                        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Créneau renouvelé dans <strong id="totp-timer" class="font-mono text-slate-800">30s</strong></span>
                    </div>

                    <button type="button" id="btn-toggle-secret" class="text-xs font-semibold text-emerald-700 hover:text-emerald-800 underline transition pt-1">
                        Afficher la clé secrète textuelle
                    </button>

                    <div id="totp-secret-wrapper" class="hidden w-full pt-1">
                        <code id="totp-secret-display" class="block p-2.5 bg-slate-900 text-emerald-400 font-mono text-xs rounded-lg tracking-widest text-center select-all">KANSO2FA...</code>
                        <span class="text-[10px] text-slate-400 mt-1 block">À saisir manuellement si vous ne pouvez pas scanner le QR Code.</span>
                    </div>
                </div>

                <form id="form-toggle-2fa" class="space-y-4">
                    <div>
                        <label for="totp-code-input" class="block text-xs font-semibold uppercase text-slate-600 mb-1">Code de vérification à 6 chiffres *</label>
                        <input type="text" id="totp-code-input" maxlength="6" pattern="\d{6}" placeholder="Ex: 123456" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm font-mono tracking-widest focus:ring-2 focus:ring-emerald-500 focus:outline-none">
                    </div>
                    <div class="flex gap-3">
                        <button type="button" id="btn-enable-2fa" class="bg-emerald-600 hover:bg-emerald-700 text-white px-5 py-2.5 rounded-xl text-xs font-bold transition">
                            Activer la 2FA
                        </button>
                        <button type="button" id="btn-disable-2fa" class="hidden bg-red-50 hover:bg-red-100 text-red-600 border border-red-200 px-5 py-2.5 rounded-xl text-xs font-bold transition">
                            Désactiver la 2FA
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- B. Changement du mot de passe personnel avec Règles Explicites et Confirmation -->
        <div class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <div>
                <h2 class="text-lg font-bold text-slate-900 mb-1">Modifier mon mot de passe</h2>
                <p class="text-xs text-slate-500">Pour votre sécurité, votre mot de passe actuel est exigé avant toute modification.</p>
            </div>

            <div class="p-3.5 bg-amber-50 border border-amber-200 rounded-xl text-xs text-amber-900 space-y-1">
                <span class="font-bold block">Règles de complexité requises pour le mot de passe :</span>
                <ul class="list-disc list-inside space-y-0.5 text-[11px] text-amber-800">
                    <li>Minimum 8 caractères</li>
                    <li>Au moins une lettre majuscule (A-Z) et une minuscule (a-z)</li>
                    <li>Au moins un chiffre (0-9)</li>
                    <li>Au moins un caractère spécial (!@#$%^&*...)</li>
                </ul>
            </div>

            <form id="form-change-password" class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ancien mot de passe *</label>
                    <input type="password" name="current_password" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Nouveau mot de passe *</label>
                    <input type="password" name="new_password" required minlength="8" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>
                <button type="submit" id="btn-submit-password" class="bg-slate-900 hover:bg-slate-800 text-white px-5 py-2.5 rounded-xl text-sm font-bold transition">
                    Mettre à jour mon mot de passe
                </button>
            </form>
        </div>
    </section>

    <!-- =====================================================================
         PARAMÉTRAGE MÉTIER DU CLUB (PRÉSIDENT / TRÉSORIER / SECRÉTAIRE - Points 1 & 3)
         - Saison : Président uniquement
         - Cotisation & Moyens de paiement échelonnés : Président & Trésorier
         - Texte explicatif de validation OTP : Président & Secrétaire
         ===================================================================== -->
    <?php if (in_array($currentRole, ['Président', 'Trésorier', 'Secrétaire'], true)): ?>
        <section class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
            <div class="border-b border-slate-100 pb-3">
                <span class="text-xs font-bold uppercase text-amber-600">Paramètres Métier du Bureau (<?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?>)</span>
                <h2 class="text-lg font-bold text-slate-900">Configuration de la Saison, des Cotisations & de l'Onboarding</h2>
            </div>

            <form id="form-club-business-settings" class="space-y-5">
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <?php if ($currentRole === 'Président'): ?>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Type de Saison (Président) *</label>
                            <select name="season_type" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                                <option value="Sept-Juin" <?= $seasonType === 'Sept-Juin' ? 'selected' : '' ?>>Septembre à Juin (Scolaire)</option>
                                <option value="Jan-Dec" <?= $seasonType === 'Jan-Dec' ? 'selected' : '' ?>>Janvier à Décembre (Civile)</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Ouverture des Inscriptions</label>
                            <select name="public_registration_enabled" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                                <option value="1" <?= $regEnabled === '1' ? 'selected' : '' ?>>Inscriptions en ligne Ouvertes</option>
                                <option value="0" <?= $regEnabled === '0' ? 'selected' : '' ?>>Inscriptions en ligne Fermées</option>
                            </select>
                        </div>
                    <?php endif; ?>

                    <?php if (in_array($currentRole, ['Président', 'Trésorier'], true)): ?>
                        <div>
                            <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cotisation annuelle de base (€) *</label>
                            <input type="number" name="membership_base_price" value="<?= htmlspecialchars($basePrice, ENT_QUOTES, 'UTF-8') ?>" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                        </div>
                    <?php endif; ?>
                </div>

                <?php if (in_array($currentRole, ['Président', 'Secrétaire'], true)): ?>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Texte explicatif affiché après validation d'une inscription (Procédure OTP)</label>
                        <textarea name="registration_confirmation_text" rows="3" class="w-full border border-slate-300 rounded-xl p-3.5 text-sm"><?= htmlspecialchars($confirmTxt, ENT_QUOTES, 'UTF-8') ?></textarea>
                    </div>
                <?php endif; ?>

                <div class="flex justify-end">
                    <button type="submit" id="btn-save-club-settings" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                        Enregistrer les paramètres du club
                    </button>
                </div>
            </form>
        </section>
    <?php endif; ?>

    <?php if ($currentRole === 'Président'): ?>
        <!-- =====================================================================
             BASCULEMENT DE SAISON / ROLLOVER ANNUEL (PRÉSIDENT UNIQUEMENT - US 5.1)
             ===================================================================== -->
        <section class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
            <div class="border-b border-slate-100 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-bold uppercase text-red-600">Automatisation Administrative & Sportive (US 5.1)</span>
                    <h2 class="text-lg font-bold text-slate-900">Basculement Annuel de Saison (Moulinette Rollover)</h2>
                    <p class="text-xs text-slate-500">Recalcule automatiquement les catégories d'âge (grades) pour tous les adhérents actifs, verrouille les surclassements entraîneur et archive les dossiers antérieurs.</p>
                </div>
                <button type="button" id="btn-open-rollover-modal" class="px-5 py-2.5 bg-red-600 hover:bg-red-700 text-white text-xs font-bold uppercase tracking-wider rounded-xl transition shadow shrink-0">
                    🔄 Déclencher le basculement de saison
                </button>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-xs">
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-800 block mb-1">1. Recalcul automatique des Grades</span>
                    <p class="text-slate-500">L'âge de chaque adhérent est recalculé à la date de référence pour lui affecter sa nouvelle catégorie d'âge (Poussin, Benjamin, Minime...).</p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-800 block mb-1">2. Respect strict des Surclassements</span>
                    <p class="text-slate-500">Les adhérents surclassés par décision d'entraîneur (<code class="text-emerald-700 font-mono">is_category_forced = 1</code>) sont intégralement préservés.</p>
                </div>
                <div class="p-4 rounded-xl bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-800 block mb-1">3. Clôture des Dossiers Précédents</span>
                    <p class="text-slate-500">Les dossiers validés de la saison sortante sont automatiquement passés au statut <code class="text-blue-700 font-mono">archived</code>.</p>
                </div>
            </div>
        </section>

        <!-- Modale de Confirmation Rouge de Rollover (US 5.1) -->
        <div id="rollover-confirm-modal" class="fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50 hidden">
            <div class="bg-white rounded-2xl max-w-lg w-full p-6 shadow-2xl border-2 border-red-500 space-y-5 animate-in fade-in duration-200">
                <div class="flex items-center gap-3 text-red-600 border-b border-red-100 pb-3">
                    <span class="text-2xl">⚠️</span>
                    <div>
                        <h3 class="text-base font-extrabold text-slate-900">Confirmation du Basculement de Saison</h3>
                        <p class="text-xs text-red-600 font-semibold">Cette opération massive modifie les dossiers de l'ensemble du club.</p>
                    </div>
                </div>

                <div class="space-y-4 text-xs text-slate-600">
                    <p>
                        Vous êtes sur le point de déclencher la moulinette de changement de saison sportive.
                        Tous les adhérents actifs verront leur catégorie d'âge recalculée pour la nouvelle saison cible.
                    </p>

                    <div class="bg-red-50 p-3.5 rounded-xl border border-red-200 space-y-2">
                        <label class="block font-bold text-slate-800">Saison cible :</label>
                        <input type="text" id="rollover-target-season" value="2027-2028" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-mono font-bold">

                        <label class="block font-bold text-slate-800 mt-2">Date de référence du calcul d'âge :</label>
                        <input type="date" id="rollover-reference-date" value="2027-09-01" class="w-full bg-white border border-slate-300 rounded-lg px-3 py-2 text-xs font-mono">
                    </div>

                    <div class="p-3 bg-amber-50 rounded-xl border border-amber-200 text-amber-900">
                        🛡️ <strong>Garantie Sécurité :</strong> Les surclassements validés manuellement par les entraîneurs (<code>is_category_forced = 1</code>) ne seront <strong>pas modifiés</strong>.
                    </div>
                </div>

                <div class="flex items-center justify-end gap-3 pt-3 border-t border-slate-100">
                    <button type="button" id="btn-cancel-rollover" class="px-4 py-2 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold transition">
                        Annuler
                    </button>
                    <button type="button" id="btn-confirm-rollover" class="px-5 py-2 rounded-xl bg-red-600 hover:bg-red-700 text-white text-xs font-bold uppercase tracking-wider transition shadow">
                        Confirmer le basculement
                    </button>
                </div>
            </div>
        </div>

        <!-- Journal d'Audit Applicatif (Réservé au Président - Point 6) -->
        <section class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4">
            <h2 id="audit-logs-title" class="text-base font-bold text-slate-900">Journal d'Audit Applicatif du Club (`app_logs`)</h2>
            <div class="overflow-x-auto rounded-xl border border-slate-200 max-h-60">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-50 text-slate-600 uppercase border-b border-slate-200">
                        <tr>
                            <th class="p-3">ID</th>
                            <th class="p-3">Action</th>
                            <th class="p-3">Cible</th>
                            <th class="p-3">Horodatage</th>
                        </tr>
                    </thead>
                    <tbody id="audit-logs-tbody" class="divide-y divide-slate-100"></tbody>
                </table>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';