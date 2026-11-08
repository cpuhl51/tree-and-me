<?php
// Spielregeln, Besuche und Rangliste. Punkte werden NUR hier am Server vergeben.
declare(strict_types=1);

const P_BAUM = 1;          // erster erfolgreicher Scan eines Baums
const P_BEZIRK = 10;       // erster Baum in einem neuen Bezirk (zusätzlich)
const P_ERSTER = 3;        // erster Spieler an einem Baum
const ERSTER_TAGE = 30;    // danach wird der Erster-Status frei
const P_MELDUNG = 5;       // Plakette fehlt/unlesbar, vorläufig
const MELDUNG_WERKTAGE = 3;
const P_WIDERLEGT = -10;   // jemand scannt die "fehlende" Plakette doch erfolgreich

// ---------- Werktage (Mo–Fr ohne österreichische Feiertage) ----------

function ostersonntag(int $j): int {
    $a = $j % 19; $b = intdiv($j, 100); $c = $j % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $monat = intdiv($h + $l - 7 * $m + 114, 31); $tag = (($h + $l - 7 * $m + 114) % 31) + 1;
    return mktime(12, 0, 0, $monat, $tag, $j);
}

function feiertag_at(int $ts): bool {
    $j = (int)date('Y', $ts);
    $fix = ['01-01', '01-06', '05-01', '08-15', '10-26', '11-01', '12-08', '12-25', '12-26'];
    if (in_array(date('m-d', $ts), $fix, true)) return true;
    $o = ostersonntag($j);
    foreach ([1, 39, 50, 60] as $plus) { // Ostermontag, Christi Himmelfahrt, Pfingstmontag, Fronleichnam
        if (date('Y-m-d', $ts) === date('Y-m-d', $o + $plus * 86400)) return true;
    }
    return false;
}

function plus_werktage(int $ts, int $n): int {
    while ($n > 0) {
        $ts = strtotime('+1 day', $ts);
        if ((int)date('N', $ts) <= 5 && !feiertag_at($ts)) $n--;
    }
    return $ts;
}

// ---------- Foto für Plan B ----------

