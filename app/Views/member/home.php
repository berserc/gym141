<?php

use App\Models\CalendarRepo;
use App\Models\SportRepo;

/**
 * Mitglieder-Übersicht: eigene Daten, Beitragsstatus, nächste Termine.
 *
 * @var array<string,mixed>       $member
 * @var list<array<string,mixed>> $record
 * @var list<array<string,mixed>> $openFees
 * @var list<array<string,mixed>> $events
 */
?>
<h1>Hallo, <?= e($member['first_name']) ?>!</h1>

<div class="m-grid">
    <div class="m-card">
        <h2>Meine Daten</h2>
        <dl class="m-datalist">
            <dt>Name</dt><dd><?= e($member['first_name'] . ' ' . $member['last_name']) ?></dd>
            <?php if ((string) $member['member_no'] !== ''): ?>
                <dt>Mitgliedsnummer</dt><dd><?= e($member['member_no']) ?></dd>
            <?php endif; ?>
            <?php if (($member['birthdate'] ?? null) !== null && $member['birthdate'] !== ''): ?>
                <dt>Geburtsdatum</dt><dd><?= e(format_date((string) $member['birthdate'])) ?></dd>
            <?php endif; ?>
            <?php if ((string) $member['street'] !== ''): ?>
                <dt>Adresse</dt>
                <dd><?= e($member['street']) ?><br><?= e(trim($member['zip'] . ' ' . $member['city'])) ?></dd>
            <?php endif; ?>
            <dt>E-Mail</dt><dd><?= e($member['email']) ?></dd>
            <?php if ((string) $member['phone'] !== ''): ?>
                <dt>Telefon</dt><dd><?= e($member['phone']) ?></dd>
            <?php endif; ?>
            <?php if (($member['joined_on'] ?? null) !== null && $member['joined_on'] !== ''): ?>
                <dt>Mitglied seit</dt><dd><?= e(format_date((string) $member['joined_on'])) ?></dd>
            <?php endif; ?>
        </dl>
        <?php $vereinsMail = \App\Models\Setting::get('club_email'); ?>
        <?php if ($vereinsMail !== ''): ?>
            <p class="muted-dark">
                Daten nicht aktuell? Bitte beim Verein melden:
                <a href="mailto:<?= e($vereinsMail) ?>"><?= e($vereinsMail) ?></a>
            </p>
        <?php endif; ?>
    </div>

    <div class="m-card">
        <h2>Meine Beiträge</h2>
        <?php if ($openFees === []): ?>
            <p class="m-ok">✔ Alles bezahlt – danke!</p>
        <?php else: ?>
            <p class="m-warn">
                <?= count($openFees) ?> offene(r) Beitrag/Beiträge –
                gesamt <strong><?= e(format_money(array_sum(array_map(static fn (array $f): float => (float) $f['amount'], $openFees)))) ?></strong>
            </p>
            <ul class="m-list">
                <?php foreach ($openFees as $fee): ?>
                    <li>
                        <?= e($fee['period_label']) ?> – <?= e(format_money($fee['amount'])) ?>
                        <span class="muted-dark">(fällig <?= e(format_date((string) $fee['due_date'])) ?>)</span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($record !== []): ?>
            <h2 style="margin-top:1.2rem">Meine Bilanz</h2>
            <ul class="m-list">
                <?php foreach ($record as $r): ?>
                    <li>
                        <strong><?= e($r['sport']) ?>:</strong>
                        <?= e(SportRepo::recordLabel($r['siege'], $r['niederlagen'], $r['unentschieden'])) ?>
                        <?= $r['ko'] > 0 ? '(' . (int) $r['ko'] . ' KO)' : '' ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<div class="m-card">
    <div class="m-card__head">
        <h2>Nächste Termine</h2>
        <a class="btn btn--sm btn--ghost btn--on-dark" href="<?= e(url('/mitglied/termine')) ?>">Alle Termine</a>
    </div>

    <?php if ($events === []): ?>
        <p class="muted-dark">Derzeit stehen keine Termine an.</p>
    <?php else: ?>
        <ul class="m-events">
            <?php foreach ($events as $event): ?>
                <li>
                    <span class="m-event__date"><?= e(CalendarRepo::rangeLabel((string) $event['starts_on'], $event['ends_on'] === null ? null : (string) $event['ends_on'])) ?></span>
                    <span class="badge-dark badge-dark--<?= e($event['kind']) ?>"><?= e(CalendarRepo::KINDS[$event['kind']] ?? $event['kind']) ?></span>
                    <strong><?= e($event['title']) ?></strong>
                    <?= (string) $event['location'] !== '' ? '· ' . e($event['location']) : '' ?>
                    <?php if ($event['my_status'] === 'zusage'): ?>
                        <span class="m-ok">✔ angemeldet</span>
                    <?php elseif ($event['my_status'] === 'absage'): ?>
                        <span class="muted-dark">✖ abgemeldet</span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>
