<?php
/** @var array<string,mixed> $cfg */
$html = (string) ($cfg['html'] ?? '');
if ($html === '') {
    return;
}
?>
<section class="wrap block block--text">
    <div class="rich-text"><?= $html /* safe_html beim Speichern */ ?></div>
</section>
