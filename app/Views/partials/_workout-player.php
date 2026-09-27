<?php

/**
 * Wiedergabe einer Trainingseinheit aus der App: Karte (Leaflet + Geoapify/OSM),
 * Herzfrequenz-Kurve, Zeitleiste. Gleiches Skript wie in der Mitglieder-App.
 *
 * @var array<string,mixed> $workout  dekodierte Einheit (hr[], track[], durationS …)
 * @var string              $geoKey   Geoapify-Schluessel ('' = OpenStreetMap-Kacheln)
 * @var string              $accent   Akzentfarbe fuer Marker/Abspielposition
 */
$accent = $accent ?? '#f2c14e';
$hatTrack = count((array) ($workout['track'] ?? [])) >= 2;
?>
<link rel="stylesheet" href="<?= e(asset('css/workout.css')) ?>">
<div id="wo-player" class="wo-player">
    <div class="wo-map"></div>
    <canvas class="wo-chart"></canvas>
    <div class="wo-controls">
        <button type="button" class="wo-play" aria-label="Abspielen">▶</button>
        <input type="range" class="wo-slider" min="0" max="1" step="0.5" value="0" aria-label="Zeitleiste">
        <select class="wo-speed" aria-label="Geschwindigkeit" onchange="workout.speed(parseFloat(this.value))">
            <option value="1">1×</option>
            <option value="5">5×</option>
            <option value="20" selected>20×</option>
            <option value="60">60×</option>
        </select>
    </div>
    <div class="wo-readout">
        <span><b class="wo-time">0:00</b><small>Zeit</small></span>
        <span><b class="wo-bpm">–</b><small>Puls</small></span>
        <span><b class="wo-dist">–</b><small>Strecke</small></span>
        <span><b class="wo-pace">–</b><small>Tempo</small></span>
    </div>
</div>
<?php if (!$hatTrack): ?>
    <p class="muted" style="margin-top:.4rem">Keine GPS-Strecke in dieser Einheit – nur die Herzfrequenz wird abgespielt.</p>
<?php endif; ?>
<script src="<?= e(asset('js/workout.js')) ?>"></script>
<script>
document.addEventListener("DOMContentLoaded", function () {
    workout.init("wo-player",
        <?= json_encode(['track' => $workout['track'] ?? [], 'hr' => $workout['hr'] ?? [], 'durationS' => (float) ($workout['durationS'] ?? 0)], JSON_UNESCAPED_SLASHES) ?>,
        <?= json_encode(['geoapifyKey' => $geoKey, 'color' => $accent], JSON_UNESCAPED_SLASHES) ?>
    ).then(function () { workout.speed(20); });
});
</script>
