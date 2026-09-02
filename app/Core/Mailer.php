<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * E-Mail-Versand: bevorzugt ueber die in den Einstellungen hinterlegten
 * SMTP-Zugangsdaten (eigener Mini-Client, keine Bibliothek noetig),
 * ohne Konfiguration als Fallback ueber PHP mail().
 *
 * SMTP: EHLO -> STARTTLS (Port 587) bzw. implizites TLS (Port 465) ->
 * AUTH LOGIN -> MAIL FROM/RCPT TO/DATA. UTF-8-Betreff und -Text.
 */
final class Mailer
{
    /** Ist SMTP konfiguriert (sonst laeuft der Versand ueber mail())? */
    public static function smtpConfigured(): bool
    {
        return trim(Setting::get('smtp_host')) !== '';
    }

    /**
     * Mail senden. Liefert '' bei Erfolg, sonst eine Fehlermeldung.
     */
    public static function send(string $to, string $subject, string $body): string
    {
        $fromMail = trim(Setting::get('smtp_from'))
            ?: trim((string) Config::get('mail_from', ''))
            ?: ('no-reply@' . (string) preg_replace('/^www\.|:.*$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost')));
        $fromName = trim(Setting::get('smtp_from_name')) ?: (Setting::get('club_name') ?: 'Gym141');

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return 'Ungültige Empfängeradresse.';
        }

        if (!self::smtpConfigured()) {
            $headers = 'From: ' . self::encodeHeader($fromName) . " <$fromMail>\r\n"
                . "MIME-Version: 1.0\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n";

            return @mail($to, self::encodeHeader($subject), $body, $headers)
                ? ''
                : 'PHP mail() ist auf diesem Server nicht verfügbar – bitte SMTP-Zugangsdaten hinterlegen.';
        }

        try {
            self::smtpSend($fromMail, $fromName, $to, $subject, $body);

            return '';
        } catch (\Throwable $e) {
            return 'SMTP-Versand fehlgeschlagen: ' . $e->getMessage();
        }
    }

    // ------------------------------------------------------------------ SMTP --

    private static function smtpSend(string $fromMail, string $fromName, string $to, string $subject, string $body): void
    {
        $host   = trim(Setting::get('smtp_host'));
        $port   = (int) (Setting::get('smtp_port') ?: '587');
        $secure = Setting::get('smtp_secure') ?: 'tls';   // tls (STARTTLS) | ssl | none
        $user   = trim(Setting::get('smtp_user'));
        $pass   = Setting::get('smtp_pass');

        $context = stream_context_create(['ssl' => [
            'verify_peer'      => true,
            'verify_peer_name' => true,
        ]]);

        $adresse = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $sock    = @stream_socket_client($adresse, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);

        if ($sock === false) {
            throw new \RuntimeException("Verbindung zu $host:$port fehlgeschlagen ($error).");
        }

        stream_set_timeout($sock, 15);

        $lies = static function () use ($sock): string {
            $antwort = '';

            while (($zeile = fgets($sock, 1024)) !== false) {
                $antwort .= $zeile;

                if (strlen($zeile) < 4 || $zeile[3] !== '-') {
                    break; // letzte Zeile einer Mehrzeilen-Antwort
                }
            }

            return $antwort;
        };

        $sende = static function (string $cmd, array $ok) use ($sock, $lies): string {
            fwrite($sock, $cmd . "\r\n");
            $antwort = $lies();
            $code    = (int) substr($antwort, 0, 3);

            if (!in_array($code, $ok, true)) {
                throw new \RuntimeException(trim($antwort) ?: 'keine Antwort auf ' . strtok($cmd, ' '));
            }

            return $antwort;
        };

        $selbst = (string) preg_replace('/^www\.|:.*$/', '', (string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));

        $lies(); // Begruessung
        $sende('EHLO ' . $selbst, [250]);

        if ($secure === 'tls') {
            $sende('STARTTLS', [220]);

            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new \RuntimeException('STARTTLS-Verschlüsselung fehlgeschlagen.');
            }

            $sende('EHLO ' . $selbst, [250]);
        }

        if ($user !== '') {
            $sende('AUTH LOGIN', [334]);
            $sende(base64_encode($user), [334]);
            $sende(base64_encode($pass), [235]);
        }

        $sende('MAIL FROM:<' . $fromMail . '>', [250]);
        $sende('RCPT TO:<' . $to . '>', [250, 251]);
        $sende('DATA', [354]);

        $daten = 'From: ' . self::encodeHeader($fromName) . " <$fromMail>\r\n"
            . "To: <$to>\r\n"
            . 'Subject: ' . self::encodeHeader($subject) . "\r\n"
            . 'Date: ' . date('r') . "\r\n"
            . "MIME-Version: 1.0\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n"
            . "\r\n"
            // Punkt-Stuffing laut RFC 5321
            . preg_replace('/^\./m', '..', str_replace(["\r\n", "\r"], "\n", $body) === $body
                ? str_replace("\n", "\r\n", $body)
                : $body);

        fwrite($sock, $daten . "\r\n.\r\n");
        $antwort = $lies();

        if ((int) substr($antwort, 0, 3) !== 250) {
            throw new \RuntimeException(trim($antwort));
        }

        fwrite($sock, "QUIT\r\n");
        fclose($sock);
    }

    /** UTF-8-Header (Betreff, Anzeigename) RFC-2047-kodiert, wenn noetig. */
    private static function encodeHeader(string $wert): string
    {
        return preg_match('/^[\x20-\x7e]*$/', $wert) === 1
            ? $wert
            : '=?UTF-8?B?' . base64_encode($wert) . '?=';
    }
}
