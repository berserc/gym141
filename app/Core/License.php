<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;

/**
 * Verbindung zum DevWorld-Kundenkonto (account.devworld-llc.com).
 *
 * Gym141 ist Open Source und bis FREE_MEMBER_LIMIT aktive Mitglieder gratis.
 * Ein Lizenzschlüssel (Produkt "gym141-pro") hebt das Limit auf; gebuchte
 * Zusatzmodule kommen als Feature-Codes in der Prüfantwort mit.
 *
 * Die Prüfung läuft gegen POST {license_api}/api/v1/licenses/validate und wird
 * gecacht (Setting license_state). Ist der Lizenzserver vorübergehend nicht
 * erreichbar, bleibt eine zuletzt gültige Lizenz GRACE_DAYS Tage lang gültig.
 */
final class License
{
    public const PRODUCT_CODE = 'gym141-pro';

    /** Aktive Mitglieder, die ohne Lizenz möglich sind. */
    public const FREE_MEMBER_LIMIT = 25;

    /** Karenz in Tagen, wenn der Lizenzserver nicht erreichbar ist. */
    private const GRACE_DAYS = 14;

    /** Frühestens alle X Stunden automatisch neu prüfen. */
    private const CHECK_HOURS = 24;

    // ------------------------------------------------------------- Zustand --

    /** @return array<string,mixed> Gecachter Prüfstand (leeres Array = nie geprüft). */
    public static function state(): array
    {
        $raw = Setting::get('license_state');

        /** @var array<string,mixed> */
        return $raw === '' ? [] : ((array) json_decode($raw, true));
    }

    public static function key(): string
    {
        $key = trim(Setting::get('devworld_license_key'));

        // Alternativ kann der Schlüssel fest in app/config.php hinterlegt
        // werden ('devworld_license_key') – praktisch für verwaltete
        // Installationen, deren Konfiguration versioniert ausgerollt wird.
        if ($key === '') {
            $key = trim((string) Config::get('devworld_license_key', ''));
        }

        return $key;
    }

    /** Basis-URL des Lizenzservers (ohne Slash am Ende). */
    public static function apiBase(): string
    {
        return rtrim((string) Config::get('license_api', 'https://api.devworld-llc.com'), '/');
    }

    /**
     * Stabile Kennung dieser Installation für die Geräteaktivierung –
     * ein Hash, es verlassen keine Klardaten den Server.
     */
    public static function deviceId(): string
    {
        $secret = Setting::get('install_secret');

        if ($secret === '') {
            $secret = bin2hex(random_bytes(16));
            Setting::set('install_secret', $secret);
        }

        return hash('sha256', $secret . '|' . (string) Config::get('db_path', ''));
    }

    // ------------------------------------------------------------ Prüfung --

    /** Prüft bei Bedarf (Cache abgelaufen) neu; liefert den aktuellen Stand. */
    public static function check(bool $force = false): array
    {
        $state = self::state();

        if (self::key() === '') {
            return $state;
        }

        $alter = time() - (int) ($state['checked_at'] ?? 0);

        if (!$force && $alter < self::CHECK_HOURS * 3600) {
            return $state;
        }

        return self::refresh();
    }

    /** Fragt den Lizenzserver an und aktualisiert den gecachten Stand. */
    public static function refresh(): array
    {
        $key = self::key();

        if ($key === '') {
            Setting::set('license_state', '');

            return [];
        }

        $host = (string) ($_SERVER['HTTP_HOST'] ?? php_uname('n'));

        $antwort = self::request('/api/v1/licenses/validate', [
            'licenseKey'  => $key,
            'deviceId'    => self::deviceId(),
            'productCode' => self::PRODUCT_CODE,
            'deviceName'  => 'Gym141 @ ' . $host,
        ]);

        $state = self::state();
        $jetzt = time();

        if ($antwort === null) {
            // Server nicht erreichbar: Karenz ab letzter erfolgreicher Prüfung.
            $state['checked_at'] = $jetzt;
            $state['reason']     = 'unreachable';

            $letzterErfolg = (int) ($state['ok_at'] ?? 0);
            if ($letzterErfolg < $jetzt - self::GRACE_DAYS * 86400) {
                $state['valid'] = false;
            }
        } else {
            $state = [
                'valid'      => (bool) ($antwort['isValid'] ?? false),
                'reason'     => $antwort['reason'] ?? null,
                'features'   => array_values((array) ($antwort['features'] ?? [])),
                'expires_at' => $antwort['expiresAtUtc'] ?? null,
                'checked_at' => $jetzt,
                'ok_at'      => $jetzt,
            ];
        }

        Setting::set('license_state', (string) json_encode($state));

        return $state;
    }

    // ------------------------------------------------------------- Abfragen --

    /** Gültige Pro-Lizenz vorhanden (inkl. Offline-Karenz)? */
    public static function isPro(): bool
    {
        $state = self::check();

        return (bool) ($state['valid'] ?? false)
            && in_array(self::PRODUCT_CODE, (array) ($state['features'] ?? [self::PRODUCT_CODE]), true);
    }

    /** Ist ein Zusatzmodul (Produktcode, z. B. "gym141-support") gebucht? */
    public static function hasModule(string $code): bool
    {
        $state = self::check();

        return (bool) ($state['valid'] ?? false)
            && in_array($code, (array) ($state['features'] ?? []), true);
    }

    public static function memberLimit(): int
    {
        if (self::isPro()) {
            return PHP_INT_MAX;
        }

        return max(1, (int) Config::get('free_member_limit', self::FREE_MEMBER_LIMIT));
    }

    /** Aktive (nicht archivierte, nicht gelöschte) Mitglieder. */
    public static function activeMemberCount(): int
    {
        return (int) Database::value(
            "SELECT COUNT(*) FROM members
              WHERE status = 'aktiv' AND deleted_at IS NULL AND archived_at IS NULL"
        );
    }

    /**
     * Dürfen $neue weitere aktive Mitglieder angelegt werden?
     * Liefert null (ja) oder die Fehlermeldung für den Benutzer.
     */
    public static function memberLimitError(int $neue = 1): ?string
    {
        $limit = self::memberLimit();

        if (self::activeMemberCount() + $neue <= $limit) {
            return null;
        }

        return sprintf(
            'Das Gratis-Limit von %d aktiven Mitgliedern ist erreicht. '
            . 'Für unbegrenzte Mitglieder gibt es Gym141 Pro auf account.devworld-llc.com – '
            . 'den Lizenzschlüssel dann unter Einstellungen eintragen. '
            . '(Archivieren ehemaliger Mitglieder schafft ebenfalls Platz.)',
            $limit
        );
    }

    // --------------------------------------------------------------- Intern --

    /**
     * POST mit JSON-Antwort; null bei Netz-/Serverfehler.
     *
     * @param array<string,mixed> $daten
     * @return array<string,mixed>|null
     */
    private static function request(string $pfad, array $daten): ?array
    {
        $url  = self::apiBase() . $pfad;
        $body = (string) json_encode($daten);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT        => 15,
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            ]);

            $antwort = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $kontext = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => 'Content-Type: application/json',
                'content'       => $body,
                'timeout'       => 15,
                'ignore_errors' => true,
            ]]);

            $antwort = @file_get_contents($url, false, $kontext);
            $status  = 0;

            foreach ($http_response_header ?? [] as $zeile) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                    $status = (int) $m[1];
                }
            }
        }

        if ($antwort === false || $status !== 200) {
            return null;
        }

        $json = json_decode((string) $antwort, true);

        return is_array($json) ? $json : null;
    }
}
