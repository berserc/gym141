<?php

use App\Core\Auth;
use App\Models\CalendarRepo;

/**
 * Organisation eines Termins/Events (z. B. Fight Night): Aufgabenbereiche
 * mit zugeteilten Personen (Mitglieder oder Externe) und eine abhakbare
 * To-do-Liste.
 *
 * @var array<string,mixed>                    $event
 * @var list<array<string,mixed>>              $tasks
 * @var array<int,list<array<string,mixed>>>   $people je Aufgabe
 * @var list<array<string,mixed>>              $todos
 * @var list<array<string,mixed>>              $mitglieder Auswahlliste
 */
$darfSchreiben = Auth::is('superuser', 'kassier', 'sektionsleiter');
$id            = (int) $event['id'];
$offen         = count(array_filter($todos, static fn (array $t): bool => (int) $t['done'] === 0));
?>
<div class="page-head">
    <div>
        <h1>Organisation: <?= e($event['title']) ?></h1>
        <p class="page-head__sub">
            <?= e(CalendarRepo::rangeLabel((string) $event['starts_on'], $event['ends_on'] === null ? null : (string) $event['ends_on'])) ?>
            <?= (string) $event['location'] !== '' ? '· 📍 ' . e($event['location']) : '' ?>
            · <?= count($tasks) ?> Aufgabe(n)
            · To-dos: <?= count($todos) - $offen ?>/<?= count($todos) ?> erledigt
        </p>
    </div>

    <div class="page-head__actions">
        <a class="btn btn--ghost" href="<?= e(url('/admin/termine')) ?>">Zu den Terminen</a>
    </div>
</div>

<datalist id="member-list">
    <?php foreach ($mitglieder as $m): ?>
        <option value="<?= e($m['last_name'] . ' ' . $m['first_name']) ?>">
            <?= (string) $m['member_no'] !== '' ? e('Nr. ' . $m['member_no']) : '' ?>
        </option>
    <?php endforeach; ?>
</datalist>

