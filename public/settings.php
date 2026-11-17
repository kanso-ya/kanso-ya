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

$pageTitle     = 'Paramètres du Club';
$activeNav     = 'settings';
$requireAuth   = true;
$requiredPerms = ['settings.manage', 'roles.manage'];
$pageScripts   = ['/assets/ts/settings.js'];

$db = Database::getInstance();
$settings = $db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR) ?: [];

$seasonType         = (string) ($settings['season_type'] ?? 'Sept-Juin');
$basePrice          = (string) ($settings['membership_base_price'] ?? '150');
$regEnabled         = (string) ($settings['public_registration_enabled'] ?? '1');
$confirmTxt         = (string) ($settings['registration_confirmation_text'] ?? '');
$discountThreshold  = (int) ($settings['discount_threshold'] ?? 2);
$discountType       = (string) ($settings['discount_type'] ?? 'percent');
$discountValue      = (float) ($settings['discount_value'] ?? 10.0);
$medicalCertMode    = (string) ($settings['medical_cert_mode'] ?? 'health_questionnaire');

ob_start();
?>
<div class="space-y-8">
    <!-- En-tête Paramètres du Club & Navigation à Onglets -->
    <div class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-600">Administration du Club — <?= htmlspecialchars($currentRole, ENT_QUOTES, 'UTF-8') ?></span>
            <h1 class="text-2xl font-extrabold text-slate-900 mt-0.5">Paramètres du Club</h1>
            <p class="text-xs text-slate-500">Configurez les cotisations, le moteur de remises, la politique médicale, les modules actifs et le rollover de saison.</p>
        </div>

        <!-- Sélecteur d'onglets -->
        <div class="flex items-center bg-slate-100 p-1.5 rounded-xl text-xs font-bold shrink-0 overflow-x-auto">
            <button type="button" id="tab-btn-club-general" class="px-4 py-2 rounded-lg bg-white text-slate-900 shadow-2xs transition">
                ⚙️ 1. Cotisations & Inscriptions
            </button>
            <?php if ($currentRole === 'Président'): ?>
                <button type="button" id="tab-btn-club-modules" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                    🧩 2. Modules & Extensions
                </button>
                <button type="button" id="tab-btn-club-rollover" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                    🔄 3. Basculement de Saison
                </button>
                <button type="button" id="tab-btn-club-logs" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                    📋 4. Journal d'Audit
                </button>
                <button type="button" id="tab-btn-club-pages" class="px-4 py-2 rounded-lg text-slate-600 hover:text-slate-900 transition">
                    📄 5. Pages Pied de Page
                </button>
            <?php endif; ?>
        </div>
    </div>

    <!-- =====================================================================
         ONGLET 1 : COTISATIONS, REMISES & SANTÉ
         ===================================================================== -->
    <section id="tab-panel-club-general" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6">
        <div class="border-b border-slate-100 pb-3">
            <span class="text-xs font-bold uppercase text-amber-600">Paramétrage Général</span>
            <h2 class="text-lg font-bold text-slate-900">Cotisations de Base, Moteur de Remises & Mode Médical</h2>
        </div>

        <form id="form-club-settings" class="space-y-6">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <?php if ($currentRole === 'Président'): ?>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Type de Saison *</label>
                        <select name="season_type" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                            <option value="Sept-Juin" <?= $seasonType === 'Sept-Juin' ? 'selected' : '' ?>>Septembre à Juin (Scolaire)</option>
                            <option value="Jan-Dec" <?= $seasonType === 'Jan-Dec' ? 'selected' : '' ?>>Janvier à Décembre (Civile)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Inscriptions en Ligne</label>
                        <select name="public_registration_enabled" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                            <option value="1" <?= $regEnabled === '1' ? 'selected' : '' ?>>Inscriptions Ouvertes</option>
                            <option value="0" <?= $regEnabled === '0' ? 'selected' : '' ?>>Inscriptions Fermées</option>
                        </select>
                    </div>
                <?php endif; ?>

                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Cotisation annuelle de base (€) *</label>
                    <input type="number" step="0.01" name="membership_base_price" value="<?= htmlspecialchars($basePrice, ENT_QUOTES, 'UTF-8') ?>" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm">
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Capacité théorique par cours (Stats) *</label>
                    <input type="number" min="1" max="500" name="default_course_capacity" id="setting-default-capacity" value="<?= htmlspecialchars((string) ($settings['default_course_capacity'] ?? '30'), ENT_QUOTES, 'UTF-8') ?>" class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                </div>
            </div>

            <!-- Moteur de Remises Paramétrables (Points 7 & 8) -->
            <div class="p-5 rounded-2xl bg-amber-50/50 border border-amber-200/80 space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-amber-900">Moteur de Remises Famille / Multi-Adhérents</h3>
                    <p class="text-xs text-amber-700">Définissez la règle de réduction appliquée automatiquement lors de l'inscription groupée d'une famille.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-amber-800 mb-1">Seuil Déclencheur (Nb inscrits)</label>
                        <input type="number" min="1" max="10" name="discount_threshold" value="<?= $discountThreshold ?>" class="w-full border border-amber-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-amber-800 mb-1">Type de Réduction</label>
                        <select name="discount_type" class="w-full border border-amber-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                            <option value="percent" <?= $discountType === 'percent' ? 'selected' : '' ?>>Pourcentage (%)</option>
                            <option value="fixed" <?= $discountType === 'fixed' ? 'selected' : '' ?>>Montant Fixe (€)</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold uppercase text-amber-800 mb-1">Valeur de la Remise</label>
                        <input type="number" step="0.01" min="0" name="discount_value" value="<?= $discountValue ?>" class="w-full border border-amber-300 rounded-xl px-3.5 py-2 text-sm bg-white">
                    </div>
                </div>
            </div>

            <!-- Mode Certificat Médical & Questionnaire Santé (Points 9, 12, 13) -->
            <div class="p-5 rounded-2xl bg-slate-50 border border-slate-200 space-y-4">
                <div>
                    <h3 class="text-sm font-bold text-slate-900">Réglementation Santé & Certificats</h3>
                    <p class="text-xs text-slate-500">Choisissez le mode d'exigence médicale requis lors de l'adhésion.</p>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <label class="p-3.5 rounded-xl border <?= $medicalCertMode === 'health_questionnaire' ? 'border-emerald-500 bg-emerald-50/50' : 'border-slate-200 bg-white' ?> cursor-pointer flex items-start gap-3">
                        <input type="radio" name="medical_cert_mode" value="health_questionnaire" <?= $medicalCertMode === 'health_questionnaire' ? 'checked' : '' ?> class="mt-0.5 text-emerald-600">
                        <div>
                            <strong class="text-xs text-slate-900 block">Questionnaire Cerfa QS-Sport</strong>
                            <span class="text-[11px] text-slate-500">Auto-évaluation sur l'honneur sans rétention de données médicales (RGPD).</span>
                        </div>
                    </label>
                    <label class="p-3.5 rounded-xl border <?= $medicalCertMode === 'mandatory' ? 'border-emerald-500 bg-emerald-50/50' : 'border-slate-200 bg-white' ?> cursor-pointer flex items-start gap-3">
                        <input type="radio" name="medical_cert_mode" value="mandatory" <?= $medicalCertMode === 'mandatory' ? 'checked' : '' ?> class="mt-0.5 text-emerald-600">
                        <div>
                            <strong class="text-xs text-slate-900 block">Certificat Médical Obligatoire</strong>
                            <span class="text-[11px] text-slate-500">Téléversement obligatoire dès la souscription en ligne.</span>
                        </div>
                    </label>
                    <label class="p-3.5 rounded-xl border <?= $medicalCertMode === 'optional' ? 'border-emerald-500 bg-emerald-50/50' : 'border-slate-200 bg-white' ?> cursor-pointer flex items-start gap-3">
                        <input type="radio" name="medical_cert_mode" value="optional" <?= $medicalCertMode === 'optional' ? 'checked' : '' ?> class="mt-0.5 text-emerald-600">
                        <div>
                            <strong class="text-xs text-slate-900 block">Certificat Optionnel / Différé</strong>
                            <span class="text-[11px] text-slate-500">Peut être remis en main propre lors de la rentrée sportive.</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Texte explicatif validation OTP -->
            <div>
                <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Texte explicatif affiché après validation d'une inscription (Procédure OTP)</label>
                <textarea name="registration_confirmation_text" rows="3" class="w-full border border-slate-300 rounded-xl p-3.5 text-sm"><?= htmlspecialchars($confirmTxt, ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" id="btn-save-settings" class="bg-slate-900 hover:bg-slate-800 text-white px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                    Enregistrer les paramètres du club
                </button>
            </div>
        </form>
    </section>

    <?php if ($currentRole === 'Président'): ?>
        <!-- =====================================================================
             ONGLET 2 : GESTION DES MODULES (PRÉSIDENT / SUPERADMIN - US 6.3)
             ===================================================================== -->
        <section id="tab-panel-club-modules" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6 hidden">
            <div class="border-b border-slate-100 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-bold uppercase text-purple-600">Extensibilité & Écosystème SDK</span>
                    <h2 class="text-lg font-bold text-slate-900">Modules Installés & Extensions</h2>
                    <p class="text-xs text-slate-500">Activez ou désactivez les extensions du club. Les modules défaillants sont isolés en Safe Mode automatique.</p>
                </div>
                <button type="button" id="btn-scan-modules" class="px-4 py-2 bg-purple-50 hover:bg-purple-100 text-purple-700 text-xs font-bold rounded-xl transition border border-purple-200 shrink-0">
                    🔄 Rescanner le dossier /modules/
                </button>
            </div>

            <div id="modules-list-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="p-6 text-center text-slate-400 text-xs italic col-span-2">Chargement des modules...</div>
            </div>
        </section>

        <!-- =====================================================================
             ONGLET 3 : BASCULEMENT DE SAISON (ROLLOVER) (US 5.1)
             ===================================================================== -->
        <section id="tab-panel-club-rollover" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6 hidden">
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

        <!-- =====================================================================
             ONGLET 4 : JOURNAL D'AUDIT APPLICATIF (PRÉSIDENT)
             ===================================================================== -->
        <section id="tab-panel-club-logs" class="bg-white p-6 rounded-2xl shadow-xs border border-slate-200 space-y-4 hidden">
            <h2 id="audit-logs-title" class="text-base font-bold text-slate-900">Journal d'Audit Applicatif du Club (`app_logs`)</h2>
            <div class="overflow-x-auto rounded-xl border border-slate-200 max-h-96">
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
        <!-- =====================================================================
             ONGLET 5 : ÉDITEUR DES PAGES DU PIED DE PAGE (Point 16b)
             - /horaires, /mentions-legales, /rgpd, /cgv
             ===================================================================== -->
        <section id="tab-panel-club-pages" class="bg-white p-6 md:p-8 rounded-2xl shadow-xs border border-slate-200 space-y-6 hidden">
            <div class="border-b border-slate-100 pb-3 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-bold uppercase text-emerald-600">Pages Institutionnelles & Légales</span>
                    <h2 class="text-lg font-bold text-slate-900">Éditeur des Pages du Pied de Page</h2>
                    <p class="text-xs text-slate-500">Personnalisez le contenu affiché sur les pages publiques /horaires, /mentions-legales, /rgpd et /cgv.</p>
                </div>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Sélectionner la page à éditer *</label>
                    <select id="select-institutional-page" class="w-full sm:w-80 border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                        <option value="horaires">Horaires des Cours & Tarifs (/horaires)</option>
                        <option value="mentions-legales">Mentions Légales (/mentions-legales)</option>
                        <option value="rgpd">Politique de Confidentialité (/rgpd)</option>
                        <option value="cgv">Conditions Générales d'Adhésion (/cgv)</option>
                    </select>
                </div>

                <form id="form-institutional-page" class="space-y-4">
                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Titre de la Page *</label>
                        <input type="text" id="page-title-input" required class="w-full border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Statut de la Page *</label>
                        <select id="page-status-select" class="w-full sm:w-48 border border-slate-300 rounded-xl px-3.5 py-2.5 text-sm bg-white">
                            <option value="published">Publiée</option>
                            <option value="draft">Brouillon</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold uppercase text-slate-600 mb-1">Contenu HTML / Texte *</label>
                        <textarea id="page-content-input" rows="10" required class="w-full border border-slate-300 rounded-xl p-3.5 text-sm font-mono"></textarea>
                    </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit" id="btn-save-institutional-page" class="brand-primary-bg hover:opacity-95 text-white px-6 py-2.5 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                            Enregistrer la page institutionnelle
                        </button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../core/ui/templates/layout.php';