function foto_speichern(string $feld, string $ordner): ?string {
    $f = $_FILES[$feld] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
    if ($f['size'] > 8 * 1024 * 1024) fehler('foto_gross', 'Das Foto ist zu groß (max. 8 MB).');
    $info = @getimagesize($f['tmp_name']);
    $endung = match ($info[2] ?? 0) { IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', default => null };
    if (!$endung) fehler('foto_typ', 'Bitte ein Foto als JPG, PNG oder WebP senden.');
    $name = date('Ymd') . '_' . zufall(8) . '.' . $endung;
    if (!move_uploaded_file($f['tmp_name'], daten_dir($ordner) . '/' . $name)) fehler('foto_speichern', 'Foto konnte nicht gespeichert werden.', 500);
    return $name;
}

// ---------- Besuch ----------

/**
 * POST besuch (multipart oder JSON)
 *   baum_id, methode = ocr | planb, grund = fehlt | unlesbar | zu_hoch (nur planb), lat, lon, foto (Datei, Pflicht bei planb)
 */
function ep_besuch(): never {
    $s = muss_angemeldet();
    $sid = (int)$s['id'];
    bremse('besuch:' . $sid, 120, 60);

    $baumId = (int)feld('baum_id', 12);
    $methode = feld('methode', 10);
    $grund = feld('grund', 10) ?: null;
    $lat = (float)feld('lat', 20);
    $lon = (float)feld('lon', 20);
    if (!in_array($methode, ['ocr', 'planb'], true)) fehler('methode', 'Unbekannte Methode.');
    if ($methode === 'planb' && !in_array($grund, ['fehlt', 'unlesbar', 'zu_hoch'], true)) fehler('grund', 'Bitte einen Grund angeben.');
    if ($methode === 'ocr') $grund = null;
    if (!$lat || !$lon) fehler('standort', 'Ohne Standort kann der Besuch nicht gewertet werden.');

    $baum = baum_holen($baumId) ?? fehler('baum_unbekannt', 'Diesen Baum gibt es im Baumkataster nicht.', 404);
    if ($baum['entfernt_seit']) fehler('baum_entfernt', 'Dieser Baum steht laut Kataster nicht mehr.', 410);
    if (!zugaenglich_fuer($baum, $sid)) {
        fehler('nicht_zugaenglich', 'Dieser Baum steht auf nicht öffentlich zugänglichem Gelände und ist vom Spiel ausgenommen.', 403);
    }

    $abstand = abstand_m($lat, $lon, (float)$baum['lat'], (float)$baum['lon']);
    if ($abstand > (int)cfg('besuch_max_abstand_m')) {
        fehler('zu_weit', 'Du bist zu weit vom Baum entfernt (' . round($abstand) . ' m).', 403, ['abstand_m' => round($abstand)]);
    }
    if (q('SELECT 1 FROM besuche WHERE spieler = ? AND baum = ?', [$sid, $baumId])->fetch()) {
        fehler('schon_besucht', 'Diesen Baum hast du schon besucht.', 409);
    }

    $foto = null;
    if ($methode === 'planb') {
        $foto = foto_speichern('foto', 'meldungen') ?? fehler('foto_fehlt', 'Bei Plan B ist ein Foto Pflicht.');
    }

    $buchungen = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        // Neuer Bezirk? (vor dem Eintragen prüfen)
        $neuerBezirk = !q('SELECT 1 FROM besuche v JOIN baeume b ON b.baum_id = v.baum
                           WHERE v.spieler = ? AND b.bezirk = ? LIMIT 1', [$sid, $baum['bezirk']])->fetch();

        q('INSERT INTO besuche (spieler, baum, methode, grund, lat, lon) VALUES (?, ?, ?, ?, ?, ?)',
            [$sid, $baumId, $methode, $grund, $lat, $lon]);
        $besuchId = (int)$pdo->lastInsertId();

        $buche = function (int $spieler, string $art, int $p, ?string $vorlaeufigBis = null) use ($baumId, $besuchId, $sid, &$buchungen) {
            q('INSERT INTO punkte (spieler, baum, besuch, art, punkte, vorlaeufig_bis) VALUES (?, ?, ?, ?, ?, ?)',
                [$spieler, $baumId, $spieler === $sid ? $besuchId : null, $art, $p, $vorlaeufigBis]);
            if ($spieler === $sid) $buchungen[] = ['art' => $art, 'punkte' => $p, 'vorlaeufig_bis' => $vorlaeufigBis];
        };

        $buche($sid, 'baum', P_BAUM);
        if ($neuerBezirk) $buche($sid, 'bezirk', P_BEZIRK);

        // Erster an diesem Baum? (Zeile sperren, damit zwei gleichzeitige Scans nicht beide +3 bekommen)
        $erster = q('SELECT spieler, seit FROM erster_status WHERE baum = ? FOR UPDATE', [$baumId])->fetch();
        if (!$erster || strtotime($erster['seit']) < time() - ERSTER_TAGE * 86400) {
            q('INSERT INTO erster_status (baum, spieler, seit) VALUES (?, ?, NOW())
               ON DUPLICATE KEY UPDATE spieler = VALUES(spieler), seit = VALUES(seit)', [$baumId, $sid]);
            $buche($sid, 'erster', P_ERSTER);
        }

        if ($methode === 'planb' && $grund !== 'zu_hoch') {
            // Nur die erste offene Meldung pro Baum bekommt den Bonus
            $offen = q("SELECT id FROM meldungen WHERE baum = ? AND status = 'offen' FOR UPDATE", [$baumId])->fetch();
            if (!$offen) {
                $bis = date('Y-m-d H:i:s', plus_werktage(time(), MELDUNG_WERKTAGE));
                q('INSERT INTO meldungen (baum, spieler, grund, foto, vorlaeufig_bis) VALUES (?, ?, ?, ?, ?)',
                    [$baumId, $sid, $grund, $foto, $bis]);
                $buche($sid, 'meldung', P_MELDUNG, $bis);
            }
        }

        if ($methode === 'ocr') {
            // Plakette doch lesbar → offene Meldungen widerlegt: Bonus weg, −10, keine Mail
            $falsch = q("SELECT id, spieler FROM meldungen WHERE baum = ? AND status = 'offen' AND vorlaeufig_bis > NOW() FOR UPDATE", [$baumId])->fetchAll();
            foreach ($falsch as $m) {
                q("UPDATE meldungen SET status = 'widerlegt' WHERE id = ?", [$m['id']]);
                if ($m['spieler']) {
                    q("UPDATE punkte SET storniert = 1 WHERE spieler = ? AND baum = ? AND art = 'meldung' AND storniert = 0", [$m['spieler'], $baumId]);
                    $buche((int)$m['spieler'], 'meldung_widerlegt', P_WIDERLEGT);
                }
            }
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int)($e->errorInfo[1] ?? 0) === 1062) fehler('schon_besucht', 'Diesen Baum hast du schon besucht.', 409);
        throw $e;
    }

    antwort([
        'ok' => true,
        'besuch' => $besuchId,
        'baum' => baum_json($baum),
        'buchungen' => $buchungen,
        'summe' => array_sum(array_column($buchungen, 'punkte')),
        'spieler' => spieler_info($sid),
    ]);
}

function baum_json(array $b): array {
    return [
        'baum_id' => (int)$b['baum_id'], 'nummer' => $b['nummer'], 'art' => $b['art'],
        'bezirk' => (int)$b['bezirk'], 'strasse' => $b['strasse'], 'gebietsgruppe' => $b['gebietsgruppe'] ?? null,
        'lat' => (float)$b['lat'], 'lon' => (float)$b['lon'],
    ];
}

/** GET besuche – eigene Besuche mit Punkten (für „Meine Bäume“ und neues Handy) */
function ep_meine_besuche(): never {
    $s = muss_angemeldet();
    $rows = q('SELECT v.id, v.baum, v.zeitpunkt, v.methode, v.grund, b.nummer, b.art, b.bezirk, b.strasse, b.lat, b.lon,
                      b.entfernt_seit, COALESCE(SUM(CASE WHEN p.storniert = 0 THEN p.punkte END), 0) AS punkte
               FROM besuche v JOIN baeume b ON b.baum_id = v.baum
               LEFT JOIN punkte p ON p.besuch = v.id
               WHERE v.spieler = ? GROUP BY v.id ORDER BY v.zeitpunkt DESC', [$s['id']])->fetchAll();
    foreach ($rows as &$r) {
        $r['baum'] = (int)$r['baum']; $r['bezirk'] = (int)$r['bezirk']; $r['punkte'] = (int)$r['punkte'];
        $r['lat'] = (float)$r['lat']; $r['lon'] = (float)$r['lon']; $r['entfernt'] = $r['entfernt_seit'] !== null;
        unset($r['entfernt_seit']);
    }
    antwort(['ok' => true, 'besuche' => $rows]);
}

/** GET baum?id=… – Status eines Baums (wer ist Erster, offene Meldung) */
function ep_baum(): never {
    $baum = baum_holen((int)($_GET['id'] ?? 0)) ?? fehler('baum_unbekannt', 'Diesen Baum gibt es im Baumkataster nicht.', 404);
    $erster = q('SELECT s.name, e.seit FROM erster_status e JOIN spieler s ON s.id = e.spieler
                 WHERE e.baum = ? AND e.seit > ?', [$baum['baum_id'], jetzt(-ERSTER_TAGE * 86400)])->fetch();
    $besucher = (int)q('SELECT COUNT(*) FROM besuche WHERE baum = ?', [$baum['baum_id']])->fetchColumn();
    $meldung = (bool)q("SELECT 1 FROM meldungen WHERE baum = ? AND status = 'offen'", [$baum['baum_id']])->fetch();
    antwort(['ok' => true, 'baum' => baum_json($baum), 'erster' => $erster ?: null,
             'besucher' => $besucher, 'meldung_offen' => $meldung, 'entfernt' => $baum['entfernt_seit'] !== null,
             'regel_zugaenglich' => regel_zugaenglich($baum),
             'zugang' => $baum['zugang_korrektur'] === null ? null : (bool)$baum['zugang_korrektur'],
             'obst' => $baum['obst_korrektur'] === null ? null : (bool)$baum['obst_korrektur']]);
}

// ---------- Rangliste ----------

/** GET rangliste?zeitraum=gesamt|monat|woche&bezirk=1..23&limit=50 */
function ep_rangliste(): never {
    $zeitraum = $_GET['zeitraum'] ?? 'gesamt';
    $bezirk = (int)($_GET['bezirk'] ?? 0);
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $bed = ['p.storniert = 0', 's.gesperrt = 0'];
    $par = [];
    $von = match ($zeitraum) {
        'monat' => date('Y-m-01 00:00:00'),
        'woche' => date('Y-m-d 00:00:00', strtotime('monday this week')),
        'gesamt' => null,
        default => fehler('zeitraum', 'Zeitraum: gesamt, monat oder woche.'),
    };
    if ($von) { $bed[] = 'p.zeitpunkt >= ?'; $par[] = $von; }
    $join = '';
    if ($bezirk) {
        if ($bezirk < 1 || $bezirk > 23) fehler('bezirk', 'Bezirk 1 bis 23.');
        $join = 'JOIN baeume b ON b.baum_id = p.baum';
        $bed[] = 'b.bezirk = ?'; $par[] = $bezirk;
    }
    $sql = "SELECT s.id, s.name, SUM(p.punkte) AS punkte, SUM(p.art = 'baum') AS baeume, MAX(p.zeitpunkt) AS zuletzt
            FROM punkte p JOIN spieler s ON s.id = p.spieler $join
            WHERE " . implode(' AND ', $bed) . '
            GROUP BY s.id, s.name HAVING SUM(p.punkte) > 0';
    // Gleich viele Punkte → gleicher Rang; Reihenfolge innerhalb: wer früher dort war
    $alle = q("SELECT t.*, RANK() OVER (ORDER BY t.punkte DESC) AS rang FROM ($sql) t ORDER BY t.punkte DESC, t.zuletzt ASC", $par)->fetchAll();

    $ich = aktueller_spieler();
    $meinPlatz = null;
    $liste = [];
    foreach ($alle as $i => $r) {
        $zeile = ['rang' => (int)$r['rang'], 'name' => $r['name'], 'punkte' => (int)$r['punkte'], 'baeume' => (int)$r['baeume']];
        if ($ich && (int)$r['id'] === (int)$ich['id']) { $zeile['ich'] = true; $meinPlatz = $zeile; }
        if ($i < $limit) $liste[] = $zeile;
    }
    antwort(['ok' => true, 'zeitraum' => $zeitraum, 'bezirk' => $bezirk ?: null,
             'spieler_gesamt' => count($alle), 'liste' => $liste, 'ich' => $meinPlatz]);
}
