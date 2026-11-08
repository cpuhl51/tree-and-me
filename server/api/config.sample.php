<?php
// Vorlage. Kopie als  tree-and-me-config.php  EINE Ebene ÜBER dem Web-Ordner der Domain ablegen
// (oder als api/config.php – ist per .htaccess gesperrt). Niemals ins Git-Repo!
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'd0xxxxxx',     // all-inkl KAS → Datenbanken
        'user' => 'd0xxxxxx',
        'pass' => 'HIER_DB_PASSWORT',
    ],
    // Adresse, unter der die App läuft (API liegt darunter in /api), ohne / am Ende
    'app_url' => 'https://app.tree-and-me.puhl.ch',

    // Google Cloud Console → APIs & Dienste → Anmeldedaten → OAuth-Client-ID (Webanwendung)
    'google_client_ids' => ['XXXXXXXX.apps.googleusercontent.com'],

    'mail_absender' => 'noreply@puhl.ch',   // muss bei all-inkl als Postfach/Adresse existieren
    'mail_admin' => 'deine@adresse.at',          // bekommt Test-Sammelmails
    'ma42_mail' => '',                           // leer = Testbetrieb: Sammelmails gehen nur an mail_admin

    // Langer Zufallswert für den Cronjob-Aufruf, z. B. aus einem Passwortgenerator (mind. 20 Zeichen)
    'cron_key' => 'HIER_LANGER_ZUFALLSWERT',

    // Optional
    // 'daten_dir' => __DIR__ . '/tree-and-me-daten',  // Fotos & Logs, am besten außerhalb des Web-Ordners
    // 'besuch_max_abstand_m' => 100,
    // 'kataster_sekunden' => 25,
];
