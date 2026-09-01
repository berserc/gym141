<?php

use App\Core\Auth;

/** @var list<array<string,mixed>> $users */
?>
<div class="page-head">
    <h1>Benutzer</h1>
    <div class="page-head__actions">
        <a class="btn btn--primary" href="<?= e(url('/admin/benutzer/neu')) ?>">Neuer Benutzer</a>
    </div>
</div>

<div class="card">
    <div class="table-scroll">
        <table class="table">
            <thead>
            <tr>
                <th>Benutzername</th>
                <th>Name</th>
                <th>Mitglied</th>
                <th>Rolle</th>
                <th>Sektionen</th>
                <th>Status</th>
                <th>Letzte Anmeldung</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><a class="strong" href="<?= e(url('/admin/benutzer/' . $user['id'])) ?>"><?= e($user['username']) ?></a></td>
                    <td><?= e($user['name']) ?></td>
                    <td>
                        <?php if (($user['member_id'] ?? null) !== null && ($user['member_last_name'] ?? null) !== null): ?>
                            <a href="<?= e(url('/admin/mitglieder/' . (int) $user['member_id'])) ?>">
                                <?= e($user['member_last_name']) ?>, <?= e($user['member_first_name']) ?>
                            </a>
                            <?php if (($user['member_archived_at'] ?? null) !== null): ?>
                                <span class="badge badge--muted">ehemalig</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="muted">–</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(implode(', ', array_map(
                        static fn (string $r): string => Auth::ROLES[$r] ?? $r,
                        (array) ($user['roles'] ?? [(string) $user['role']])
                    ))) ?></td>
                    <td>
                        <?php if (array_intersect((array) ($user['roles'] ?? []), Auth::SECTION_SCOPED_ROLES) !== []): ?>
                            <?= e(implode(', ', array_column($user['sections'], 'name'))) ?: '<span class="muted">keine</span>' ?>
                        <?php else: ?>
                            <span class="muted">alle</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $user['active'] === 1): ?>
                            <span class="pill pill--aktiv">aktiv</span>
                        <?php else: ?>
                            <span class="pill pill--offen">gesperrt</span>
                        <?php endif; ?>
                        <?php if ((int) $user['must_change_password'] === 1): ?>
                            <span class="badge">Passwortwechsel offen</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e(format_datetime($user['last_login_at'] === null ? null : (string) $user['last_login_at'])) ?: '–' ?></td>
                    <td class="row-actions">
                        <a href="<?= e(url('/admin/benutzer/' . $user['id'])) ?>">Bearbeiten</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
