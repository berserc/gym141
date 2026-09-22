<?php

/**
 * Tickets über Ticket141: Verkaufsübersicht der Events.
 *
 * @var bool                      $configured
 * @var string                    $baseUrl
 * @var list<array<string,mixed>> $events
 * @var string                    $error
 * @var string                    $statsSlug
 * @var array<string,mixed>|null  $stats
 */
$statusLabel = [
    'entwurf'     => 'Entwurf',
    'verkauf'     => 'Im Verkauf',
    'pausiert'    => 'Verkauf pausiert',
    'ausverkauft' => 'Ausverkauft',
    'beendet'     => 'Beendet',
    'abgesagt'    => 'Abgesagt',
];
$statusPill = static fn (string $s): string => match ($s) {
    'verkauf'     => 'pill--aktiv',
    'ausverkauft' => 'pill--offen',
    default       => 'pill--muted',
};
$termin = static function (?string $iso): string {
    if ($iso === null || trim($iso) === '') {
        return '';
    }
    $ts = strtotime($iso);

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
};
$summeTickets = array_sum(array_map(static fn (array $e): int => (int) ($e['ticket_count'] ?? 0), $events));
$summeUmsatz  = array_sum(array_map(static fn (array $e): int => (int) ($e['revenue_cents'] ?? 0), $events));
?>
<div class="page-head">
    <div>
        <h1>Tickets</h1>
        <p class="page-head__sub">
            Ticketverkauf über Ticket141
            <?php if ($configured && $baseUrl !== ''): ?>
                · <a href="<?= e($baseUrl . '/admin') ?>" target="_blank" rel="noopener"><?= e(preg_replace('#^https?://#', '', $baseUrl)) ?> ↗</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if (!$configured): ?>
    <div class="card">
        <h2>Ticket141 verbinden</h2>
        <ol class="steps">
            <li>In Ticket141 unter <strong>Verwaltung → API &amp; Webhooks</strong> einen Schlüssel mit Bereich <strong>read</strong> erzeugen (Name z. B. „Gym141“).</li>
            <li>Hier unter <a href="<?= e(url('/admin/einstellungen')) ?>">Einstellungen</a> die Ticket141-Adresse und den Schlüssel <code>tk_…</code> eintragen und „Speichern + Verbindung prüfen“ drücken.</li>
            <li>Danach erscheinen hier alle Events mit Verkaufszahlen. Den Shop bringt der Inhaltsblock <strong>„Ticketshop (Ticket141)“</strong> auf jede Seite der Website.</li>
        </ol>
    </div>
<?php elseif ($error !== ''): ?>
    <div class="notice notice--danger"><strong>Ticket141 antwortet nicht:</strong> <?= e($error) ?></div>
