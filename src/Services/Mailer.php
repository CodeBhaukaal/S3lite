<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Logger;
use RuntimeException;

/**
 * Pure-PHP SMTP client plus a couple of fallbacks.
 *
 * No dependency: the protocol is spoken directly over a socket, which keeps the
 * "no Composer packages" promise while still supporting STARTTLS, implicit SSL
 * and the usual AUTH mechanisms.
 */
final class Mailer
{
    /** @var resource|null */
    private $socket = null;
    private array $config;
    /** @var list<string> */
    private array $transcript = [];

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? (array) Config::get('mail', []);
    }

    public static function isConfigured(): bool
    {
        $driver = (string) Config::get('mail.driver', 'none');

        if ($driver === 'none') {
            return false;
        }

        return $driver !== 'smtp' || trim((string) Config::get('mail.host', '')) !== '';
    }

    /**
     * @param array{reply_to?:string, text?:string} $options
     * @return array{ok:bool, message:string, transcript?:list<string>}
     */
    public function send(string $to, string $subject, string $html, array $options = []): array
    {
        $driver = (string) ($this->config['driver'] ?? 'none');

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return ['ok' => false, 'message' => 'Not a valid recipient address: ' . $to];
        }

        return match ($driver) {
            'smtp'     => $this->sendSmtp($to, $subject, $html, $options),
            'sendmail' => $this->sendMailFunction($to, $subject, $html, $options),
            'log'      => $this->sendToLog($to, $subject, $html),
            default    => ['ok' => false, 'message' => 'Email is not configured on this server.'],
        };
    }

    /**
     * Prove the settings work without sending anything to a real recipient:
     * connect, negotiate encryption and authenticate, then quit.
     *
     * @return array{ok:bool, message:string, transcript:list<string>}
     */
    public function testConnection(): array
    {
        if (($this->config['driver'] ?? 'none') !== 'smtp') {
            return [
                'ok'         => true,
                'message'    => 'Driver "' . ($this->config['driver'] ?? 'none') . '" needs no connection test.',
                'transcript' => [],
            ];
        }

        $this->transcript = [];

        try {
            $this->connect();
            $this->authenticate();
            $this->command('QUIT', [221]);

            return [
                'ok'         => true,
                'message'    => sprintf(
                    'Connected to %s:%d and authenticated successfully.',
                    (string) $this->config['host'],
                    (int) $this->config['port']
                ),
                'transcript' => $this->transcript,
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage(), 'transcript' => $this->transcript];
        } finally {
            $this->disconnect();
        }
    }

    // --- Drivers ----------------------------------------------------------

    private function sendSmtp(string $to, string $subject, string $html, array $options): array
    {
        $this->transcript = [];

        try {
            $this->connect();
            $this->authenticate();

            $from = (string) ($this->config['from']['address'] ?? 'no-reply@localhost');

            $this->command('MAIL FROM:<' . $from . '>', [250]);
            $this->command('RCPT TO:<' . $to . '>', [250, 251]);
            $this->command('DATA', [354]);

            $this->write($this->buildMessage($to, $subject, $html, $options) . "\r\n.");
            $this->expect([250]);

            $this->command('QUIT', [221]);

            return ['ok' => true, 'message' => 'Message sent to ' . $to, 'transcript' => $this->transcript];
        } catch (\Throwable $e) {
            Logger::error('SMTP send failed: ' . $e->getMessage(), ['to' => $to]);

            return ['ok' => false, 'message' => $e->getMessage(), 'transcript' => $this->transcript];
        } finally {
            $this->disconnect();
        }
    }

    private function sendMailFunction(string $to, string $subject, string $html, array $options): array
    {
        if (!function_exists('mail')) {
            return ['ok' => false, 'message' => 'PHP mail() is not available on this server.'];
        }

        $from = (string) ($this->config['from']['address'] ?? 'no-reply@localhost');
        $name = (string) ($this->config['from']['name'] ?? 'S3 Lite');

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->formatAddress($from, $name),
        ];

        if (!empty($options['reply_to'])) {
            $headers[] = 'Reply-To: ' . $options['reply_to'];
        }

        $sent = @mail($to, $this->encodeHeader($subject), $html, implode("\r\n", $headers));

        return $sent
            ? ['ok' => true, 'message' => 'Handed to PHP mail() for ' . $to]
            : ['ok' => false, 'message' => 'PHP mail() refused the message. Check the server MTA.'];
    }

    private function sendToLog(string $to, string $subject, string $html): array
    {
        Logger::info('Email (log driver)', [
            'to'      => $to,
            'subject' => $subject,
            'body'    => mb_substr(strip_tags($html), 0, 2000),
        ]);

        return ['ok' => true, 'message' => 'Written to the application log instead of being sent.'];
    }

    // --- SMTP conversation ------------------------------------------------

    private function connect(): void
    {
        $host = trim((string) ($this->config['host'] ?? ''));
        $port = (int) ($this->config['port'] ?? 587);
        $encryption = strtolower((string) ($this->config['encryption'] ?? 'tls'));
        $timeout = (int) ($this->config['timeout'] ?? 15);

        if ($host === '') {
            throw new RuntimeException('No SMTP host is configured.');
        }

        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => !($this->config['allow_self_signed'] ?? false),
                'verify_peer_name'  => !($this->config['allow_self_signed'] ?? false),
                'allow_self_signed' => (bool) ($this->config['allow_self_signed'] ?? false),
            ],
        ]);

        // Port 465 speaks TLS from the first byte; 587 upgrades with STARTTLS.
        $endpoint = ($encryption === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client($endpoint, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            throw new RuntimeException(sprintf(
                'Could not reach %s:%d — %s. Check the host, the port, and whether your host blocks outbound SMTP.',
                $host,
                $port,
                $errstr !== '' ? $errstr : 'connection failed'
            ));
        }

        stream_set_timeout($socket, $timeout);
        $this->socket = $socket;

        $this->expect([220]);

        $hello = $this->clientName();
        $this->command('EHLO ' . $hello, [250]);

        if ($encryption === 'tls') {
            $this->command('STARTTLS', [220]);

            $ok = @stream_socket_enable_crypto(
                $this->socket,
                true,
                STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
            );

            if ($ok !== true) {
                throw new RuntimeException(
                    'STARTTLS failed. If the server uses a self-signed certificate, enable that option; '
                    . 'if it expects implicit SSL, use port 465 with encryption set to SSL.'
                );
            }

            // The session restarts after the upgrade.
            $this->command('EHLO ' . $hello, [250]);
        }
    }

    private function authenticate(): void
    {
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        if ($username === '') {
            return;
        }

        try {
            $this->command('AUTH LOGIN', [334]);
            $this->command(base64_encode($username), [334]);
            $this->command(base64_encode($password), [235]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Authentication failed: ' . $e->getMessage()
                . ' — check the username and password. Gmail and Outlook require an app password, not the account password.'
            );
        }
    }

    private function buildMessage(string $to, string $subject, string $html, array $options): string
    {
        $from = (string) ($this->config['from']['address'] ?? 'no-reply@localhost');
        $name = (string) ($this->config['from']['name'] ?? 'S3 Lite');
        $boundary = 'b' . bin2hex(random_bytes(12));
        $text = (string) ($options['text'] ?? strip_tags(str_replace(['<br>', '<br/>', '</p>'], "\n", $html)));

        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->formatAddress($from, $name),
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeHeader($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $this->clientName() . '>',
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];

        if (!empty($options['reply_to'])) {
            $headers[] = 'Reply-To: <' . $options['reply_to'] . '>';
        }

        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text)) . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html)) . "\r\n"
            . '--' . $boundary . "--";

        // A line starting with '.' would end the DATA block early.
        return preg_replace('/^\./m', '..', $body) ?? $body;
    }

    /**
     * @param list<int> $expected
     */
    private function command(string $command, array $expected): string
    {
        $this->write($command);

        // Never let a password reach the transcript shown in the UI.
        $this->transcript[] = '> ' . (preg_match('/^[A-Za-z0-9+\/=]{12,}$/', $command) === 1 ? '<credentials>' : $command);

        return $this->expect($expected);
    }

    private function write(string $data): void
    {
        if (!is_resource($this->socket)) {
            throw new RuntimeException('The SMTP connection was closed unexpectedly.');
        }

        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new RuntimeException('Could not write to the SMTP server.');
        }
    }

    /**
     * @param list<int> $expected
     */
    private function expect(array $expected): string
    {
        $response = '';

        while (is_resource($this->socket)) {
            $line = fgets($this->socket, 1024);

            if ($line === false) {
                $meta = stream_get_meta_data($this->socket);

                throw new RuntimeException(
                    ($meta['timed_out'] ?? false)
                        ? 'The SMTP server stopped responding (timeout).'
                        : 'The SMTP server closed the connection.'
                );
            }

            $response .= $line;

            // Multi-line replies use "250-"; the last one uses "250 ".
            if (strlen($line) < 4 || $line[3] !== '-') {
                break;
            }
        }

        $trimmed = trim($response);
        $this->transcript[] = '< ' . $trimmed;
        $code = (int) substr($trimmed, 0, 3);

        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP server replied: ' . $trimmed);
        }

        return $trimmed;
    }

    private function disconnect(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }

        $this->socket = null;
    }

    private function clientName(): string
    {
        $host = (string) parse_url((string) Config::get('app.url', ''), PHP_URL_HOST);

        return $host !== '' ? $host : (gethostname() ?: 'localhost');
    }

    private function formatAddress(string $address, string $name): string
    {
        return $name === '' ? '<' . $address . '>' : $this->encodeHeader($name) . ' <' . $address . '>';
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[\x80-\xFF]/', $value) === 1
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    /** A branded wrapper so every message from the platform looks the same. */
    public static function template(string $heading, string $body, ?string $buttonLabel = null, ?string $buttonUrl = null): string
    {
        $site = e((string) Config::get('app.name', 'S3 Lite'));
        $accent = '#4f7cff';

        $button = $buttonLabel !== null && $buttonUrl !== null
            ? '<p style="margin:24px 0"><a href="' . e($buttonUrl) . '" style="background:' . $accent
                . ';color:#fff;padding:11px 20px;border-radius:6px;text-decoration:none;font-weight:600;display:inline-block">'
                . e($buttonLabel) . '</a></p>'
            : '';

        return '<!doctype html><html><body style="margin:0;background:#f4f6fb;font-family:-apple-system,Segoe UI,Roboto,Arial,sans-serif">'
            . '<div style="max-width:560px;margin:32px auto;background:#fff;border-radius:12px;padding:28px;color:#131722">'
            . '<div style="font-weight:700;font-size:17px;color:' . $accent . ';margin-bottom:18px">' . $site . '</div>'
            . '<h1 style="font-size:20px;margin:0 0 12px">' . e($heading) . '</h1>'
            . '<div style="font-size:14px;line-height:1.6;color:#3d4757">' . $body . '</div>'
            . $button
            . '<hr style="border:0;border-top:1px solid #e2e8f2;margin:24px 0">'
            . '<div style="font-size:12px;color:#8b95a7">Sent by ' . $site . '.</div>'
            . '</div></body></html>';
    }
}
