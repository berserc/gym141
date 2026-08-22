<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Einrichtung erforderlich</title>
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
</head>
<body class="page">
<main class="site-main">
    <article class="wrap text-page">
        <h1>Einrichtung erforderlich</h1>
        <p>Die Datenbank wurde noch nicht angelegt. Bitte auf dem Server einmalig ausführen:</p>
        <pre><code>php bin/install.php</code></pre>
        <p>Danach ist der Verwaltungsbereich unter <code>/admin</code> erreichbar.</p>
    </article>
</main>
</body>
</html>