<?php else: ?>

    <div class="card">
        <div class="card__head">
            <h2>Events</h2>
            <?php if ($events !== []): ?>
                <span class="muted"><?= (int) $summeTickets ?> Tickets · <?= e(format_money($summeUmsatz / 100)) ?></span>
            <?php endif; ?>
        </div>
        <div class="table-scroll">
            <table class="table table--compact">
                <thead>
                <tr>
                    <th>Event</th>
                    <th>Termin</th>
                    <th>Status</th>
                    <th class="num">Verkauft</th>
                    <th class="num">Eingelassen</th>
                    <th class="num">Umsatz</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($events as $ev): ?>
                    <?php
                    $slug   = (string) ($ev['slug'] ?? '');
                    $status = (string) ($ev['status'] ?? '');
                    $offen  = $statsSlug === $slug;
                    ?>
                    <tr>
                        <td class="strong">
                            <?= e($ev['name'] ?? '') ?>
                            <?php if (empty($ev['published'])): ?><small class="muted">(nicht veröffentlicht)</small><?php endif; ?>
                            <?php if (($ev['venue']['city'] ?? '') !== ''): ?><br><small class="muted"><?= e(trim(($ev['venue']['name'] ?? '') . ', ' . $ev['venue']['city'], ', ')) ?></small><?php endif; ?>
                        </td>
                        <td class="mono"><?= e($termin($ev['starts_at'] ?? null)) ?></td>
                        <td><span class="pill <?= $statusPill($status) ?>"><?= e($statusLabel[$status] ?? $status) ?></span></td>
                        <td class="num"><?= (int) ($ev['ticket_count'] ?? 0) ?></td>
                        <td class="num"><?= (int) ($ev['checkin_count'] ?? 0) ?></td>
                        <td class="num"><?= e(format_money(((int) ($ev['revenue_cents'] ?? 0)) / 100)) ?></td>
                        <td class="row-actions">
                            <a href="<?= e(url('/admin/tickets') . ($offen ? '' : '?event=' . rawurlencode($slug))) ?>#stats"><?= $offen ? 'Kategorien ausblenden' : 'Kategorien' ?></a>
                            <?php if (($ev['shop_url'] ?? '') !== ''): ?>
                                · <a href="<?= e($ev['shop_url']) ?>" target="_blank" rel="noopener">Shop ↗</a>
                            <?php endif; ?>
                            · <a href="<?= e($baseUrl . '/admin/events/' . (int) ($ev['id'] ?? 0)) ?>" target="_blank" rel="noopener">Verwaltung ↗</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($events === []): ?><tr><td colspan="7" class="empty">Noch keine Events in Ticket141.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($stats !== null): ?>
        <?php
        $kat    = (array) ($stats['categories'] ?? []);
        $kenn   = (array) ($stats['stats'] ?? []);
        $evName = '';
        foreach ($events as $ev) {
            if (($ev['slug'] ?? '') === $statsSlug) {
                $evName = (string) ($ev['name'] ?? '');
            }
        }
        ?>
        <div class="card" id="stats">
            <div class="card__head">
                <h2>Verkauf je Kategorie – <?= e($evName !== '' ? $evName : $statsSlug) ?></h2>
            </div>
            <div class="table-scroll">
                <table class="table table--compact">
                    <thead><tr><th>Kategorie</th><th class="num">Verkauft</th><th class="num">Reserviert</th><th class="num">Eingelassen</th><th class="num">Kontingent</th><th class="num">Umsatz</th></tr></thead>
                    <tbody>
                    <?php foreach ($kat as $c): ?>
                        <tr>
                            <td class="strong"><?= e($c['name'] ?? '') ?></td>
                            <td class="num"><?= (int) ($c['sold'] ?? 0) ?></td>
                            <td class="num"><?= (int) ($c['reserved'] ?? 0) ?></td>
                            <td class="num"><?= (int) ($c['checked_in'] ?? 0) ?></td>
                            <td class="num"><?= (int) ($c['quota'] ?? 0) > 0 ? (int) $c['quota'] : '∞' ?></td>
                            <td class="num"><?= e(format_money(((int) ($c['revenue_cents'] ?? 0)) / 100)) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if ($kat === []): ?><tr><td colspan="6" class="empty">Keine Kategorien.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php
            $kennLabel = [
                'tickets' => 'Tickets', 'checked_in' => 'eingelassen', 'orders_paid' => 'bezahlte Bestellungen',
                'orders_open' => 'offene Bestellungen', 'gross_cents' => 'Umsatz brutto', 'refunded_cents' => 'erstattet',
                'discount_cents' => 'Rabatte', 'fee_cents' => 'Gebühren', 'stripe_fee_cents' => 'Stripe-Gebühren',
            ];
            ?>
            <?php if ($kenn !== []): ?>
                <p class="muted">
                    <?php foreach ($kenn as $k => $v): ?>
                        <?php if (is_scalar($v)): ?>
                            <?= e($kennLabel[(string) $k] ?? (string) $k) ?>: <strong><?= e(str_ends_with((string) $k, '_cents') ? format_money(((int) $v) / 100) : (string) $v) ?></strong> &nbsp;
                        <?php endif; ?>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <div class="card">
        <h2>Tickets auf der Website verkaufen</h2>
        <p class="muted">
            Unter <a href="<?= e(url('/admin/seiten')) ?>">Seiten</a> → Inhalt einen Block
            <strong>„Ticketshop (Ticket141)“</strong> hinzufügen und den Event-Kürzel (Slug)
            eintragen – der Shop erscheint dann direkt auf der Seite (Widget) oder als
            Button „Tickets kaufen“.
        </p>
    </div>
<?php endif; ?>
