<?php

use App\Models\Schedule;

/**
 * Wochenplan-Raster – gemeinsames Teilstück für die Startseite und die
 * einbettbare Version (/embed/wochenplan).
 *
 * Erwartet: $week (Schedule::week()); optional $planLinks = false, um die
 * Kacheln ohne Links zu rendern (im Embed zeigen Links sonst ins Leere,
 * wenn die öffentliche Website deaktiviert ist).
 */
$planLinks ??= true;
?>
<div class="plan-grid">
    <?php foreach ($week as $day => $entries): ?>
        <section class="plan-day<?= $entries === [] ? ' plan-day--free' : '' ?>">
            <h3 class="plan-day__name"><?= e(Schedule::DAYS[$day]) ?></h3>
            <?php if ($entries === []): ?>
                <p class="plan-day__free">trainingsfrei</p>
            <?php else: ?>
                <ul class="plan-day__list">
                    <?php foreach ($entries as $entry): ?>
                        <?php
                        $ziel = $planLinks ? (string) $entry['link'] : '';
                        $tag  = $ziel !== '' ? 'a' : 'div';
                        ?>
                        <li>
                            <<?= $tag ?> class="plan-slot" style="--slot: <?= e($entry['color']) ?>"
                                <?= $ziel !== '' ? 'href="' . e(url('/sektion/' . $ziel)) . '"' : '' ?>>
                                <span class="plan-slot__time"><?= e($entry['from']) ?> – <?= e($entry['to']) ?></span>
                                <span class="plan-slot__icon"><?= Schedule::icon($entry['icon']) ?></span>
                                <span class="plan-slot__title">
                                    <?= e($entry['title']) ?>
                                    <?php if ($entry['badge'] !== ''): ?>
                                        <span class="plan-slot__badge"><?= e($entry['badge']) ?></span>
                                    <?php endif; ?>
                                </span>
                                <?php if ($entry['note'] !== ''): ?>
                                    <span class="plan-slot__note"><?= e($entry['note']) ?></span>
                                <?php endif; ?>
                            </<?= $tag ?>>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </section>
    <?php endforeach; ?>
</div>
