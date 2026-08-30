<?php

/**
 * Aufgaben-Uebersicht (Task141 eingebaut): Checklisten, Anhaenge und
 * Freigabe-Links fuer Externe – unabhaengig von Terminen nutzbar.
 *
 * @var list<array<string,mixed>> $tasks
 */
$offen = array_values(array_filter($tasks, static fn (array $t): bool => $t['status'] === 'offen'));
$fertig = array_values(array_filter($tasks, static fn (array $t): bool => $t['status'] === 'erledigt'));
?>
<div class="page-head">
    <div>
        <h1>Aufgaben</h1>
        <p class="page-head__sub">
            To-dos mit Checklisten, Anhängen (Dokumente, Fotos, Videos) und
            Freigabe-Links, mit denen Externe ohne Zugang mitarbeiten können.
            · <?= count($offen) ?> offen, <?= count($fertig) ?> erledigt
        </p>
    </div>
</div>

<div class="card">
    <form method="post" action="<?= e(url('/admin/aufgaben')) ?>" class="inline-form">
        <?= csrf_field() ?>
        <div class="field field--grow">
            <label>Neue Aufgabe</label>
            <input name="title" required maxlength="200" placeholder="z. B. Kuchenbuffet organisieren">
        </div>
        <div class="field field--sm">
            <label>Fällig am</label>
            <input type="date" name="due_date">
        </div>
        <button class="btn" type="submit">Anlegen</button>
    </form>
</div>

<?php foreach ([['Offen', $offen], ['Erledigt', $fertig]] as [$abschnitt, $liste]): ?>
    <?php if ($liste === []) { continue; } ?>
    <div class="card">
        <div class="card__head"><h2><?= e($abschnitt) ?></h2></div>
        <div class="table-wrap">
            <table class="table">
                <thead>
                <tr>
                    <th>Aufgabe</th>
                    <th>Fällig</th>
                    <th>Checkliste</th>
                    <th>Anhänge</th>
                    <th>Ersteller</th>
                    <th>Freigabe</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($liste as $task): ?>
                    <tr>
                        <td><a href="<?= e(url('/admin/aufgaben/' . $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a></td>
                        <td><?= $task['due_date'] !== null ? e(date('d.m.Y', (int) strtotime((string) $task['due_date']))) : '–' ?></td>
                        <td><?= (int) $task['n_items'] > 0 ? (int) $task['n_done'] . '/' . (int) $task['n_items'] : '–' ?></td>
                        <td><?= (int) $task['n_files'] > 0 ? (int) $task['n_files'] : '–' ?></td>
                        <td><?= e((string) ($task['ersteller'] ?? '')) ?: '–' ?></td>
                        <td>
                            <?php if ($task['share_token'] !== null): ?>
                                <a class="badge" style="text-decoration:none" target="_blank" rel="noopener"
                                   href="<?= e(url('/f/' . $task['share_token'])) ?>">extern freigegeben ↗</a>
                            <?php else: ?>
                                –
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<?php if ($tasks === []): ?>
    <div class="card"><p class="muted">Noch keine Aufgaben – lege oben die erste an.</p></div>
<?php endif; ?>
