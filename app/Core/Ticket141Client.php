<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;
use RuntimeException;

/**
 * Anbindung an Ticket141 (Ticketshop) ueber dessen Plattform-API
 * (/api/v1/*, Bearer-Schluessel "tk_…", Scope "read" genuegt).
 *
 * Der Schluessel wird in Ticket141 unter "Verwaltung -> API & Webhooks"
 * erzeugt und hier in den Einstellungen hinterlegt (ticket141_url +
 * ticket141_api_key). Damit zeigt Gym141 die Verkaufszahlen der Events
 * (Admin-Seite "Tickets") und bettet den Shop per Inhaltsblock "Tickets"
 * in die Website ein. Fuer das Einbetten reicht die Adresse allein.
 */
final class Ticket141Client
{
    private string $base;

    private string $key;

    public function __construct(?string $url = null, ?string $key = null)
    {
        $this->base = self::normalize($url ?? Setting::get('ticket141_url'));
        $this->key  = trim($key ?? Setting::get('ticket141_api_key'));
    }

    /** Adresse UND Schluessel vorhanden (fuer die API-Aufrufe). */
    public static function configured(): bool
    {
        return trim(Setting::get('ticket141_url')) !== '' && trim(Setting::get('ticket141_api_key')) !== '';
    }

    /** Nur die Adresse vorhanden – reicht fuer Shop-Link und Embed-Widget. */
    public static function urlConfigured(): bool
    {
        return trim(Setting::get('ticket141_url')) !== '';
    }

    /** "tickets.example.org/" -> "https://tickets.example.org" (localhost: http). */
    public static function normalize(string $url): string
    {
        $url = rtrim(trim($url), '/');

        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            $host  = strtolower((string) strtok($url, '/:'));
            $lokal = in_array($host, ['localhost', '127.0.0.1'], true)
                || str_ends_with($host, '.local') || str_ends_with($host, '.test');
            $url   = ($lokal ? 'http://' : 'https://') . $url;
        }

        if (str_ends_with($url, '/admin')) {
            $url = substr($url, 0, -6);
        }

        return $url;
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** Adresse des Embed-Skripts (Shop-Widget). */
    public function embedScriptUrl(): string
    {
        return $this->base . '/embed.js';
    }

    /** Oeffentlicher Shop-Link eines Events, optional mit Zugangscode. */
    public function shopUrl(string $slug, string $code = ''): string
    {
        return $this->base . '/t/' . rawurlencode($slug) . ($code !== '' ? '?code=' . rawurlencode($code) : '');
    }

    /** Verwaltungsseite eines Events in Ticket141. */
    public function adminEventUrl(int $eventId): string
    {
        return $this->base . '/admin/events/' . $eventId;
    }

    /** @return array<string,mixed> Verbindungstest: {ok, service, version, key:{name, scope, event}} */
    public function ping(): array
    {
        return $this->request('GET', '/api/v1/ping');
    }

    /**
     * Alle Events (auch Entwuerfe) mit Verkaufszahlen: ticket_count,
     * checkin_count, order_count, revenue_cents, status, shop_url, embed_url.
     *
     * @return list<array<string,mixed>>
     */
    public function events(): array
    {
        return array_values((array) ($this->request('GET', '/api/v1/events')['events'] ?? []));
    }

    /** @return array<string,mixed> {event, categories, sale} */
    public function event(string $slug): array
    {
        return $this->request('GET', '/api/v1/event/' . rawurlencode($slug));
    }

    /** @return array<string,mixed> {event, stats, categories:[{id,name,sold,reserved,checked_in,quota,revenue_cents}]} */
    public function stats(string $slug): array
    {
        return $this->request('GET', '/api/v1/event/' . rawurlencode($slug) . '/stats');
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->base === '' || $this->key === '') {
            throw new RuntimeException('Ticket141 ist nicht eingerichtet (Einstellungen: Adresse + API-Schlüssel).');
        }

        $headers = ['Accept: application/json', 'Authorization: Bearer ' . $this->key];
        $payload = null;

        if ($body !== null) {
            $payload   = (string) json_encode($body, JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($this->base . $path);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_CONNECTTIMEOUT => 8,
            ]);

            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }

            $raw = curl_exec($ch);

            if ($raw === false) {
                throw new RuntimeException('Ticket141 nicht erreichbar: ' . curl_error($ch));
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '',
                'timeout' => 20, 'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($this->base . $path, false, $ctx);

            if ($raw === false) {
                throw new RuntimeException('Ticket141 nicht erreichbar.');
            }

            $status = 0;

            foreach ($http_response_header ?? [] as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        $data = json_decode((string) $raw, true);

        if (!is_array($data)) {
            throw new RuntimeException('Ticket141 hat keine gültige Antwort geliefert (HTTP ' . $status . '). Stimmt die Adresse?');
        }

        if ($status >= 400) {
            throw new RuntimeException((string) ($data['error'] ?? ('Ticket141-Fehler (HTTP ' . $status . ')')));
        }

        return $data;
    }
}
