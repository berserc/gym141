<?php

use App\Models\Schedule;

/**
 * Startseite: Hero, Trainingsgruppen-Kacheln, Wochenplan.
 *
 * @var list<array<string,mixed>> $sections
 * @var string                    $introTitle
 * @var string                    $introText
 */
$heroVideo  = is_file(dirname(__DIR__, 3) . '/public/assets/video/hero.mp4');
$heroPoster = is_file(dirname(__DIR__, 3) . '/public/assets/img/hero-poster.jpg');
$whatsapp   = whatsapp_link('Hallo! Ich möchte gerne ein Probetraining vereinbaren.');
$week       = Schedule::week();
$hatPlan    = array_filter($week) !== [];
?>
<section class="hero-video<?= $heroVideo ? '' : ' hero-video--static' ?>">
    <?php if ($heroVideo): ?>
        <video class="hero-video__media" autoplay muted loop playsinline
               <?= $heroPoster ? 'poster="' . e(asset('img/hero-poster.jpg')) . '"' : '' ?>
               aria-hidden="true" tabindex="-1">
            <source src="<?= e(asset('video/hero.mp4')) ?>" type="video/mp4">
        </video>
    <?php endif; ?>
    <div class="hero-video__shade" aria-hidden="true"></div>

    <div class="wrap hero-video__content">
        <h1><?= e($introTitle) ?></h1>
        <?php if (trim($introText) !== ''): ?>
            <div class="hero-intro__text"><?= $introText ?></div>
        <?php endif; ?>
        <p class="hero-video__actions">
            <?php if ($whatsapp !== ''): ?>
                <a class="btn btn--primary" href="<?= e($whatsapp) ?>"
                   target="_blank" rel="noopener">Probetraining per WhatsApp</a>
            <?php endif; ?>
            <?php if ($hatPlan): ?>
                <a class="btn btn--ghost btn--on-dark" href="#wochenplan">Wochenplan</a>
            <?php else: ?>
                <a class="btn btn--ghost btn--on-dark" href="#training">Unser Angebot</a>
            <?php endif; ?>
        </p>
    </div>
</section>

<section class="wrap" id="training">
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
</section>

<?php if ($hatPlan): ?>
    <section class="wrap schedule" id="wochenplan">
        <h2 class="section-heading">Wochenplan</h2>

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
                                $ziel = (string) $entry['link'];
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

        <?php if ($whatsapp !== ''): ?>
            <p class="pricing__cta">
                <a class="btn btn--primary" href="<?= e($whatsapp) ?>"
                   target="_blank" rel="noopener">Probetraining per WhatsApp vereinbaren</a>
            </p>
        <?php endif; ?>
    </section>
<?php endif; ?>
