<?php

declare(strict_types=1);

namespace Core\Api\Services;

use Core\Api\Database\Database;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Service centralisé d'expédition et de traçabilité des e-mails transactionnels.
 * Gère :
 * - La lecture de la configuration SMTP (sys_settings + surcharge .env)
 * - Le chiffrement/déchiffrement AES-256-GCM du mot de passe SMTP
 * - La bascule sécurisée en mode log/stub et le confinement réseau sous APP_ENV=testing
 * - La journalisation complète dans email_logs et la relance des e-mails en échec.
 */
final class MailerService
{
    private const ENC_PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private PDO $db;
    private SmtpClient $smtpClient;

    /**
     * @param PDO|null        $db         Connexion PDO optionnelle.
     * @param SmtpClient|null $smtpClient Client SMTP natif optionnel.
     */
    public function __construct(?PDO $db = null, ?SmtpClient $smtpClient = null)
    {
        $this->db = $db ?? Database::getInstance();
        $this->smtpClient = $smtpClient ?? new SmtpClient();
    }

    /**
     * Lit une variable d'environnement depuis l'OS, $_ENV ou le fichier .env racine.
     *
     * @param string $key Nom de la variable.
     * @return string|null Valeur ou null si absente/vide.
     */
    public static function readEnv(string $key): ?string
    {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string) $val;
        }
        if (isset($_ENV[$key]) && (string) $_ENV[$key] !== '') {
            return (string) $_ENV[$key];
        }

        $envFile = dirname(__DIR__, 3) . '/.env';
        if (is_file($envFile)) {
            $parsed = @parse_ini_file($envFile);
            if (is_array($parsed) && isset($parsed[$key]) && (string) $parsed[$key] !== '') {
                return (string) $parsed[$key];
            }
        }

        return null;
    }

    /**
     * Chiffre un secret applicatif (ex. mot de passe SMTP) en AES-256-GCM.
     *
     * @param string $plaintext Secret en clair.
     * @return string Chaîne préfixée enc:v1:...
     */
    public static function encryptSecret(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        if (str_starts_with($plaintext, self::ENC_PREFIX)) {
            return $plaintext;
        }

        $key = self::deriveEncryptionKey();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);

        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            throw new RuntimeException('Impossible de chiffrer le secret SMTP via OpenSSL.');
        }

        return self::ENC_PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Déchiffre un secret stocké par encryptSecret().
     *
     * @param string $stored Valeur stockée en base.
     * @return string Secret en clair.
     */
    public static function decryptSecret(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::ENC_PREFIX)) {
            return $stored;
        }

        $raw = base64_decode(substr($stored, strlen(self::ENC_PREFIX)), true);
        if (!is_string($raw) || strlen($raw) < 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plain = openssl_decrypt($ciphertext, self::CIPHER, self::deriveEncryptionKey(), OPENSSL_RAW_DATA, $iv, $tag);

        return is_string($plain) ? $plain : '';
    }

    /**
     * Détermine la source active du mot de passe SMTP : 'env', 'db' ou 'none'.
     *
     * @param array<string, string> $settings Paramètres bruts de sys_settings.
     * @return string 'env'|'db'|'none'.
     */
    public static function resolvePasswordSource(array $settings): string
    {
        if (self::readEnv('SMTP_PASSWORD') !== null) {
            return 'env';
        }
        if (!empty($settings['smtp_password'])) {
            return 'db';
        }
        return 'none';
    }

    /**
     * Envoie (ou journalise en mode log) un e-mail transactionnel et crée sa trace dans email_logs.
     *
     * @param string $recipient    Adresse destinataire.
     * @param string $subject      Sujet du message.
     * @param string $bodyHtml     Corps HTML du message.
     * @param string $templateType Type métier (SMTP_DIAGNOSTIC, OTP_INVITE, PASSWORD_RESET, etc.).
     * @return array{
     *     success: bool,
     *     id: int,
     *     status: string,
     *     smtp_response: string
     * }
     */
    public function send(
        string $recipient,
        string $subject,
        string $bodyHtml,
        string $templateType = 'GENERIC'
    ): array {
        $cleanRecipient = trim($recipient);
        $cleanSubject = trim($subject) !== '' ? trim($subject) : 'Notification Kanso-ya';
        $cleanBody = trim($bodyHtml) !== '' ? $bodyHtml : '<p>' . htmlspecialchars($cleanSubject, ENT_QUOTES, 'UTF-8') . '</p>';
        $cleanType = trim($templateType) !== '' ? trim($templateType) : 'GENERIC';

        $dispatch = $this->dispatchMessage($cleanRecipient, $cleanSubject, $cleanBody);
        $status = $dispatch['success'] ? 'sent' : 'failed';
        $responseMsg = mb_substr($dispatch['smtp_response'], 0, 250);

        $stmt = $this->db->prepare("
            INSERT INTO email_logs (recipient_email, subject, body_html, template_type, status, smtp_response, retry_count, last_retry_at)
            VALUES (:email, :subj, :body, :type, :status, :resp, 0, NULL)
        ");
        $stmt->execute([
            'email'  => $cleanRecipient,
            'subj'   => $cleanSubject,
            'body'   => $cleanBody,
            'type'   => $cleanType,
            'status' => $status,
            'resp'   => $responseMsg,
        ]);

        return [
            'success'       => $dispatch['success'],
            'id'            => (int) $this->db->lastInsertId(),
            'status'        => $status,
            'smtp_response' => $responseMsg,
        ];
    }

    /**
     * Relance un e-mail précédemment en échec dans email_logs.
     *
     * @param int $logId        Identifiant de la ligne email_logs.
     * @param int $superadminId Identifiant du Superadmin déclenchant la relance.
     * @return array<string, mixed> Réponse JSend normalisée.
     */
    public function retryEmailLog(int $logId, int $superadminId = 1): array
    {
        $stmt = $this->db->prepare('SELECT * FROM email_logs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return [
                'status'  => 'error',
                'code'    => 404,
                'message' => 'Trace d\'e-mail introuvable.',
                'data'    => null,
            ];
        }

        if ((string) ($row['status'] ?? '') === 'sent') {
            return [
                'status'  => 'error',
                'code'    => 409,
                'message' => 'Cet e-mail a déjà été envoyé avec succès et ne peut pas être relancé.',
                'data'    => null,
            ];
        }

        $recipient = (string) $row['recipient_email'];
        $subject = (string) $row['subject'];
        $bodyHtml = isset($row['body_html']) && (string) $row['body_html'] !== ''
            ? (string) $row['body_html']
            : '<p>' . htmlspecialchars($subject, ENT_QUOTES, 'UTF-8') . '</p>';

        $dispatch = $this->dispatchMessage($recipient, $subject, $bodyHtml);
        $newStatus = $dispatch['success'] ? 'sent' : 'failed';
        $responseMsg = mb_substr($dispatch['smtp_response'], 0, 250);
        $newRetryCount = ((int) ($row['retry_count'] ?? 0)) + 1;
        $now = date('Y-m-d H:i:s');

        $upd = $this->db->prepare("
            UPDATE email_logs
            SET status = :status,
                smtp_response = :resp,
                retry_count = :retries,
                last_retry_at = :now,
                sent_at = CASE WHEN :is_sent = 1 THEN :now_sent ELSE sent_at END
            WHERE id = :id
        ");
        $upd->execute([
            'status'   => $newStatus,
            'resp'     => $responseMsg,
            'retries'  => $newRetryCount,
            'now'      => $now,
            'is_sent'  => $dispatch['success'] ? 1 : 0,
            'now_sent' => $now,
            'id'       => $logId,
        ]);

        try {
            $logStmt = $this->db->prepare("
                INSERT INTO sys_logs (superadmin_id, action, details, ip_address)
                VALUES (:sid, :action, :details, :ip)
            ");
            $logStmt->execute([
                'sid'     => $superadminId > 0 ? $superadminId : 1,
                'action'  => $dispatch['success'] ? 'EMAIL_RETRY_SUCCESS' : 'EMAIL_RETRY_FAILED',
                'details' => sprintf('Email #%d (%s) — %s', $logId, $recipient, $responseMsg),
                'ip'      => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);
        } catch (Throwable) {
        }

        if ($dispatch['success']) {
            return [
                'status'  => 'success',
                'code'    => 200,
                'message' => sprintf('E-mail #%d relancé avec succès vers %s.', $logId, $recipient),
                'data'    => [
                    'id'            => $logId,
                    'status'        => 'sent',
                    'retry_count'   => $newRetryCount,
                    'smtp_response' => $responseMsg,
                    'last_retry_at' => $now,
                ],
            ];
        }

        return [
            'status'  => 'error',
            'code'    => 502,
            'message' => 'Échec de la relance SMTP : ' . $responseMsg,
            'data'    => [
                'id'            => $logId,
                'status'        => 'failed',
                'retry_count'   => $newRetryCount,
                'smtp_response' => $responseMsg,
                'last_retry_at' => $now,
            ],
        ];
    }

    /**
     * Charge la configuration SMTP effective depuis sys_settings et .env.
     *
     * @return array{
     *     transport: string,
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     timeout: int,
     *     username: string,
     *     password: string,
     *     from_email: string,
     *     from_name: string,
     *     app_env: string
     * }
     */
    public function getEffectiveConfig(): array
    {
        /** @var array<string, string> $settings */
        $settings = $this->db->query("SELECT setting_key, setting_value FROM sys_settings")->fetchAll(PDO::FETCH_KEY_PAIR);

        $envPassword = self::readEnv('SMTP_PASSWORD');
        $password = $envPassword !== null
            ? $envPassword
            : self::decryptSecret((string) ($settings['smtp_password'] ?? ''));

        $transport = strtolower(trim((string) ($settings['smtp_transport'] ?? 'log')));
        if (!in_array($transport, ['log', 'smtp'], true)) {
            $transport = 'log';
        }

        $encryption = strtolower(trim((string) ($settings['smtp_encryption'] ?? 'tls')));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            $encryption = 'tls';
        }

        $port = (int) ($settings['smtp_port'] ?? 587);
        if ($port < 1 || $port > 65535) {
            $port = 587;
        }

        $timeout = (int) ($settings['smtp_timeout'] ?? 10);
        if ($timeout < 2 || $timeout > 30) {
            $timeout = 10;
        }

        return [
            'transport'  => $transport,
            'host'       => trim((string) ($settings['smtp_host'] ?? 'smtp.kanso-ya.fr')),
            'port'       => $port,
            'encryption' => $encryption,
            'timeout'    => $timeout,
            'username'   => trim((string) ($settings['smtp_username'] ?? '')),
            'password'   => $password,
            'from_email' => trim((string) ($settings['smtp_from_email'] ?? 'ne-pas-repondre@kanso-ya.fr')),
            'from_name'  => trim((string) ($settings['smtp_from_name'] ?? 'Secrétariat Kanso-ya')),
            'app_env'    => strtolower(trim((string) (self::readEnv('APP_ENV') ?? 'production'))),
        ];
    }

    /**
     * Exécute réellement l'envoi (mode smtp) ou la journalisation déterministe (mode log).
     *
     * @param string $recipient Adresse destinataire.
     * @param string $subject   Sujet du message.
     * @param string $bodyHtml  Corps HTML du message.
     * @return array{success: bool, smtp_response: string}
     */
    private function dispatchMessage(string $recipient, string $subject, string $bodyHtml): array
    {
        $config = $this->getEffectiveConfig();

        if ($config['transport'] === 'log') {
            $queueId = strtoupper(bin2hex(random_bytes(3)));
            return [
                'success'       => true,
                'smtp_response' => sprintf(
                    '250 2.0.0 OK [LOG/STUB] Relayed via %s:%d [Queue-%s]',
                    $config['host'] !== '' ? $config['host'] : 'localhost',
                    $config['port'],
                    $queueId
                ),
            ];
        }

        // Garde de sécurité en environnement de test : interdiction d'émettre vers des hôtes externes
        if ($config['app_env'] === 'testing') {
            $hostLower = strtolower($config['host']);
            if (!in_array($hostLower, ['127.0.0.1', 'localhost', '::1'], true)) {
                return [
                    'success'       => false,
                    'smtp_response' => sprintf(
                        '550 5.7.1 Blocked external SMTP host in testing environment (%s)',
                        $config['host']
                    ),
                ];
            }
        }

        try {
            $smtpResp = $this->smtpClient->send($config, $recipient, $subject, $bodyHtml);
            return [
                'success'       => true,
                'smtp_response' => $smtpResp,
            ];
        } catch (Throwable $e) {
            return [
                'success'       => false,
                'smtp_response' => $e->getMessage(),
            ];
        }
    }

    /**
     * Dérive une clé symétrique 256 bits pour AES-256-GCM.
     *
     * @return string Clé binaire de 32 octets.
     */
    private static function deriveEncryptionKey(): string
    {
        $appKey = self::readEnv('APP_KEY');
        if ($appKey !== null && $appKey !== '') {
            return hash('sha256', $appKey, true);
        }

        $rootPath = realpath(dirname(__DIR__, 3)) ?: dirname(__DIR__, 3);
        return hash('sha256', 'kanso-ya-smtp-secret-v1|' . $rootPath, true);
    }
}
