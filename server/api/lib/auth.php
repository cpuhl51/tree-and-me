<?php
// Anmeldung: E-Mail/Passwort und Google, Sitzungen per Cookie, Konto ändern/löschen
declare(strict_types=1);

// ---------- Sitzungen ----------

function sitzung_starten(int $spielerId): void {
    $token = zufall();
    $tage = (int)cfg('sitzung_tage');
    q('INSERT INTO sitzungen (token_hash, spieler, ablauf) VALUES (?, ?, ?)',
        [hash('sha256', $token), $spielerId, jetzt(86400 * $tage)]);
    setcookie((string)cfg('cookie_name'), $token, [
        'expires' => time() + 86400 * $tage, 'path' => '/',
        'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function sitzung_beenden(): void {
    $token = $_COOKIE[cfg('cookie_name')] ?? '';
    if ($token !== '') q('DELETE FROM sitzungen WHERE token_hash = ?', [hash('sha256', $token)]);
    setcookie((string)cfg('cookie_name'), '', [
        'expires' => time() - 3600, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

function aktueller_spieler(): ?array {
    static $s = false;
    if ($s !== false) return $s;
    $token = $_COOKIE[cfg('cookie_name')] ?? '';
    $s = null;
    if (is_string($token) && preg_match('/^[0-9a-f]{64}$/', $token)) {
        $s = q('SELECT s.* FROM sitzungen z JOIN spieler s ON s.id = z.spieler
                WHERE z.token_hash = ? AND z.ablauf > NOW() AND s.gesperrt = 0',
            [hash('sha256', $token)])->fetch() ?: null;
    }
    return $s;
}

function muss_angemeldet(): array {
    return aktueller_spieler() ?? fehler('nicht_angemeldet', 'Bitte melde dich an.', 401);
}

// ---------- Prüfungen ----------

function name_gueltig(string $name): ?string {
    $name = preg_replace('/\s+/u', ' ', trim($name));
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _.\-]{1,28}[\p{L}\p{N}]$/u', $name)) return null;
    return $name;
}

function name_frei(string $name, int $ausser = 0): bool {
    return !q('SELECT 1 FROM spieler WHERE name = ? AND id <> ?', [$name, $ausser])->fetch();
}

function email_gueltig(string $email): ?string {
    $email = mb_strtolower(trim($email));
    return (filter_var($email, FILTER_VALIDATE_EMAIL) && strlen($email) <= 190) ? $email : null;
}

function passwort_ok(string $pw): void {
    if (mb_strlen($pw) < 8) fehler('passwort_kurz', 'Das Passwort braucht mindestens 8 Zeichen.');
    if (strlen($pw) > 200) fehler('passwort_lang', 'Das Passwort ist zu lang.');
}

function mail_token(int $spielerId, string $zweck, int $sekunden): string {
    q('DELETE FROM mail_tokens WHERE spieler = ? AND zweck = ?', [$spielerId, $zweck]);
    $t = zufall();
    q('INSERT INTO mail_tokens (token_hash, spieler, zweck, ablauf) VALUES (?, ?, ?, ?)',
        [hash('sha256', $t), $spielerId, $zweck, jetzt($sekunden)]);
    return $t;
}

function mail_token_einloesen(string $t, string $zweck): ?int {
    if (!preg_match('/^[0-9a-f]{64}$/', $t)) return null;
    $h = hash('sha256', $t);
    $r = q('SELECT spieler FROM mail_tokens WHERE token_hash = ? AND zweck = ? AND ablauf > NOW()', [$h, $zweck])->fetch();
    if (!$r) return null;
    q('DELETE FROM mail_tokens WHERE token_hash = ?', [$h]);
    return (int)$r['spieler'];
}

function bestaetigungsmail(int $id, string $email, string $name): void {
    $t = mail_token($id, 'bestaetigen', 86400 * 2);
    mail_senden($email, 'Tree and me: Bitte bestätige deine E-Mail-Adresse',
        "Hallo $name,\n\nbitte bestätige deine Anmeldung bei „Tree and me“ mit diesem Link (2 Tage gültig):\n\n"
        . api_link('auth/bestaetigen?t=' . $t)
        . "\n\nWenn du dich nicht angemeldet hast, ignoriere diese Mail einfach.\n");
}

// ---------- Endpunkte ----------

/** POST auth/registrieren {name, email, passwort} */
function ep_registrieren(): never {
    bremse('reg:ip:' . ip(), 10, 60);
    $name = name_gueltig(feld('name', 60)) ?? fehler('name_ungueltig', 'Der Name braucht 3–30 Zeichen (Buchstaben, Ziffern, Leerzeichen, _ . -).');
    $email = email_gueltig(feld('email', 200)) ?? fehler('email_ungueltig', 'Bitte gib eine gültige E-Mail-Adresse ein.');
    $pw = (string)(eingabe()['passwort'] ?? '');
    passwort_ok($pw);
    bremse('reg:mail:' . $email, 3, 60);

    $vorhanden = q('SELECT * FROM spieler WHERE email = ?', [$email])->fetch();
    $antwort = ['ok' => true, 'text' => 'Fast geschafft: Wir haben dir eine Mail geschickt. Bitte bestätige den Link darin.'];

    if ($vorhanden && $vorhanden['email_bestaetigt']) {
        // Nicht verraten, dass es die Adresse schon gibt – der Inhaber bekommt einen Hinweis per Mail
        mail_senden($email, 'Tree and me: Du hast schon ein Konto',
            "Hallo,\n\njemand (vermutlich du) wollte sich mit dieser Adresse neu anmelden. Du hast aber schon ein Konto.\n"
            . "Passwort vergessen? In der App auf „Passwort vergessen“ tippen.\n\n" . app_link() . "\n");
        antwort($antwort);
    }
    if (!name_frei($name, $vorhanden ? (int)$vorhanden['id'] : 0)) {
        fehler('name_vergeben', 'Dieser Name ist schon vergeben.');
    }
    $hash = password_hash($pw, PASSWORD_DEFAULT);
    if ($vorhanden) { // unbestätigt: Angaben überschreiben, neue Mail
        q('UPDATE spieler SET name = ?, passwort_hash = ? WHERE id = ?', [$name, $hash, $vorhanden['id']]);
        $id = (int)$vorhanden['id'];
    } else {
        q('INSERT INTO spieler (name, email, passwort_hash) VALUES (?, ?, ?)', [$name, $email, $hash]);
        $id = (int)db()->lastInsertId();
    }
    bestaetigungsmail($id, $email, $name);
    antwort($antwort);
}

/** GET auth/bestaetigen?t=… (Link aus der Mail) → meldet an und leitet zur App */
function ep_bestaetigen(): never {
    $id = mail_token_einloesen((string)($_GET['t'] ?? ''), 'bestaetigen');
    if ($id === null) { header('Location: ' . app_link('#bestaetigung-ungueltig')); exit; }
    q('UPDATE spieler SET email_bestaetigt = 1 WHERE id = ?', [$id]);
    sitzung_starten($id);
    header('Location: ' . app_link('#angemeldet'));
    exit;
}

/** POST auth/login {email, passwort} */
function ep_login(): never {
    bremse('login:ip:' . ip(), 30, 15);
    $email = email_gueltig(feld('email', 200)) ?? fehler('falsch', 'E-Mail oder Passwort stimmt nicht.', 401);
    bremse('login:mail:' . $email, 8, 15);
    $pw = (string)(eingabe()['passwort'] ?? '');
    $s = q('SELECT * FROM spieler WHERE email = ?', [$email])->fetch();
    // password_verify auch ohne Konto ausführen, damit die Antwortzeit nichts verrät
    $hash = $s['passwort_hash'] ?? '$2y$10$mfR64UD7ikPDk0xMj/Jj0OaWTCoIcUf.AYgKSsL7R85Sb8JzCg34W';
    if (!password_verify($pw, $hash) || !$s || $s['gesperrt']) {
        fehler('falsch', 'E-Mail oder Passwort stimmt nicht.', 401);
    }
    if (!$s['email_bestaetigt']) {
        bestaetigungsmail((int)$s['id'], $email, $s['name']);
        fehler('unbestaetigt', 'Bitte bestätige zuerst deine E-Mail-Adresse. Wir haben dir den Link nochmal geschickt.', 403);
    }
    if (password_needs_rehash($s['passwort_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE spieler SET passwort_hash = ? WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $s['id']]);
    }
    sitzung_starten((int)$s['id']);
    antwort(['ok' => true, 'spieler' => spieler_info((int)$s['id'])]);
}

/** POST auth/google {credential} – das ID-Token aus google.accounts.id */
function ep_google(): never {
    bremse('google:ip:' . ip(), 30, 15);
    try {
        $g = google_token_pruefen(feld('credential', 4000));
    } catch (Throwable $e) {
        protokoll('Google-Login abgelehnt: ' . $e->getMessage());
        fehler('google_ungueltig', 'Die Google-Anmeldung konnte nicht geprüft werden.', 401);
    }
    $neu = false;
    $s = q('SELECT * FROM spieler WHERE google_sub = ?', [$g['sub']])->fetch();
    if (!$s && $g['email'] && $g['email_verified']) {
        $s = q('SELECT * FROM spieler WHERE email = ?', [$g['email']])->fetch();
        if ($s && !$s['email_bestaetigt']) {
            // Unbestätigtes Passwort-Konto mit derselben Adresse: Google beweist, wem sie gehört.
            // Passwort löschen, damit niemand ein fremdes Konto vorab „reservieren“ kann.
            q('UPDATE spieler SET passwort_hash = NULL, email_bestaetigt = 1 WHERE id = ?', [$s['id']]);
        }
        if ($s) q('UPDATE spieler SET google_sub = ? WHERE id = ?', [$g['sub'], $s['id']]);
    }
    if (!$s) {
        // Neues Konto mit Platzhalter-Namen; die App fragt danach nach einem Spitznamen
        do { $name = 'Baumfreund' . random_int(1000, 99999); } while (!name_frei($name));
        q('INSERT INTO spieler (name, google_sub, email, email_bestaetigt) VALUES (?, ?, ?, ?)',
            [$name, $g['sub'], $g['email_verified'] ? $g['email'] : null, $g['email_verified'] ? 1 : 0]);
        $s = ['id' => (int)db()->lastInsertId(), 'gesperrt' => 0];
        $neu = true;
    }
    if ($s['gesperrt']) fehler('gesperrt', 'Dieses Konto ist gesperrt.', 403);
    sitzung_starten((int)$s['id']);
    antwort(['ok' => true, 'neu' => $neu, 'spieler' => spieler_info((int)$s['id'])]);
}

/** POST auth/logout */
function ep_logout(): never {
    sitzung_beenden();
    antwort(['ok' => true]);
}

/** POST auth/passwort-vergessen {email} */
function ep_passwort_vergessen(): never {
    bremse('pwv:ip:' . ip(), 10, 60);
    $email = email_gueltig(feld('email', 200));
    if ($email) {
        bremse('pwv:mail:' . $email, 3, 60);
        $s = q('SELECT id, name FROM spieler WHERE email = ? AND gesperrt = 0', [$email])->fetch();
        if ($s) {
            $t = mail_token((int)$s['id'], 'passwort', 3600);
            mail_senden($email, 'Tree and me: Neues Passwort',
                "Hallo {$s['name']},\n\nhier kannst du ein neues Passwort festlegen (1 Stunde gültig):\n\n"
                . app_link('#passwort-neu=' . $t)
                . "\n\nWenn du das nicht angefordert hast, ignoriere diese Mail. Dein Passwort bleibt dann gleich.\n");
        }
    }
    antwort(['ok' => true, 'text' => 'Wenn es ein Konto mit dieser Adresse gibt, ist eine Mail unterwegs.']);
}

/** POST auth/passwort-neu {token, passwort} */
function ep_passwort_neu(): never {
    bremse('pwn:ip:' . ip(), 20, 60);
    $pw = (string)(eingabe()['passwort'] ?? '');
    passwort_ok($pw);
    $id = mail_token_einloesen(feld('token', 100), 'passwort')
        ?? fehler('token_ungueltig', 'Der Link ist abgelaufen oder wurde schon benutzt.');
    q('UPDATE spieler SET passwort_hash = ?, email_bestaetigt = 1 WHERE id = ?', [password_hash($pw, PASSWORD_DEFAULT), $id]);
    q('DELETE FROM sitzungen WHERE spieler = ?', [$id]); // alle Geräte abmelden
    sitzung_starten($id);
    antwort(['ok' => true, 'spieler' => spieler_info($id)]);
}

// ---------- Eigenes Konto ----------

function spieler_info(int $id): array {
    $s = q('SELECT id, name, email, google_sub, passwort_hash, erstellt FROM spieler WHERE id = ?', [$id])->fetch();
    $stand = q('SELECT COALESCE(SUM(punkte), 0) AS punkte,
                       COALESCE(SUM(CASE WHEN art = \'meldung\' AND vorlaeufig_bis > NOW() THEN punkte END), 0) AS vorlaeufig
                FROM punkte WHERE spieler = ? AND storniert = 0', [$id])->fetch();
    $baeume = (int)q('SELECT COUNT(*) FROM besuche WHERE spieler = ?', [$id])->fetchColumn();
    return [
        'id' => (int)$s['id'],
        'name' => $s['name'],
        'email' => $s['email'],
        'google' => $s['google_sub'] !== null,
        'passwort' => $s['passwort_hash'] !== null,
        'name_waehlen' => (bool)preg_match('/^Baumfreund\d+$/', $s['name']),
        'punkte' => (int)$stand['punkte'],
        'davon_vorlaeufig' => (int)$stand['vorlaeufig'],
        'baeume' => $baeume,
        'seit' => $s['erstellt'],
    ];
}

/** GET ich */
function ep_ich(): never {
    $s = aktueller_spieler();
    antwort(['ok' => true, 'spieler' => $s ? spieler_info((int)$s['id']) : null]);
}

/** POST ich {name} */
function ep_ich_aendern(): never {
    $s = muss_angemeldet();
    bremse('name:' . $s['id'], 10, 60);
    $name = name_gueltig(feld('name', 60)) ?? fehler('name_ungueltig', 'Der Name braucht 3–30 Zeichen (Buchstaben, Ziffern, Leerzeichen, _ . -).');
    if (preg_match('/^Baumfreund\d+$/', $name) && $name !== $s['name']) fehler('name_ungueltig', 'Bitte wähle einen eigenen Namen.');
    if (!name_frei($name, (int)$s['id'])) fehler('name_vergeben', 'Dieser Name ist schon vergeben.');
    q('UPDATE spieler SET name = ? WHERE id = ?', [$name, $s['id']]);
    antwort(['ok' => true, 'spieler' => spieler_info((int)$s['id'])]);
}

/** POST ich/loeschen {bestaetigung: "LÖSCHEN"} – Pflicht für den Play Store */
function ep_konto_loeschen(): never {
    $s = muss_angemeldet();
    if (feld('bestaetigung', 20) !== 'LÖSCHEN') fehler('bestaetigung', 'Bitte zur Bestätigung LÖSCHEN eingeben.');
    // Besuche, Punkte, Sitzungen, Erster-Status, Fotos-Einträge fallen per ON DELETE CASCADE mit weg.
    // Meldungen an die Stadtgärten bleiben ohne Spielerbezug erhalten.
    q('DELETE FROM spieler WHERE id = ?', [$s['id']]);
    sitzung_beenden();
    antwort(['ok' => true, 'text' => 'Dein Konto und alle Spieldaten wurden gelöscht.']);
}
