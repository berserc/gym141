<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Ticket141Client;
use App\Core\View;
use RuntimeException;

/**
 * Tickets ueber Ticket141: Verkaufsuebersicht aller Events des Ticketshops
 * (Tickets verkauft, eingelassen, Umsatz) mit Links in Shop und Verwaltung.
 * Reine Leseansicht – verkauft und verwaltet wird in Ticket141 selbst.
 */
final class Ticket141Controller
{
    public function index(): void
    {
        AuthController::requireRole('superuser', 'verwaltung', 'kassier');

        $client     = new Ticket141Client();
        $configured = Ticket141Client::configured();
        $events     = [];
        $error      = '';
        $statsSlug  = trim(query('event'));
        $stats      = null;

        if ($configured) {
            try {
                $events = $client->events();

                // Optional: Verkauf je Kategorie eines Events (aufgeklappt per ?event=slug).
                if ($statsSlug !== '') {
                    $stats = $client->stats($statsSlug);
                }
            } catch (RuntimeException $e) {
                $error = $e->getMessage();
            }
        }

        View::display('admin/tickets/index', [
            'title'      => 'Tickets',
            'configured' => $configured,
            'baseUrl'    => $client->baseUrl(),
            'events'     => $events,
            'error'      => $error,
            'statsSlug'  => $statsSlug,
            'stats'      => $stats,
        ], 'layouts/admin');
    }
}
