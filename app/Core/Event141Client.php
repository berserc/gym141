<?php

declare(strict_types=1);

namespace App\Core;

use App\Models\Setting;
use RuntimeException;

/**
 * Anbindung an Event141 (Event- und Turnierplattform) ueber dessen Gym-API
 * (/api/v1/gym/*, Bearer-Schluessel "ek_…").
 *
 * Der Schluessel wird in Event141 im Gym-Bereich unter "Gym141" erzeugt und
 * hier in den Einstellungen hinterlegt (event141_url + event141_api_key).
 * Damit meldet der Verein seine Mitglieder direkt aus Gym141 zu Wettkaempfen
 * an und holt die Ergebnisse in die "Erfolge" zurueck.
 */
final class Event141Client
{
    private string $base;

    private string $key;

    public function __construct(?string $url = null, ?string $key = null)
    {
        $this->base = rtrim(trim($url ?? Setting::get('event141_url')), '/');
        $this->key  = trim($key ?? Setting::get('event141_api_key'));

        if ($this->base !== '' && !preg_match('#^https?://#i', $this->base)) {
            $this->base = 'https://' . $this->base;
        }
    }

    public static function configured(): bool
    {
        return trim(Setting::get('event141_url')) !== '' && trim(Setting::get('event141_api_key')) !== '';
    }

    public function baseUrl(): string
    {
        return $this->base;
    }

    /** @return array<string,mixed> eigenes Gym in Event141 (Verbindungstest) */
    public function gym(): array
    {
        return $this->request('GET', '/api/v1/gym');
    }

    /** @return list<array<string,mixed>> Events mit offener Anmeldung (Kategorien + eigene Anmeldungen) */
    public function openEvents(): array
    {
        return array_values((array) ($this->request('GET', '/api/v1/gym/events')['events'] ?? []));
    }

    /**
     * Sportler abgleichen (Upsert ueber external_ref).
     *
     * @param list<array<string,mixed>> $athletes
     * @return array<string,mixed>
     */
    public function upsertAthletes(array $athletes): array
    {
        return $this->request('POST', '/api/v1/gym/athletes', ['athletes' => $athletes]);
    }

    /**
     * @param list<array<string,mixed>> $entries [{external_ref, category_id, note}]
     * @return array<string,mixed>
     */
    public function enter(string $eventSlug, array $entries): array
    {
        return $this->request('POST', '/api/v1/gym/event/' . rawurlencode($eventSlug) . '/entries', ['entries' => $entries]);
    }

    public function withdraw(int $entryId): void
    {
        $this->request('POST', '/api/v1/gym/entry/' . $entryId . '/withdraw', []);
    }

    /** @return list<array<string,mixed>> Kaempfe der eigenen Sportler */
    public function results(): array
    {
        return array_values((array) ($this->request('GET', '/api/v1/gym/results')['results'] ?? []));
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        if ($this->base === '' || $this->key === '') {
            throw new RuntimeException('Event141 ist nicht eingerichtet (Einstellungen: Adresse + API-Schlüssel).');
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
                throw new RuntimeException('Event141 nicht erreichbar: ' . curl_error($ch));
            }

            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => $method, 'header' => implode("\r\n", $headers), 'content' => $payload ?? '',
                'timeout' => 20, 'ignore_errors' => true,
            ]]);
            $raw = @file_get_contents($this->base . $path, false, $ctx);

            if ($raw === false) {
                throw new RuntimeException('Event141 nicht erreichbar.');
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
            throw new RuntimeException('Event141 hat keine gültige Antwort geliefert (HTTP ' . $status . '). Stimmt die Adresse?');
        }

        if ($status >= 400) {
            throw new RuntimeException((string) ($data['error'] ?? ('Event141-Fehler (HTTP ' . $status . ')')));
        }

        return $data;
    }
}
