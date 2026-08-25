<?php

/**
 * Trainingsgruppen-Kacheln – gemeinsames Teilstück für die Startseite und
 * den "Trainingsgruppen"-Inhaltsblock.
 *
 * Erwartet: $sections (SectionRepo::allPublished()).
 */
?>
<?php if ($sections === []): ?>
    <p class="empty">Derzeit sind keine Trainings veröffentlicht.</p>
<?php else: ?>
    <ul class="tiles">
        <?php foreach ($sections as $section): ?>
            <?php
            $image = (string) $section['tile_path'] !== ''
                ? upload_url((string) $section['tile_path'])
                : ((string) $section['hero_path'] !== '' ? upload_url((string) $section['hero_path']) : '');
            ?>
            <li class="tile">
                <a class="tile__link" href="<?= e(url('/sektion/' . $section['slug'])) ?>">
                    <div class="tile__media">
                        <?php if ($image !== ''): ?>
                            <img src="<?= e($image) ?>" alt="" loading="lazy" width="900" height="600">
                        <?php else: ?>
                            <span class="tile__placeholder" aria-hidden="true"><?= e(mb_substr((string) $section['name'], 0, 1)) ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tile__body">
                        <h2 class="tile__title"><?= e($section['name']) ?></h2>
                        <?php if ((string) $section['club_name'] !== ''): ?>
                            <p class="tile__sub"><?= e($section['club_name']) ?></p>
                        <?php endif; ?>
                    </div>
                </a>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
