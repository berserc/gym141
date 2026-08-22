<?php

use App\Models\CalendarRepo;

/**
 * Terminliste für Mitglieder: Wettkampf- und Eventkalender, monatlich
 * gruppiert. Wiederholungstermine (z. B. wöchentliches Training) erscheinen
 * als einzelne Vorkommen; je Vorkommen gibt es eine Abstimmung im
 * WhatsApp-Stil: "Komme" / "Komme nicht" mit Zählern und Namen. Erneutes
 * Antippen der eigenen Antwort zieht sie zurück.
 *
 * @var array<string,mixed> $member
 * @var list<array{event:array<string,mixed>,on:string,ends:?string,occurs_on:string}> $eintraege
 * @var array<int,array<string,array<string,list<string>>>> $votes [event][occurs_on][status] = Namen
 * @var array<int,array<string,string>> $my [event][occurs_on] = eigener Status
 * @var bool                 $nurKommende
 * @var array<string,string> $kinds
 */
$monatsname = static function (string $ym): string {
    $namen = [1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
    [$jahr, $monat] = explode('-', $ym);

    return ($namen[(int) $monat] ?? $monat) . ' ' . $jahr;
};

$gruppiert = [];

foreach ($eintraege as $eintrag) {
    $gruppiert[substr($eintrag['on'], 0, 7)][] = $eintrag;
}
?>
<div class="m-card__head">
    <h1>Termine</h1>
    <a class="btn btn--sm btn--ghost btn--on-dark"
       href="<?= e(url('/mitglied/termine', $nurKommende ? ['alle' => '1'] : [])) ?>">
        <?= $nurKommende ? 'auch vergangene zeigen' : 'nur kommende zeigen' ?>
    </a>
</div>

<?php if ($eintraege === []): ?>
    <div class="m-card"><p class="muted-dark">Keine Termine gefunden.</p></div>
<?php endif; ?>

<?php foreach ($gruppiert as $monat => $liste): ?>
    <h2 class="m-month"><?= e($monatsname($monat)) ?></h2>

    <?php foreach ($liste as $eintrag): ?>
        <?php
        $event    = $eintrag['event'];
        $eid      = (int) $event['id'];
        $occ      = $eintrag['occurs_on'];
        $zusagen  = $votes[$eid][$occ]['zusage'] ?? [];
        $absagen  = $votes[$eid][$occ]['absage'] ?? [];
        $meins    = $my[$eid][$occ] ?? '';
        $recurTxt = CalendarRepo::recurLabel($event);
        ?>
        <div class="m-card m-event-card">
            <div class="m-card__head">
                <div>
                    <span class="m-event__date"><?= e(CalendarRepo::rangeLabel($eintrag['on'], $eintrag['ends'])) ?></span>
                    <span class="badge-dark badge-dark--<?= e($event['kind']) ?>"><?= e($kinds[$event['kind']] ?? $event['kind']) ?></span>
                    <?php if ($recurTxt !== ''): ?>
                        <span class="badge-dark" title="Wiederholungstermin">🔁 <?= e($recurTxt) ?></span>
                    <?php endif; ?>
                </div>
            </div>

            <h3><?= e($event['title']) ?></h3>

            <?php if ((string) $event['location'] !== ''): ?>
                <p class="muted-dark">📍 <?= e($event['location']) ?></p>
            <?php endif; ?>

            <?php if (trim((string) $event['description']) !== ''): ?>
                <p style="white-space: pre-line"><?= e($event['description']) ?></p>
            <?php endif; ?>

            <?php if ((int) $event['rsvp'] === 1): ?>
                <div class="poll">
                    <form method="post" action="<?= e(url('/mitglied/termin/' . $eid . '/antwort')) ?>" class="poll__option">
                        <?= csrf_field() ?>
                        <input type="hidden" name="occurs_on" value="<?= e($occ) ?>">
                        <input type="hidden" name="status" value="zusage">
                        <button type="submit"
                                class="poll__btn poll__btn--ja<?= $meins === 'zusage' ? ' is-mine' : '' ?>"
                                title="<?= $meins === 'zusage' ? 'Nochmal tippen zieht deine Antwort zurück' : 'Ich komme' ?>">
                            <span class="poll__label">✅ Komme</span>
                            <span class="poll__count"><?= count($zusagen) ?></span>
                        </button>
                    </form>

                    <form method="post" action="<?= e(url('/mitglied/termin/' . $eid . '/antwort')) ?>" class="poll__option">
                        <?= csrf_field() ?>
                        <input type="hidden" name="occurs_on" value="<?= e($occ) ?>">
                        <input type="hidden" name="status" value="absage">
                        <button type="submit"
                                class="poll__btn poll__btn--nein<?= $meins === 'absage' ? ' is-mine' : '' ?>"
                                title="<?= $meins === 'absage' ? 'Nochmal tippen zieht deine Antwort zurück' : 'Ich komme nicht' ?>">
                            <span class="poll__label">❌ Komme nicht</span>
                            <span class="poll__count"><?= count($absagen) ?></span>
                        </button>
                    </form>

                    <?php if ($zusagen !== [] || $absagen !== []): ?>
                        <details class="poll__voters">
                            <summary><?= count($zusagen) + count($absagen) ?> Antwort(en) ansehen</summary>
                            <?php if ($zusagen !== []): ?>
                                <p><span class="m-ok">✅</span> <?= e(implode(', ', $zusagen)) ?></p>
                            <?php endif; ?>
                            <?php if ($absagen !== []): ?>
                                <p><span class="muted-dark">❌</span> <span class="muted-dark"><?= e(implode(', ', $absagen)) ?></span></p>
                            <?php endif; ?>
                        </details>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endforeach; ?>
