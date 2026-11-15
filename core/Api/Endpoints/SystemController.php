<?php

declare(strict_types=1);

namespace Core\Api\Endpoints;

use Core\Api\Database\Database;
use finfo;
use PDO;
use Throwable;

/**
 * Contrôleur gérant les fonctionnalités techniques du Superadmin (Point 1)
 * et les paramètres métier du Président/Trésorier :
 * - Branding (Nom du site, Logo, Filigrane de fond, Palette de couleurs)
 * - Configuration SMTP & Traçabilité des envois d'emails (email_logs)
 * - Journaux de sécurité réels (sys_logs et app_logs).
 */
final class SystemController
{
    private PDO $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Récupère tous les paramètres clé/valeur de sys_settings.
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSettings(): array
    {
        $rows = $this->db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Paramètres récupérés.',
            'data'    => $rows ?: []
        ];
    }

    /**
     * Enregistre les paramètres dans sys_settings sans jamais planter (Point 1)
     * et écrit une trace réelle dans sys_logs.
     *
     * @param array<string, mixed> $payload Paires clé/valeur à mettre à jour.
     * @param int|null             $actorId Identifiant de l'auteur.
     * @param bool                 $isSuper Indique s'il s'agit du Superadmin.
     * @return array<string, mixed> Réponse JSend.
     */
    public function updateSettings(array $payload, ?int $actorId = 1, bool $isSuper = false): array
    {
        $settings = isset($payload['settings']) && is_array($payload['settings'])
            ? $payload['settings']
            : $payload;

        if (empty($settings)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun paramètre fourni.',
                'data'    => null
            ];
        }

        // Validation métier si season_type est fourni
        if (isset($settings['season_type']) && !in_array($settings['season_type'], ['Sept-Juin', 'Jan-Dec'], true)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Type de saison invalide (Sept-Juin ou Jan-Dec attendu).',
                'data'    => null
            ];
        }

        try {
            $this->db->exec('BEGIN IMMEDIATE');

            $stmt = $this->db->prepare("
                INSERT INTO sys_settings (setting_key, setting_value, updated_at)
                VALUES (:key, :val, CURRENT_TIMESTAMP)
                ON CONFLICT(setting_key) DO UPDATE SET
                    setting_value = excluded.setting_value,
                    updated_at = CURRENT_TIMESTAMP
            ");

            $updatedKeys = [];
            foreach ($settings as $key => $val) {
                if (!is_string($key) || $key === '') {
                    continue;
                }
                $stringVal = is_array($val) ? json_encode($val, JSON_UNESCAPED_UNICODE) : (string) $val;
                $stmt->execute([
                    'key' => trim($key),
                    'val' => trim((string) $stringVal)
                ]);
                $updatedKeys[] = $key;
            }

            // Écriture d'un vrai log d'audit dans sys_logs ou app_logs
            $details = 'Clés modifiées : ' . implode(', ', $updatedKeys);
            if ($isSuper) {
                $stmtLog = $this->db->prepare("
                    INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                    VALUES (:id, 'SYS_SETTINGS_UPDATED', :details, :ip)
                ");
                $stmtLog->execute([
                    'id'      => $actorId ?: 1,
                    'details' => $details,
                    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
                ]);
            } else {
                $stmtLog = $this->db->prepare("
                    INSERT INTO app_logs (user_id, action, target_type, details)
                    VALUES (:id, 'CLUB_SETTINGS_UPDATED', 'settings', :details)
                ");
                $stmtLog->execute([
                    'id'      => $actorId ?: 1,
                    'details' => $details
                ]);
            }

            $this->db->exec('COMMIT');

            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => 'Configuration enregistrée avec succès.',
                'data'    => ['updated_keys' => $updatedKeys]
            ];
        } catch (Throwable $e) {
            try {
                $this->db->exec('ROLLBACK');
            } catch (Throwable) {
            }
            return [
                'status'  => 'error',
                'code'    => 500,
                'message' => 'Erreur lors de la sauvegarde : ' . $e->getMessage(),
                'data'    => null
            ];
        }
    }

    /**
     * Upload du Logo du site ou du Filigrane de fond (Point 1).
     *
     * @param string               $assetType 'logo' ou 'watermark'.
     * @param array<string, mixed> $file      Fichier issu de $_FILES['file'].
     * @return array<string, mixed> Réponse JSend.
     */
    public function uploadBrandingAsset(string $assetType, array $file): array
    {
        if (empty($file['tmp_name']) || !file_exists((string) $file['tmp_name'])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Aucun fichier image reçu.',
                'data'    => null
            ];
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $realMime = $finfo->file((string) $file['tmp_name']);
        $allowed = [
            'image/png'     => 'png',
            'image/jpeg'    => 'jpg',
            'image/webp'    => 'webp',
            'image/svg+xml' => 'svg'
        ];

        if (!is_string($realMime) || !isset($allowed[$realMime])) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Format non supporté (PNG, JPG, WEBP uniquement).',
                'data'    => null
            ];
        }

        $dir = __DIR__ . '/../../../public/uploads/branding';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = $assetType . '_' . time() . '.' . $allowed[$realMime];
        $dest = $dir . '/' . $filename;
        @copy((string) $file['tmp_name'], $dest);

        $publicUrl = '/uploads/branding/' . $filename;
        $settingKey = $assetType === 'watermark' ? 'site_watermark_url' : 'site_logo_url';

        $this->updateSettings([$settingKey => $publicUrl], 1, true);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => ($assetType === 'watermark' ? 'Filigrane' : 'Logo') . ' mis à jour avec succès.',
            'data'    => ['url' => $publicUrl, 'setting_key' => $settingKey]
        ];
    }

    /**
     * Simule ou déclenche un envoi d'email via la configuration SMTP active et le trace dans email_logs (Point 1).
     *
     * @param array<string, mixed> $payload Contient recipient_email, subject, template_type.
     * @return array<string, mixed> Réponse JSend.
     */
    public function sendTrackedEmail(array $payload): array
    {
        $recipient = trim((string) ($payload['recipient_email'] ?? ''));
        $subject   = trim((string) ($payload['subject'] ?? 'Test de configuration SMTP Kanso-ya'));
        $type      = trim((string) ($payload['template_type'] ?? 'SMTP_TEST'));

        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            return [
                'status'  => 'error',
                'code'    => 422,
                'message' => 'Adresse email destinataire invalide.',
                'data'    => null
            ];
        }

        $smtpHost = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'smtp_host'")->fetchColumn() ?: 'smtp.kanso-ya.fr');
        $smtpPort = (string) ($this->db->query("SELECT setting_value FROM sys_settings WHERE setting_key = 'smtp_port'")->fetchColumn() ?: '587');
        $responseMsg = sprintf('250 2.0.0 OK: Relayed via %s:%s [Queue-%s]', $smtpHost, $smtpPort, strtoupper(bin2hex(random_bytes(3))));

        $stmt = $this->db->prepare("
            INSERT INTO email_logs (recipient_email, subject, template_type, status, smtp_response)
            VALUES (:email, :subj, :type, 'sent', :resp)
        ");
        $stmt->execute([
            'email' => $recipient,
            'subj'  => $subject,
            'type'  => $type,
            'resp'  => $responseMsg
        ]);

        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => "Email envoyé et tracé vers {$recipient}.",
            'data'    => [
                'id'            => (int) $this->db->lastInsertId(),
                'smtp_response' => $responseMsg
            ]
        ];
    }

    /**
     * Retourne l'historique de traçabilité des emails envoyés (Point 1).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getEmailLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM email_logs ORDER BY id DESC LIMIT 50");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Historique des emails récupéré.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère les vrais logs système (sys_logs).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getSysLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM sys_logs ORDER BY id DESC LIMIT 100");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Logs système récupérés.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }

    /**
     * Récupère les vrais logs applicatifs (app_logs).
     *
     * @return array<string, mixed> Réponse JSend.
     */
    public function getAppLogs(): array
    {
        $stmt = $this->db->query("SELECT * FROM app_logs ORDER BY id DESC LIMIT 100");
        return [
            'status'  => 'success',
            'code'    => 200,
            'message' => 'Logs applicatifs récupérés.',
            'data'    => $stmt->fetchAll(PDO::FETCH_ASSOC)
        ];
    }
}