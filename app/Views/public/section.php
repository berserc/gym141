<?php

/**
 * Sektionsseite.
 *
 * Aufbau: oben Logo links, Informationen rechts – darunter der Text,
 * ganz unten das grosse Sektionsplakat im Originalformat (Hochformat,
 * enthaelt Geschichte, Fotos und das 100-Jahre-Logo).
 *
 * @var array<string,mixed>       $section
 * @var list<array<string,mixed>> $contacts
 * @var list<array<string,mixed>> $sections
 */
$poster = (string) $section['hero_path'] !== '' ? upload_url((string) $section['hero_path']) : '';
$logo   = (string) $section['logo_path'] !== '' ? upload_url((string) $section['logo_path']) : '';

$links = array_filter([
    'Website'   => (string) $section['website'],
    'Facebook'  => (string) $section['facebook'],
    'Instagram' => (string) $section['instagram'],
]);

$hatText = trim((string) $section['description']) !== '' || trim((string) $section['training_info']) !== '';
?>
<article class="section-page">
    <div class="wrap">
        <header class="section-title">
            <h1><?= e($section['name']) ?></h1>
            <?php if ((string) $section['club_name'] !== ''): ?>
                <p class="section-title__club"><?= e($section['club_name']) ?></p>
            <?php endif; ?>
        </header>

        <div class="section-intro">
            <div class="section-intro__logo">
                <?php if ($logo !== ''): ?>
                    <img src="<?= e($logo) ?>"
                         alt="Logo <?= e($section['club_name'] ?: $section['name']) ?>"
                         width="500" height="500">
                <?php elseif (site_logo() !== ''): ?>
                    <img src="<?= e(site_logo()) ?>"
                         alt="Logo <?= e(\App\Models\Setting::get('club_name', '')) ?>"
                         width="500" height="500">
                <?php else: ?>
                    <span class="tile__placeholder" aria-hidden="true"><?= e(mb_substr((string) $section['name'], 0, 1)) ?></span>
                <?php endif; ?>
            </div>

            <div class="section-intro__body">
                <?php if ((string) $section['tagline'] !== ''): ?>
                    <p class="section-intro__tagline"><?= e($section['tagline']) ?></p>
                <?php endif; ?>

                <?php if ($contacts !== []): ?>
                    <div class="contact-grid">
                        <?php foreach ($contacts as $contact): ?>
                            <div class="contact-card">
                                <?php if ((string) $contact['role_label'] !== ''): ?>
                                    <p class="contact-card__role"><?= e($contact['role_label']) ?></p>
                                <?php endif; ?>

                                <?php if ((string) $contact['name'] !== ''): ?>
                                    <p class="contact-card__name"><?= e($contact['name']) ?></p>
                                <?php endif; ?>

                                <ul class="contact-card__list">
                                    <?php if ((string) $contact['phone'] !== ''): ?>
                                        <li><span class="contact-card__label">Telefon</span> <?= tel_link((string) $contact['phone']) ?></li>
                                    <?php endif; ?>
                                    <?php if ((string) $contact['mobile'] !== ''): ?>
                                        <li><span class="contact-card__label">Mobil</span> <?= tel_link((string) $contact['mobile']) ?></li>
                                    <?php endif; ?>
                                    <?php if ((string) $contact['fax'] !== ''): ?>
                                        <li><span class="contact-card__label">Fax</span> <?= e($contact['fax']) ?></li>
                                    <?php endif; ?>
                                    <?php if ((string) $contact['email'] !== ''): ?>
                                        <li><span class="contact-card__label">E-Mail</span> <?= mail_link((string) $contact['email']) ?></li>
                                    <?php endif; ?>
                                </ul>

                                <?php if ((string) $contact['note'] !== ''): ?>
                                    <p class="contact-card__note"><?= e($contact['note']) ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($links !== []): ?>
                    <ul class="section-intro__links">
                        <?php foreach ($links as $label => $href): ?>
                            <li><span class="contact-card__label"><?= e($label) ?></span> <?= link_out($href) ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>

        <?php $zeiten = \App\Models\Schedule::forSection((string) $section['slug']); ?>
        <?php if ($zeiten !== []): ?>
            <section class="training-times">
                <h2>Trainingszeiten</h2>
                <div class="table-scroll">
                    <table class="times-table">
                        <thead>
                            <tr><th scope="col">Tag</th><th scope="col">Zeit</th><th scope="col">Training</th><th scope="col">Hinweis</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($zeiten as $zeit): ?>
                            <tr>
                                <td data-label="Tag"><?= e(\App\Models\Schedule::DAYS[$zeit['day']]) ?></td>
                                <td data-label="Zeit"><?= e($zeit['from']) ?>–<?= e($zeit['to']) ?></td>
                                <td data-label="Training">
                                    <span class="times-table__icon" style="color: <?= e($zeit['color']) ?>"><?= \App\Models\Schedule::icon($zeit['icon'], 18) ?></span>
                                    <?= e($zeit['title']) ?>
                                    <?php if ($zeit['badge'] !== ''): ?>
                                        <span class="plan-slot__badge"><?= e($zeit['badge']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td data-label="Hinweis"><?= e($zeit['note']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <p class="training-times__cta">
                    <strong>Dein 1. Training ist gratis!</strong>
                    <a class="btn btn--primary" href="<?= e(whatsapp_link('Hallo! Ich möchte gerne ein gratis Probetraining (' . $section['name'] . ') vereinbaren.')) ?>"
                       target="_blank" rel="noopener">Schreib uns kurz auf WhatsApp</a>
                </p>
            </section>
        <?php endif; ?>

        <?php if ($hatText): ?>
            <div class="section-text">
                <?php if (trim((string) $section['description']) !== ''): ?>
                    <div class="rich-text"><?= $section['description'] ?></div>
                <?php endif; ?>

                <?php if (trim((string) $section['training_info']) !== ''): ?>
                    <section class="training">
                        <h2>Training &amp; Angebot</h2>
                        <div class="rich-text"><?= $section['training_info'] ?></div>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php
        // Optionales Video je Sektion: Datei uploads/sektionen/<slug>-video.mp4
        $sectionVideo = 'sektionen/' . $section['slug'] . '-video.mp4';
        $videoFile    = (string) \App\Core\Config::get('upload_dir', '') . '/' . $sectionVideo;
        ?>
        <?php if (is_file($videoFile)): ?>
            <section class="section-video">
                <h2>Einblick ins Training</h2>
                <video controls preload="metadata" playsinline
                       <?= (string) $section['hero_path'] !== '' ? 'poster="' . e(upload_url((string) $section['hero_path'])) . '"' : '' ?>>
                    <source src="<?= e(upload_url($sectionVideo)) ?>" type="video/mp4">
                </video>
            </section>
        <?php endif; ?>

        <?php if ($poster !== ''): ?>
            <figure class="section-poster">
                <a href="<?= e($poster) ?>" target="_blank" rel="noopener">
                    <img src="<?= e($poster) ?>"
                         alt="Informationstafel der Sektion <?= e($section['name']) ?>"
                         loading="lazy">
                </a>
                <figcaption>Zum Vergrößern anklicken</figcaption>
            </figure>
        <?php endif; ?>

        <?php if (count($sections) > 1): ?>
            <nav class="other-sections" aria-label="Weitere Trainings">
                <h2>Weitere Trainings</h2>
                <ul>
                    <?php foreach ($sections as $other): ?>
                        <?php if ((int) $other['id'] === (int) $section['id']) {
                            continue;
                        } ?>
                        <li><a href="<?= e(url('/sektion/' . $other['slug'])) ?>"><?= e($other['name']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
    </div>
</article>
