<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;
use RuntimeException;

/**
 * Zentraler Zugang zur Claude API (Anthropic) – genutzt von der
 * KI-Formularerkennung (Mitglieder) und dem KI-Bankimport samt Zuordnung.
 *
 * Schluessel, in dieser Reihenfolge:
 *   1. Eigener Schluessel des Vereins: Einstellungen -> "Anthropic API-Schlüssel"
 *      (Setting anthropic_api_key) – Abrechnung direkt beim Verein.
 *   2. Betreiber-Schluessel: 'ai_shared_key' in app/config.php (nur auf
 *      verwalteten/gehosteten Installationen; NIE im Repository). Mit ihm
 *      ruft Gym141 IMMER 'ai_shared_model' (Kostenkontrolle, kalkuliert ist
 *      Haiku) und zaehlt ein Monats-Kontingent ('ai_quota_monthly').
 *
 * Der Schluessel verlaesst den Server nicht; hochgeladene Dateien werden zur
 * Auswertung an die Claude API uebertragen (Hinweise in den Formularen).
 */
final class Ai
{
    public const DEFAULT_MODEL = 'claude-haiku-4-5';

    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';

    // ---------------------------------------------------------- Schluessel --

    /** Eigener Schluessel des Vereins (Einstellungen). */
    public static function ownKey(): string
    {
        return trim(Setting::get('anthropic_api_key'));
    }

    /** Betreiber-Schluessel aus app/config.php (gehostete Installationen). */
    public static function sharedKey(): string
    {
        return trim((string) Config::get('ai_shared_key', ''));
    }

    public static function apiKey(): string
    {
        return self::ownKey() !== '' ? self::ownKey() : self::sharedKey();
    }

    public static function usesSharedKey(): bool
    {
        return self::ownKey() === '' && self::sharedKey() !== '';
    }

    public static function configured(): bool
    {
        return self::apiKey() !== '';
    }

    public static function model(): string
    {
        // Betreiber-Schluessel: das Modell bestimmt der Betreiber.
        if (self::usesSharedKey()) {
            return trim((string) Config::get('ai_shared_model', self::DEFAULT_MODEL)) ?: self::DEFAULT_MODEL;
        }

        $model = trim(Setting::get('ai_model'));

        return preg_match('/^[a-z0-9.\-]{3,60}$/', $model) === 1 ? $model : self::DEFAULT_MODEL;
    }

    // ----------------------------------------------- Kontingent (Betreiber) --

    /** Monatslimit beim Betreiber-Schluessel; null = eigener Schluessel, kein Limit. */
    public static function quotaLimit(): ?int
    {
        return self::usesSharedKey() ? max(0, (int) Config::get('ai_quota_monthly', 200)) : null;
    }

    public static function quotaUsed(): int
    {
        return (int) Setting::get('ai_used_' . gmdate('Y-m'));
    }

    /** null = alles ok, sonst die Fehlermeldung fuer den Benutzer. */
    public static function quotaError(): ?string
    {
        $limit = self::quotaLimit();

        if ($limit !== null && self::quotaUsed() >= $limit) {
            return sprintf(
                'Das inkludierte KI-Kontingent dieses Monats (%d Auswertungen) ist aufgebraucht. '
                . 'Mit eigenem API-Schlüssel (Einstellungen → KI) gibt es kein Limit.',
                $limit
            );
        }

        return null;
    }

    /** Einen Lauf verbuchen (nur beim Betreiber-Schluessel zaehlen). */
    private static function consume(): void
    {
        if (self::usesSharedKey()) {
            Setting::set('ai_used_' . gmdate('Y-m'), (string) (self::quotaUsed() + 1));
        }
    }

    // --------------------------------------------------------------- Aufruf --

    /**
     * Inhaltsblock fuer eine Datei: PDF als document, Bilder als image.
     *
     * @return array<string,mixed>
     */
    public static function fileBlock(string $mime, string $pfad): array
    {
        $quelle = [
            'type'       => 'base64',
            'media_type' => $mime,
            'data'       => base64_encode((string) file_get_contents($pfad)),
        ];

        return $mime === 'application/pdf'
            ? ['type' => 'document', 'source' => $quelle]
            : ['type' => 'image', 'source' => $quelle];
    }