<div class="form-grid">
    <div>
        <?php foreach ($tasks as $task): ?>
            <div class="card">
                <div class="card__head">
                    <h2><?= e($task['title']) ?>
                        <span class="badge"><?= count($people[(int) $task['id']] ?? []) ?> Person(en)</span>
                        <?php if (($task['task141_url'] ?? '') !== ''): ?>
                            <a class="badge" style="text-decoration:none" target="_blank" rel="noopener"
                               href="<?= e((string) $task['task141_url']) ?>"
                               title="Task141-Freigabe für Externe – Link teilen">extern freigegeben ↗</a>
                        <?php endif; ?>
                    </h2>
                    <?php if ($darfSchreiben): ?>
                        <div>
                            <details class="plan-edit inline">
                                <summary class="linklike">bearbeiten</summary>
                                <form method="post" action="<?= e(url('/admin/termine/' . $id . '/aufgabe/' . $task['id'])) ?>" class="inline-form">
                                    <?= csrf_field() ?>
                                    <div class="field field--sm">
                                        <label>Titel</label>
                                        <input name="title" required value="<?= e($task['title']) ?>">
                                    </div>
                                    <div class="field field--grow">
                                        <label>Notiz</label>
                                        <input name="note" value="<?= e($task['note']) ?>">
                                    </div>
                                    <button class="btn btn--sm" type="submit">Speichern</button>
                                </form>
                            </details>
                            <form method="post" class="inline" action="<?= e(url('/admin/termine/' . $id . '/aufgabe-loeschen')) ?>"
                                  data-confirm="Aufgabe „<?= e($task['title']) ?>“ samt Zuteilungen löschen?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="task_id" value="<?= (int) $task['id'] ?>">
                                <button class="linklike linklike--danger" type="submit">löschen</button>
                            </form>
                            <?php if (($task['task141_url'] ?? '') === '' && \App\Models\Setting::get('task141_url') !== ''): ?>
                                <form method="post" class="inline"
                                      action="<?= e(url('/admin/termine/' . $id . '/aufgabe/' . $task['id'] . '/task141')) ?>">
                                    <?= csrf_field() ?>
                                    <button class="linklike" type="submit"
                                            title="Aufgabe über Task141 für Helfer außerhalb des Vereins freigeben">für Externe freigeben</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ((string) $task['note'] !== ''): ?>
                    <p class="muted"><?= e($task['note']) ?></p>
                <?php endif; ?>

                <?php if (($people[(int) $task['id']] ?? []) !== []): ?>
                    <ul class="chip-list">
                        <?php foreach ($people[(int) $task['id']] as $person): ?>
                            <li class="chip">
                                <?php if ($person['member_id'] !== null && ($person['last_name'] ?? null) !== null): ?>
                                    <a href="<?= e(url('/admin/mitglieder/' . $person['member_id'])) ?>">
                                        <?= e($person['last_name'] . ' ' . $person['first_name']) ?>
                                    </a>
                                <?php else: ?>
                                    <?= e($person['name']) ?> <span class="badge badge--muted">extern</span>
                                <?php endif; ?>
                                <?php if ((string) $person['contact'] !== ''): ?>
                                    <small class="muted"><?= e($person['contact']) ?></small>
                                <?php endif; ?>
                                <?php if ((string) $person['note'] !== ''): ?>
                                    <small class="muted">(<?= e($person['note']) ?>)</small>
                                <?php endif; ?>
                                <?php if ($darfSchreiben): ?>
                                    <form method="post" class="inline" action="<?= e(url('/admin/termine/' . $id . '/person-loeschen')) ?>">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="person_id" value="<?= (int) $person['id'] ?>">
                                        <button class="chip__remove" type="submit" title="aus der Aufgabe entfernen">×</button>
                                    </form>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="muted">Noch niemand zugeteilt.</p>
                <?php endif; ?>

                <?php if ($darfSchreiben): ?>
                    <form method="post" action="<?= e(url('/admin/termine/' . $id . '/aufgabe/' . $task['id'] . '/person')) ?>" class="inline-form">
                        <?= csrf_field() ?>

                        <div class="field field--sm">
                            <label>Mitglied</label>
                            <input name="member_ref" list="member-list" placeholder="tippen zum Suchen …">
                        </div>

                        <div class="field field--sm">
                            <label>… oder Externe:r</label>
                            <input name="name" placeholder="Name">
                        </div>

                        <div class="field field--sm">
                            <label>Kontakt <small>(bei Externen)</small></label>
                            <input name="contact" placeholder="Telefon / E-Mail">
                        </div>

                        <div class="field field--grow">
                            <label>Notiz</label>
                            <input name="person_note" placeholder="z. B. ab 16 Uhr">
                        </div>

                        <button class="btn btn--sm" type="submit">Zuteilen</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>

        <?php if ($tasks === []): ?>
            <div class="card"><p class="empty">Noch keine Aufgaben angelegt (z. B. Aufbau, Kassa, Catering, Security …).</p></div>
        <?php endif; ?>

        <?php if ($darfSchreiben): ?>
            <div class="card">
                <div class="card__head"><h2>Neue Aufgabe</h2></div>
                <form method="post" action="<?= e(url('/admin/termine/' . $id . '/aufgabe')) ?>" class="inline-form">
                    <?= csrf_field() ?>

                    <div class="field field--sm">
                        <label>Titel *</label>
                        <input name="title" required placeholder="z. B. Aufbau, Kassa, Catering">
                    </div>

                    <div class="field field--grow">
                        <label>Notiz</label>
                        <input name="note" placeholder="Was ist zu tun?">
                    </div>

                    <button class="btn btn--primary" type="submit">Anlegen</button>
                </form>
            </div>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card__head">
            <h2>To-do-Liste</h2>
            <?php if ($todos !== []): ?>
                <span class="badge <?= $offen === 0 ? 'badge--ok' : 'badge--warn' ?>">
                    <?= count($todos) - $offen ?>/<?= count($todos) ?> erledigt
                </span>
            <?php endif; ?>
        </div>

        <?php if ($todos === []): ?>
            <p class="muted">Noch keine To-dos.</p>
        <?php endif; ?>

        <ul class="todo-list">
            <?php foreach ($todos as $todo): ?>
                <?php $erledigt = (int) $todo['done'] === 1; ?>
                <li class="todo<?= $erledigt ? ' is-done' : '' ?>">
                    <?php if ($darfSchreiben): ?>
                        <form method="post" action="<?= e(url('/admin/termine/' . $id . '/todo-umschalten')) ?>" class="inline">
                            <?= csrf_field() ?>
                            <input type="hidden" name="todo_id" value="<?= (int) $todo['id'] ?>">
                            <button class="todo__check" type="submit"
                                    title="<?= $erledigt ? 'wieder öffnen' : 'abhaken' ?>"><?= $erledigt ? '☑' : '☐' ?></button>
                        </form>
                    <?php else: ?>
                        <span class="todo__check"><?= $erledigt ? '☑' : '☐' ?></span>
                    <?php endif; ?>

                    <span class="todo__text">
                        <?= e($todo['title']) ?>
                        <?php if (($todo['due_on'] ?? null) !== null && $todo['due_on'] !== ''): ?>
                            <?php $ueberfaellig = !$erledigt && (string) $todo['due_on'] < date('Y-m-d'); ?>
                            <span class="badge<?= $ueberfaellig ? ' badge--danger' : '' ?>">bis <?= e(format_date((string) $todo['due_on'])) ?></span>
                        <?php endif; ?>
                        <?php if ($erledigt && (string) ($todo['done_by_name'] ?? '') !== ''): ?>
                            <small class="muted">✔ <?= e($todo['done_by_name']) ?>, <?= e(format_datetime((string) $todo['done_at'])) ?></small>
                        <?php endif; ?>
                    </span>

                    <?php if ($darfSchreiben): ?>
                        <form method="post" class="inline" action="<?= e(url('/admin/termine/' . $id . '/todo-loeschen')) ?>"
                              data-confirm="To-do löschen?">
                            <?= csrf_field() ?>
                            <input type="hidden" name="todo_id" value="<?= (int) $todo['id'] ?>">
                            <button class="linklike linklike--danger" type="submit">×</button>
                        </form>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <?php if ($darfSchreiben): ?>
            <form method="post" action="<?= e(url('/admin/termine/' . $id . '/todo')) ?>" class="inline-form">
                <?= csrf_field() ?>

                <div class="field field--grow">
                    <label>Neues To-do</label>
                    <input name="title" required placeholder="z. B. Genehmigung Gemeinde einholen">
                </div>

                <div class="field field--sm">
                    <label>fällig am <small>(optional)</small></label>
                    <input name="due_on" type="date">
                </div>

                <button class="btn" type="submit">Hinzufügen</button>
            </form>
        <?php endif; ?>
    </div>
</div>
