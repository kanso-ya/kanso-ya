<?php

declare(strict_types=1);

if (!defined('KANSO_ENTRY')) {
    http_response_code(403);
    exit('Direct access forbidden.');
}

$pageTitle     = 'Messagerie SMTP & Templates d\'Emails';
$activeNav     = 'sys-smtp';
$requireAuth   = true;
$requiredPerms = ['system.admin', 'settings.manage'];
$pageScripts   = ['/assets/ts/sys-console.js'];

$defaultSmtpTabs = [
    ['id' => 'config', 'label' => '⚙️ 1. Configuration Serveur Mail', 'active' => true],
    ['id' => 'templates', 'label' => '📝 2. Modèles d\'Emails', 'active' => false],
    ['id' => 'history', 'label' => '📬 3. Historique des Envois', 'active' => false],
];
$smtpTabs = \Core\Api\Sdk\UiHelpers::tabs('sys-smtp', $defaultSmtpTabs, ['role' => 'Superadmin']);
$coreSmtpTabIds = ['config', 'templates', 'history'];

ob_start();
?>
<div class="space-y-6 text-slate-100">
    <div class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <span class="text-xs font-bold uppercase text-emerald-400">Communication & Notifications</span>
            <h1 class="text-2xl font-extrabold text-white mt-1">Serveur SMTP & Modèles d'Emails Transactionnels</h1>
            <p class="text-xs text-slate-400 mt-1">Configurez le serveur d'envoi, personnalisez les e-mails automatiques et consultez l'historique des envois.</p>
        </div>
    </div>

    <!-- Barre d'Onglets SMTP (Sprint 14 Point 20 & Sprint 18 ui_tabs) -->
    <div id="smtp-tabs-bar" data-testid="smtp-tabs" role="tablist" aria-label="Onglets messagerie SMTP" class="flex items-center gap-2 border-b border-slate-800 pb-2 overflow-x-auto">
        <?php foreach ($smtpTabs as $t): ?>
            <?php $isActiveSmtp = !empty($t['active']); ?>
            <button type="button"
                    role="tab"
                    aria-selected="<?= $isActiveSmtp ? 'true' : 'false' ?>"
                    aria-controls="smtp-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-smtp-target="<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                    data-testid="smtp-tab-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                    class="smtp-tab-btn px-4 py-2 rounded-xl text-xs font-bold transition shrink-0 <?= $isActiveSmtp ? 'bg-brand-primary text-white shadow-xs' : 'bg-slate-900 border border-slate-700 text-slate-300 hover:bg-slate-800' ?>">
                <?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        <?php endforeach; ?>
    </div>

    <!-- ONGLET 1 : Configuration Serveur Mail & Diagnostic -->
    <div id="smtp-panel-config" data-smtp-panel="config" data-testid="smtp-panel-config" role="tabpanel" class="smtp-tab-panel space-y-6">
        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <h2 class="text-lg font-bold text-white border-b border-slate-800 pb-3">Paramètres du Serveur SMTP Sortant</h2>
            <form id="smtp-settings-form" class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Mode de transport *</label>
                    <select id="smtp-transport" name="smtp_transport" data-testid="smtp-transport-select" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                        <option value="log">Simulation / Log local (sans envoi réseau)</option>
                        <option value="smtp">Serveur SMTP réel (PHP natif)</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Serveur Hôte SMTP *</label>
                    <input type="text" id="smtp-host" name="smtp_host" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Port SMTP & Chiffrement *</label>
                    <div class="grid grid-cols-2 gap-2">
                        <input type="number" id="smtp-port" name="smtp_port" min="1" max="65535" value="587" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white">
                        <select id="smtp-encryption" name="smtp_encryption" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-sm text-white">
                            <option value="tls">STARTTLS (587)</option>
                            <option value="ssl">SSL/TLS (465)</option>
                            <option value="none">Aucun (25)</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Identifiant / Compte SMTP</label>
                    <input type="text" id="smtp-username" name="smtp_username" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <div class="flex items-center justify-between gap-2 mb-1">
                        <label class="block text-xs font-semibold uppercase text-slate-400">Mot de passe SMTP</label>
                        <span id="smtp-password-status" data-testid="smtp-password-status" class="px-2 py-0.5 rounded-full text-[10px] font-bold bg-slate-800 text-slate-400">Non configuré</span>
                    </div>
                    <input type="password" id="smtp-password" name="smtp_password" data-testid="smtp-password-input" autocomplete="new-password" placeholder="Mot de passe SMTP" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                    <label id="smtp-password-clear-wrapper" class="mt-1.5 hidden items-center gap-1.5 text-[11px] text-slate-400 cursor-pointer select-none">
                        <input type="checkbox" id="smtp-password-clear" name="smtp_password_clear" value="1" data-testid="smtp-password-clear" class="rounded border-slate-600 bg-slate-950 text-red-500">
                        <span>Effacer le mot de passe enregistré en base</span>
                    </label>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Timeout réseau (secondes)</label>
                    <input type="number" id="smtp-timeout" name="smtp_timeout" data-testid="smtp-timeout-input" min="2" max="30" value="10" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Email Expéditeur (From) *</label>
                    <input type="email" id="smtp-from-email" name="smtp_from_email" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Nom affiché de l'expéditeur *</label>
                    <input type="text" id="smtp-from-name" name="smtp_from_name" class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white">
                </div>
                <div class="flex items-end justify-end">
                    <button type="submit" id="btn-save-smtp" data-testid="btn-save-smtp" class="w-full bg-emerald-600 hover:bg-emerald-500 text-white py-2.5 px-4 rounded-xl text-xs font-bold uppercase tracking-wider transition">
                        Sauvegarder la configuration SMTP
                    </button>
                </div>
            </form>
        </section>

        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 flex flex-col sm:flex-row sm:items-center justify-between gap-4">
            <div>
                <h3 class="text-sm font-bold text-white">Test de délivrabilité SMTP</h3>
                <p class="text-xs text-slate-400 mt-0.5">Expédiez un message de diagnostic pour vérifier votre serveur sortant.</p>
            </div>
            <div class="flex items-center gap-2">
                <input type="email" id="test-smtp-recipient" data-testid="test-smtp-recipient" placeholder="destinataire@test.fr" class="bg-slate-950 border border-slate-700 rounded-xl px-3 py-2 text-xs text-white">
                <button type="button" id="btn-send-test-email" data-testid="btn-send-test-email" class="bg-blue-600 hover:bg-blue-500 text-white px-4 py-2 rounded-xl text-xs font-bold transition shrink-0">
                    ✉️ Envoyer un mail de test
                </button>
            </div>
        </section>
    </div>

    <!-- ONGLET 2 : Éditeur de Modèles d'Emails Transactionnels (Point 31) -->
    <div id="smtp-panel-templates" data-smtp-panel="templates" data-testid="smtp-panel-templates" role="tabpanel" class="smtp-tab-panel hidden">
        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-b border-slate-800 pb-3">
                <div>
                    <h2 class="text-lg font-bold text-white">Modèles d'E-mails Automatiques</h2>
                    <p class="text-xs text-slate-400">Personnalisez le sujet et le contenu des notifications envoyées par le site.</p>
                </div>
                <select id="select-email-template" data-testid="select-email-template" class="bg-slate-950 border border-slate-700 text-white text-xs font-semibold rounded-xl px-3.5 py-2">
                    <option value="otp_invitation">Invitation d'activation de compte (48h)</option>
                    <option value="registration_received">Accusé de réception dossier</option>
                    <option value="registration_validated">Validation & Reçu officiel</option>
                </select>
            </div>

            <form id="form-edit-email-template" class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Sujet de l'email *</label>
                    <input type="text" id="tpl-subject" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-sm text-white font-semibold">
                </div>

                <div class="p-3 bg-slate-950 rounded-xl border border-slate-800 text-xs text-slate-400">
                    <span class="font-bold text-slate-300 block mb-1">Variables dynamiques disponibles pour ce modèle :</span>
                    <code id="tpl-variables-hint" class="text-emerald-400 font-mono text-[11px]">{{first_name}}, {{last_name}}, {{club_name}}, {{activation_link}}</code>
                </div>

                <div>
                    <label class="block text-xs font-semibold uppercase text-slate-400 mb-1">Corps HTML du message *</label>
                    <textarea id="tpl-body-html" rows="6" required class="w-full bg-slate-950 border border-slate-700 rounded-xl px-3.5 py-2 text-xs font-mono text-white leading-relaxed"></textarea>
                </div>

                <div class="flex justify-end">
                    <button type="submit" id="btn-save-email-template" data-testid="btn-save-template" class="bg-emerald-600 hover:bg-emerald-500 text-white px-5 py-2.5 rounded-xl text-xs font-bold transition">
                        Enregistrer ce modèle d'email
                    </button>
                </div>
            </form>
        </section>
    </div>

    <!-- ONGLET 3 : Historique & Traçabilité des envois d'emails -->
    <div id="smtp-panel-history" data-smtp-panel="history" data-testid="smtp-panel-history" role="tabpanel" class="smtp-tab-panel hidden">
        <section class="bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-3">
            <h2 class="text-lg font-bold text-white border-b border-slate-800 pb-3">Historique des E-mails Envoyés</h2>
            <div class="overflow-x-auto rounded-xl border border-slate-800 max-h-96">
                <table class="w-full text-left border-collapse text-xs">
                    <thead class="bg-slate-950 text-slate-400 uppercase border-b border-slate-800">
                        <tr>
                            <th class="p-2.5">Destinataire</th>
                            <th class="p-2.5">Sujet</th>
                            <th class="p-2.5">Type</th>
                            <th class="p-2.5">Statut</th>
                            <th class="p-2.5">Réponse Serveur SMTP</th>
                            <th class="p-2.5">Date d'envoi</th>
                            <th class="p-2.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody id="email-logs-tbody" class="divide-y divide-slate-800 font-mono"></tbody>
                </table>
            </div>
        </section>
    </div>

    <?php foreach ($smtpTabs as $t): ?>
        <?php if (!in_array((string) $t['id'], $coreSmtpTabIds, true)): ?>
            <div id="smtp-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 data-smtp-panel="<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 data-testid="smtp-panel-<?= htmlspecialchars((string) $t['id'], ENT_QUOTES, 'UTF-8') ?>"
                 role="tabpanel"
                 class="smtp-tab-panel hidden bg-slate-900 border border-slate-800 rounded-2xl p-6 space-y-3">
                <h2 class="text-lg font-bold text-white"><?= htmlspecialchars((string) $t['label'], ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="text-xs text-slate-300">
                    <?= isset($t['content']) ? (string) $t['content'] : '' ?>
                </div>
            </div>
        <?php endif; ?>
    <?php endforeach; ?>
</div>
<?php
$pageContent = ob_get_clean();
require __DIR__ . '/../Templates/layout.php';
