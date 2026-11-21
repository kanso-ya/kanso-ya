<?php

declare(strict_types=1);

namespace Core\Api\Services;

use RuntimeException;

/**
 * Client SMTP natif PHP 8.5 (sans dépendance externe).
 * Supporte TCP clair (none), SSL/TLS implicite (ssl) et STARTTLS (tls),
 * l'authentification AUTH LOGIN / AUTH PLAIN, les réponses multi-lignes RFC 5321
 * et le dot-stuffing RFC 5321 sur la commande DATA.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $socket = null;

    /**
     * Expédie un message RFC 5322 vers un serveur SMTP distant ou local.
     *
     * @param array{
     *     host: string,
     *     port: int,
     *     encryption: string,
     *     timeout: int,
     *     username: string,
     *     password: string,
     *     from_email: string,
     *     from_name: string
     * } $config Configuration de connexion SMTP.
     * @param string $recipient Adresse e-mail destinataire.
     * @param string $subject   Sujet UTF-8 du message.
     * @param string $bodyHtml  Contenu HTML UTF-8 du message.
     * @return string Réponse finale du serveur SMTP (ex: "250 2.0.0 OK ...").
     * @throws RuntimeException En cas d'erreur de connexion, TLS, authentification ou rejet SMTP.
     */
    public function send(array $config, string $recipient, string $subject, string $bodyHtml): string
    {
        $host = trim($config['host']);
        $port = max(1, min(65535, (int) $config['port']));
        $encryption = strtolower(trim($config['encryption']));
        $timeout = max(2, min(30, (int) $config['timeout']));
        $username = trim($config['username']);
        $password = $config['password'];
        $fromEmail = $this->sanitizeAddress($config['from_email']);
        $fromName = $this->sanitizeHeader($config['from_name']);
        $toEmail = $this->sanitizeAddress($recipient);

        if ($host === '') {
            throw new RuntimeException('Serveur hôte SMTP manquant.');
        }
        if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse expéditeur SMTP invalide.');
        }
        if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Adresse destinataire invalide.');
        }

        $scheme = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $remote = sprintf('%s%s:%d', $scheme, $host, $port);

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
                'allow_self_signed' => in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true),
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $remote,
            $errno,
            $errstr,
            (float) $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            $detail = $errstr !== '' ? $errstr : 'Connexion refusée ou expirée';
            throw new RuntimeException(sprintf('Connexion SMTP impossible (%s:%d) : [%d] %s', $host, $port, $errno, $detail));
        }

        $this->socket = $socket;
        stream_set_timeout($this->socket, $timeout);

        try {
            // 1. Bannière serveur (220)
            $this->expectResponse([220], 'Bannière SMTP');

            // 2. EHLO (fallback HELO)
            $ehloDomain = $this->resolveClientDomain();
            $ehloResp = $this->sendCommand('EHLO ' . $ehloDomain);
            if ($ehloResp['code'] !== 250) {
                $this->commandExpect('HELO ' . $ehloDomain, [250], 'HELO');
                $ehloCapabilities = '';
            } else {
                $ehloCapabilities = strtoupper($ehloResp['message']);
            }

            // 3. STARTTLS si demandé
            if ($encryption === 'tls') {
                $this->commandExpect('STARTTLS', [220], 'STARTTLS');
                $cryptoEnabled = @stream_socket_enable_crypto(
                    $this->socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );
                if ($cryptoEnabled !== true) {
                    throw new RuntimeException('Échec de la négociation STARTTLS avec le serveur SMTP.');
                }
                $ehloResp = $this->commandExpect('EHLO ' . $ehloDomain, [250], 'EHLO après STARTTLS');
                $ehloCapabilities = strtoupper($ehloResp['message']);
            }

            // 4. Authentification si identifiant renseigné
            if ($username !== '') {
                $this->authenticate($username, $password, $ehloCapabilities);
            }

            // 5. Enveloppe SMTP
            $this->commandExpect('MAIL FROM:<' . $fromEmail . '>', [250], 'MAIL FROM');
            $this->commandExpect('RCPT TO:<' . $toEmail . '>', [250, 251], 'RCPT TO');

            // 6. Données MIME
            $this->commandExpect('DATA', [354], 'DATA');
            $rawMessage = $this->buildMimeMessage($fromEmail, $fromName, $toEmail, $subject, $bodyHtml);
            $this->writeRaw($rawMessage . "\r\n.\r\n");
            $dataResp = $this->expectResponse([250], 'Fin de transmission DATA');

            // 7. Fermeture propre
            try {
                $this->sendCommand('QUIT');
            } catch (RuntimeException) {
            }

            return trim($dataResp['message']) !== ''
                ? $dataResp['message']
                : sprintf('250 2.0.0 OK: Relayed via %s:%d', $host, $port);
        } finally {
            if (is_resource($this->socket)) {
                @fclose($this->socket);
            }
            $this->socket = null;
        }
    }

    /**
     * Effectue l'authentification SMTP (AUTH LOGIN ou AUTH PLAIN).
     *
     * @param string $username     Identifiant SMTP.
     * @param string $password     Mot de passe SMTP.
     * @param string $capabilities Chaîne EHLO en majuscules.
     * @return void
     */
    private function authenticate(string $username, string $password, string $capabilities): void
    {
        $preferPlain = str_contains($capabilities, 'PLAIN') && !str_contains($capabilities, 'LOGIN');

        if ($preferPlain) {
            $token = base64_encode("\0" . $username . "\0" . $password);
            $this->commandExpect('AUTH PLAIN ' . $token, [235], 'AUTH PLAIN');
            return;
        }

        $resp = $this->sendCommand('AUTH LOGIN');
        if ($resp['code'] === 334) {
            $this->commandExpect(base64_encode($username), [334], 'AUTH LOGIN (utilisateur)');
            $this->commandExpect(base64_encode($password), [235], 'AUTH LOGIN (mot de passe)');
            return;
        }

        // Fallback AUTH PLAIN si AUTH LOGIN non supporté (504 / 500)
        $token = base64_encode("\0" . $username . "\0" . $password);
        $this->commandExpect('AUTH PLAIN ' . $token, [235], 'AUTH PLAIN');
    }

    /**
     * Construit un message RFC 5322 UTF-8 HTML avec dot-stuffing RFC 5321.
     *
     * @param string $fromEmail Adresse expéditeur.
     * @param string $fromName  Nom affiché expéditeur.
     * @param string $toEmail   Adresse destinataire.
     * @param string $subject   Sujet UTF-8.
     * @param string $bodyHtml  Corps HTML UTF-8.
     * @return string Message prêt pour la commande DATA.
     */
    private function buildMimeMessage(
        string $fromEmail,
        string $fromName,
        string $toEmail,
        string $subject,
        string $bodyHtml
    ): string {
        $encodedSubject = '=?UTF-8?B?' . base64_encode($this->sanitizeHeader($subject)) . '?=';
        $fromHeader = $fromName !== ''
            ? sprintf('=?UTF-8?B?%s?= <%s>', base64_encode($fromName), $fromEmail)
            : '<' . $fromEmail . '>';

        $domain = substr(strrchr($fromEmail, '@') ?: '@kanso-ya.local', 1);
        $messageId = sprintf('<%s.%s@%s>', bin2hex(random_bytes(8)), (string) time(), $domain);

        $headers = [
            'Date: ' . gmdate('D, d M Y H:i:s +0000'),
            'From: ' . $fromHeader,
            'To: <' . $toEmail . '>',
            'Subject: ' . $encodedSubject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            'X-Mailer: Kanso-ya-SMTP/2.1',
        ];

        $encodedBody = rtrim(chunk_split(base64_encode($bodyHtml), 76, "\r\n"));
        $fullMessage = implode("\r\n", $headers) . "\r\n\r\n" . $encodedBody;

        // Normalisation CRLF + dot-stuffing RFC 5321
        $normalized = str_replace(["\r\n", "\r"], "\n", $fullMessage);
        $normalized = str_replace("\n", "\r\n", $normalized);
        return preg_replace('/^\./m', '..', $normalized) ?? $normalized;
    }

    /**
     * Envoie une commande SMTP et valide le code de retour attendu.
     *
     * @param string    $command       Commande SMTP sans CRLF.
     * @param list<int> $expectedCodes Codes SMTP acceptés.
     * @param string    $stepLabel     Libellé de l'étape pour les messages d'erreur.
     * @return array{code: int, message: string}
     */
    private function commandExpect(string $command, array $expectedCodes, string $stepLabel): array
    {
        $resp = $this->sendCommand($command);
        if (!in_array($resp['code'], $expectedCodes, true)) {
            throw new RuntimeException(sprintf('Erreur SMTP [%s] : %s', $stepLabel, $resp['message']));
        }
        return $resp;
    }

    /**
     * Envoie une commande SMTP suivie de CRLF et lit la réponse serveur.
     *
     * @param string $command Commande SMTP sans CRLF.
     * @return array{code: int, message: string}
     */
    private function sendCommand(string $command): array
    {
        $this->writeRaw($command . "\r\n");
        return $this->readResponse();
    }

    /**
     * Lit la réponse SMTP attendue et vérifie son code.
     *
     * @param list<int> $expectedCodes Codes SMTP acceptés.
     * @param string    $stepLabel     Libellé de l'étape.
     * @return array{code: int, message: string}
     */
    private function expectResponse(array $expectedCodes, string $stepLabel): array
    {
        $resp = $this->readResponse();
        if (!in_array($resp['code'], $expectedCodes, true)) {
            throw new RuntimeException(sprintf('Erreur SMTP [%s] : %s', $stepLabel, $resp['message']));
        }
        return $resp;
    }

    /**
     * Écrit des octets bruts sur la socket SMTP active.
     *
     * @param string $data Données à transmettre.
     * @return void
     */
    private function writeRaw(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket SMTP fermée de manière inattendue.');
        }

        $total = strlen($data);
        $written = 0;
        while ($written < $total) {
            $chunk = @fwrite($this->socket, substr($data, $written));
            if ($chunk === false || $chunk === 0) {
                throw new RuntimeException('Échec d\'écriture sur la connexion SMTP.');
            }
            $written += $chunk;
        }
    }

    /**
     * Lit une réponse SMTP (simple ou multi-lignes) depuis la socket.
     *
     * @return array{code: int, message: string}
     */
    private function readResponse(): array
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('Socket SMTP fermée de manière inattendue.');
        }

        $lines = [];
        $code = 0;

        while (true) {
            $line = @fgets($this->socket, 1024);
            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Timeout lors de la lecture de la réponse SMTP.');
                }
                break;
            }

            $trimmed = rtrim($line, "\r\n");
            $lines[] = $trimmed;

            if (preg_match('/^(\d{3})([ -])(.*)$/', $trimmed, $m) === 1) {
                $code = (int) $m[1];
                if ($m[2] === ' ') {
                    break;
                }
            } else {
                break;
            }
        }

        if ($code === 0) {
            throw new RuntimeException('Réponse SMTP vide ou invalide reçue du serveur.');
        }

        return [
            'code'    => $code,
            'message' => implode(' | ', $lines),
        ];
    }

    /**
     * Nettoie une adresse e-mail contre l'injection CRLF.
     *
     * @param string $email Adresse brute.
     * @return string Adresse assainie.
     */
    private function sanitizeAddress(string $email): string
    {
        return trim(str_replace(["\r", "\n", '<', '>'], '', $email));
    }

    /**
     * Nettoie un en-tête texte contre l'injection CRLF.
     *
     * @param string $value Valeur d'en-tête brute.
     * @return string Valeur assainie.
     */
    private function sanitizeHeader(string $value): string
    {
        return trim(str_replace(["\r", "\n"], ' ', $value));
    }

    /**
     * Détermine le nom d'hôte client annoncé lors du EHLO.
     *
     * @return string Nom de domaine client valide.
     */
    private function resolveClientDomain(): string
    {
        $host = (string) ($_SERVER['SERVER_NAME'] ?? gethostname() ?: 'localhost');
        $clean = preg_replace('/[^a-zA-Z0-9.-]/', '', $host);
        return is_string($clean) && $clean !== '' ? $clean : 'localhost';
    }
}