    /**
     * Ruft die Messages API mit festem JSON-Schema und liefert die dekodierte
     * Antwort. Wirft bei jedem Fehler eine RuntimeException mit einer
     * Meldung, die direkt angezeigt werden kann.
     *
     * @param list<array<string,mixed>> $content Inhaltsbloecke (Dateien + Text)
     * @param array<string,mixed>       $schema  JSON-Schema der Antwort
     * @return array<string,mixed>
     */
    public static function request(array $content, string $system, array $schema, int $maxTokens = 4000): array
    {
        if (!self::configured()) {
            throw new RuntimeException('Kein Anthropic API-Schlüssel hinterlegt (Einstellungen → KI).');
        }

        if (($quota = self::quotaError()) !== null) {
            throw new RuntimeException($quota);
        }

        $model = self::model();
        $body  = [
            'model'         => $model,
            'max_tokens'    => $maxTokens,
            'system'        => $system,
            'messages'      => [['role' => 'user', 'content' => $content]],
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
        ];

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . self::apiKey(),
            'anthropic-version: 2023-06-01',
        ];

        // Lehnt ein Sicherheitsfilter die Anfrage ab, uebernimmt serverseitig ein Ersatzmodell.
        if (preg_match('/^claude-(opus-5|fable-5-1|sonnet-5-5)/', $model) === 1) {
            $body['fallbacks'] = 'default';
            $headers[]         = 'anthropic-beta: server-side-fallback-2026-07-01';
        }

        [$status, $antwort] = self::post(self::ENDPOINT, (string) json_encode($body, JSON_UNESCAPED_UNICODE), $headers);

        /** @var array<string,mixed> $json */
        $json = json_decode($antwort, true) ?? [];

        if ($status !== 200) {
            $meldung = (string) ($json['error']['message'] ?? ('HTTP ' . $status));

            if ($status === 401) {
                $meldung = 'API-Schlüssel ungültig – bitte in den Einstellungen prüfen.';
            }

            throw new RuntimeException($meldung);
        }

        if (($json['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('Die KI hat die Auswertung abgelehnt.');
        }

        if (($json['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('Die Antwort der KI war unvollständig (Dokument zu umfangreich?).');
        }

        $text = '';

        foreach ((array) ($json['content'] ?? []) as $teil) {
            if (is_array($teil) && ($teil['type'] ?? '') === 'text') {
                $text = (string) $teil['text'];
                break;
            }
        }

        /** @var array<string,mixed>|null $daten */
        $daten = json_decode($text, true);

        if (!is_array($daten)) {
            throw new RuntimeException('Unerwartete Antwort der KI.');
        }

        self::consume();

        return $daten;
    }

    /** Verbindungstest fuer die Einstellungen: Modellname oder Exception. */
    public static function ping(): string
    {
        [$status, $antwort] = self::post(self::ENDPOINT, (string) json_encode([
            'model'      => self::model(),
            'max_tokens' => 16,
            'messages'   => [['role' => 'user', 'content' => 'Antworte nur mit OK.']],
        ]), [
            'Content-Type: application/json',
            'x-api-key: ' . self::apiKey(),
            'anthropic-version: 2023-06-01',
        ]);

        $json = json_decode($antwort, true) ?? [];

        if ($status !== 200) {
            throw new RuntimeException((string) ($json['error']['message'] ?? ('HTTP ' . $status)));
        }

        return (string) ($json['model'] ?? self::model());
    }

    // --------------------------------------------------------------- Intern --

    /**
     * POST mit curl, sonst PHP-Streams.
     *
     * @param list<string> $headers
     * @return array{0:int,1:string} [HTTP-Status, Antwort-Body]
     */
    private static function post(string $url, string $body, array $headers): array
    {
        $timeout = max(30, (int) Config::get('ai_timeout', 180));

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            if ((string) Config::get('ca_bundle', '') !== '') {
                curl_setopt($ch, CURLOPT_CAINFO, (string) Config::get('ca_bundle'));
            }

            $antwort = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            if ($antwort === false) {
                throw new RuntimeException('Verbindung zur Claude API fehlgeschlagen (' . curl_error($ch) . ').');
            }

            return [$status, (string) $antwort];
        }

        $kontext = stream_context_create(['http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers),
            'content'       => $body,
            'timeout'       => $timeout,
            'ignore_errors' => true,
        ]]);

        $antwort = @file_get_contents($url, false, $kontext);
        $status  = 0;

        foreach ($http_response_header ?? [] as $zeile) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        if ($antwort === false) {
            throw new RuntimeException('Verbindung zur Claude API fehlgeschlagen.');
        }

        return [$status, (string) $antwort];
    }
}
