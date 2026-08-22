<?php

use App\Core\Auth;
use App\Models\FeeRepo;

/**
 * Offene Beiträge: Erinnerungsliste mit Abhaken und Zahlungserfassung.
 *
 * @var array<string,mixed>       $filters
 * @var list<array<string,mixed>> $entries
 * @var array{count:int,sum:float,members:int} $stats
 * @var list<array<string,mixed>> $plans
 * @var list<array<string,mixed>> $methods
 * @var list<array<string,mixed>> $sections
 */
$returnTo = url('/admin/beitraege') . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '');
$showAll  = empty($filters['only_due']);
$heute    = date('Y-m-d');
?>
<div class="page-head">
    <div>
        <h1>Beiträge</h1>
        <p class="page-head__sub">
            <?php if ((int) $stats['count'] > 0): ?>
                <strong class="is-minus"><?= (int) $stats['count'] ?> fällige offene Beiträge</strong>
                von <?= (int) $stats['members'] ?> Mitglied(ern),
                gesamt <strong class="is-minus"><?= e(format_money($stats['sum'])) ?></strong>
            <?php else: ?>
                <strong class="is-plus">Keine fälligen offenen Beiträge.</strong>
            <?php endif; ?>
        </p>
    </div>

    <div class="page-head__actions">
        <?php if (Auth::is('superuser', 'kassier')): ?>
            <a class="btn" href="<?= e(url('/admin/beitragsarten')) ?>">Beitragsarten</a>
            <form method="post" action="<?= e(url('/admin/beitraege/erinnerung')) ?>" class="inline"
                  data-confirm="Erinnerung mit allen offenen Beiträgen per E-Mail versenden?">
                <?= csrf_field() ?>
                <button class="btn" type="submit">Erinnerung senden</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<form class="filters" method="get" action="<?= e(url('/admin/beitraege')) ?>">
    <div class="filters__row">
        <div class="field field--grow">
            <label for="f-q">Suche</label>
            <input id="f-q" type="search" name="q" value="<?= e($filters['q']) ?>"
                   placeholder="Name oder Mitgliedsnummer …">
        </div>

        <div class="field">
            <label for="f-plan">Beitragsart</label>
            <select id="f-plan" name="plan_id">
                <option value="">alle</option>
                <?php foreach ($plans as $plan): ?>
                    <option value="<?= (int) $plan['id'] ?>"
                        <?= (string) $filters['plan_id'] === (string) $plan['id'] ? 'selected' : '' ?>>
                        <?= e($plan['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="field">
            <label for="f-section">Sektion</label>
            <select id="f-section" name="section_id">
                <option value="">alle</option>
                <?php foreach ($sections as $section): ?>
                    <option value="<?= (int) $section['id'] ?>"
                        <?= (string) $filters['section_id'] === (string) $section['id'] ? 'selected' : '' ?>>
                        <?= e($section['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <label class="check">
            <input type="checkbox" name="alle" value="1" <?= $showAll ? 'checked' : '' ?>>
            auch künftige Perioden zeigen
        </label>

        <button class="btn btn--primary" type="submit">Filtern</button>
        <a class="btn btn--ghost" href="<?= e(url('/admin/beitraege')) ?>">Zurücksetzen</a>
    </div>
</form>

<form method="post" action="<?= e(url('/admin/beitraege/bezahlt')) ?>" data-confirm-bulk>
    <?= csrf_field() ?>
    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">

    <div class="card">
        <div class="card__head">
            <h2><?= count($entries) ?> offene Beitragszeile(n)</h2>
            <p class="muted"><?= $showAll ? 'inklusive noch nicht fälliger Perioden' : 'fällig bis heute' ?></p>
        </div>

        <div class="table-scroll">
            <table class="table">
                <thead>
                <tr>
                    <th class="col-check"><input type="checkbox" data-check-all aria-label="Alle auswählen"></th>
                    <th>Mitglied</th>
                    <th>Periode</th>
                    <th>Beitragsart</th>
                    <th>Fällig am</th>
                    <th class="num">Betrag</th>
                    <?php if (Auth::canManageFees()): ?>
                        <th>Zahlung erfassen</th>
                    <?php endif; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($entries as $entry): ?>
                    <?php $ueberfaellig = (string) $entry['due_date'] < $heute; ?>
                    <tr<?= $ueberfaellig ? ' class="is-flagged"' : '' ?>>
                        <td class="col-check">
                            <input type="checkbox" name="ids[]" value="<?= (int) $entry['id'] ?>"
                                   aria-label="<?= e($entry['last_name'] . ' ' . $entry['first_name'] . ' ' . $entry['period_label']) ?> auswählen">
                        </td>
                        <td>
                            <a class="strong" href="<?= e(url('/admin/mitglieder/' . $entry['member_id'])) ?>">
                                <?= e($entry['last_name']) ?>, <?= e($entry['first_name']) ?>
                            </a>
                            <?php if ((string) $entry['member_no'] !== ''): ?>
                                <small class="muted">Nr. <?= e($entry['member_no']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e($entry['period_label']) ?></td>
                        <td>
                            <?= e($entry['plan_name'] ?? '–') ?>
                            <?php if (($entry['plan_interval'] ?? '') !== ''): ?>
                                <small class="muted"><?= e(FeeRepo::intervalLabel((string) $entry['plan_interval'])) ?></small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= e(format_date((string) $entry['due_date'])) ?>
                            <?php if ($ueberfaellig): ?>
                                <span class="badge badge--danger">überfällig</span>
                            <?php endif; ?>
                        </td>
                        <td class="num"><?= e(format_money($entry['amount'])) ?></td>
                        <?php if (Auth::canManageFees()): ?>
                            <td>
                                <div class="fee-quick">
                                    <input type="number" step="0.01" min="0" form="pay-<?= (int) $entry['id'] ?>"
                                           name="paid_amount" value="<?= e(number_format((float) $entry['amount'], 2, '.', '')) ?>"
                                           aria-label="bezahlter Betrag" class="input--money">
                                    <input type="date" form="pay-<?= (int) $entry['id'] ?>" name="paid_on"
                                           value="<?= e($heute) ?>" aria-label="bezahlt am">
                                    <select form="pay-<?= (int) $entry['id'] ?>" name="payment_method_id"
                                            aria-label="Zahlungsart">
                                        <?php foreach ($methods as $method): ?>
                                            <option value="<?= (int) $method['id'] ?>" <?= $method['kind'] === 'bar' ? 'selected' : '' ?>>
                                                <?= e($method['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button class="btn btn--sm" type="submit" form="pay-<?= (int) $entry['id'] ?>">
                                        bezahlt ✓
                                    </button>
                                </div>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>

                <?php if ($entries === []): ?>
                    <tr>
                        <td colspan="7" class="empty">
                            Keine offenen Beiträge – alles bezahlt.
                        </td>
                    </tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($entries !== [] && Auth::canManageFees()): ?>
            <div class="bulkbar">
                <label for="bulk-paid-on">Auswahl als bezahlt markieren – Zahldatum:</label>
                <input id="bulk-paid-on" type="date" name="paid_on" value="<?= e($heute) ?>">
                <select name="payment_method_id" aria-label="Zahlungsart">
                    <?php foreach ($methods as $method): ?>
                        <option value="<?= (int) $method['id'] ?>" <?= $method['kind'] === 'bar' ? 'selected' : '' ?>>
                            <?= e($method['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <button class="btn btn--primary" type="submit">Auswahl abhaken</button>
                <span class="muted">Der Soll-Betrag der jeweiligen Zeile wird als bezahlt übernommen.</span>
            </div>
        <?php endif; ?>
    </div>
</form>

<?php if (Auth::canManageFees()): ?>
    <?php foreach ($entries as $entry): ?>
        <form id="pay-<?= (int) $entry['id'] ?>" method="post" class="hidden"
              action="<?= e(url('/admin/beitraege/bezahlt')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="entry_id" value="<?= (int) $entry['id'] ?>">
            <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
        </form>
    <?php endforeach; ?>
<?php endif; ?>
