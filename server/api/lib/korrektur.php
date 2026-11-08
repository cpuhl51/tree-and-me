<?php
// Korrekturen durch Spieler (Vorgabe: docs/korrekturen.md) und gemeinsame Regeldatei regeln.json
declare(strict_types=1);

const KORREKTUR_MAX_PRO_TAG = 20;
const MIN_STIMMEN = 2;          // mindestens 2 verschiedene Spieler
const OCR_BEWEIS_TAGE = 90;     // ein OCR-Scan in dieser Zeit verhindert "nicht zugänglich für alle"

/** regeln.json – liegt im App-Ordner (eine Ebene über api/), im Repo im Hauptordner */
function regeln(): array {
    static $r = null;
    if ($r !== null) return $r;
    $kandidaten = array_filter([cfg('regeln_datei'), dirname(__DIR__, 2) . '/regeln.json', dirname(__DIR__, 3) . '/regeln.json']);
    foreach ($kandidaten as $f) {
        if (is_file($f) && is_array($j = json_decode((string)file_get_contents($f), true))) return $r = $j;
    }
    protokoll('regeln.json nicht gefunden – alle Bäume gelten als zugänglich');
    return $r = ['nicht_zugaenglich' => []];
}

/** Einschätzung aus regeln.json */
function regel_zugaenglich(array $baum): bool {
    return !in_array((string)($baum['gebietsgruppe'] ?? ''), regeln()['nicht_zugaenglich'] ?? [], true);
}

/** Rangfolge: eigene Meldung > für alle übernommene Korrektur > regeln.json */
function zugaenglich_fuer(array $baum, int $spielerId): bool {
    $eigene = q("SELECT wert FROM korrekturen WHERE spieler_id = ? AND baum_id = ? AND art = 'zugang' AND wert IS NOT NULL",
        [$spielerId, $baum['baum_id']])->fetchColumn();
    if ($eigene !== false) return (bool)$eigene;
    if ($baum['zugang_korrektur'] !== null) return (bool)$baum['zugang_korrektur'];
    return regel_zugaenglich($baum);
}

/** POST korrektur {baum_id, art: zugang|obst, wert: true|false|null, lat, lon, zeit} */
function ep_korrektur(): never {
    $s = muss_angemeldet();
    $sid = (int)$s['id'];
    $e = eingabe();
    $art = feld('art', 10);
    if (!in_array($art, ['zugang', 'obst'], true)) fehler('art', 'Unbekannte Art der Korrektur.');
    if (!array_key_exists('wert', $e) || !in_array($e['wert'], [true, false, null], true)) fehler('wert', 'Wert muss true, false oder null sein.');
    $wert = $e['wert'] === null ? null : (int)$e['wert'];
    $lat = is_numeric($e['lat'] ?? null) ? (float)$e['lat'] : null;
    $lon = is_numeric($e['lon'] ?? null) ? (float)$e['lon'] : null;

    $baum = baum_holen((int)feld('baum_id', 12)) ?? fehler('baum_unbekannt', 'Diesen Baum gibt es im Baumkataster nicht.', 404);
    if ($lat === null || $lon === null) fehler('zu_weit_weg', 'Ohne Standort kann die Meldung nicht angenommen werden.', 403);
    $abstand = abstand_m($lat, $lon, (float)$baum['lat'], (float)$baum['lon']);
    if ($abstand > (int)cfg('besuch_max_abstand_m')) {
        fehler('zu_weit_weg', 'Du warst bei der Meldung zu weit vom Baum entfernt (' . round($abstand) . ' m).', 403, ['abstand_m' => round($abstand)]);
    }

    // höchstens 20 pro Spieler und Kalendertag
    $heute = (int)q('SELECT COUNT(*) FROM versuche WHERE schluessel = ? AND zeitpunkt >= ?', ['korr:' . $sid, date('Y-m-d 00:00:00')])->fetchColumn();
    if ($heute >= KORREKTUR_MAX_PRO_TAG) fehler('zu_viele_meldungen', 'Heute sind keine weiteren Meldungen mehr möglich (max. 20 pro Tag).', 429);
    q('INSERT INTO versuche (schluessel) VALUES (?)', ['korr:' . $sid]);

    // neuere Meldung ersetzt ältere
    q('INSERT INTO korrekturen (spieler_id, baum_id, art, wert, lat, lon, erstellt) VALUES (?, ?, ?, ?, ?, ?, NOW())
       ON DUPLICATE KEY UPDATE wert = VALUES(wert), lat = VALUES(lat), lon = VALUES(lon), erstellt = VALUES(erstellt)',
        [$sid, $baum['baum_id'], $art, $wert, $lat, $lon]);
    antwort(['ok' => true]);
}

/** GET korrekturen?bbox=west,süd,ost,nord – nur die für alle übernommenen */
function ep_korrekturen(): never {
    $b = array_map('trim', explode(',', (string)($_GET['bbox'] ?? '')));
    if (count($b) !== 4 || array_filter($b, fn($v) => !is_numeric($v))) fehler('bbox', 'bbox=west,süd,ost,nord');
    [$w, $sd, $o, $n] = array_map('floatval', $b);
    if ($w >= $o || $sd >= $n || $o - $w > 0.5 || $n - $sd > 0.5) fehler('bbox', 'Kartenausschnitt ungültig oder zu groß.');
    $rows = q('SELECT baum_id, zugang_korrektur, obst_korrektur FROM baeume
               WHERE lat BETWEEN ? AND ? AND lon BETWEEN ? AND ?
                 AND (zugang_korrektur IS NOT NULL OR obst_korrektur IS NOT NULL) LIMIT 5000', [$sd, $n, $w, $o])->fetchAll();
    $bool = fn($v) => $v === null ? null : (bool)$v;
    antwort(['ok' => true, 'baeume' => array_map(fn($r) => [
        'baum_id' => (int)$r['baum_id'], 'zugang' => $bool($r['zugang_korrektur']), 'obst' => $bool($r['obst_korrektur']),
    ], $rows)]);
}

/**
 * Nachts: Stimmen auswerten und für alle übernehmen. Gibt die Änderungen zurück (für die Tagesmail).
 * zugang = 1, wenn ein OCR-Scan den Zugang beweist oder ≥2 Spieler mit Mehrheit „zugänglich“ melden;
 * zugang = 0, wenn ≥2 Spieler mit Mehrheit „nicht zugänglich“ melden und kein OCR-Scan in 90 Tagen;
 * obst: ≥2 Spieler, Mehrheit. Sonst NULL (regeln.json gilt).
 */
function korrekturen_auswerten(): array {
    $geschlossen = regeln()['nicht_zugaenglich'] ?? [];
    $in = $geschlossen ? implode(',', array_fill(0, count($geschlossen), '?')) : "''";

    // Kandidaten: Bäume mit Stimmen, mit bestehender Korrektur, oder „laut Regel geschlossen“ mit OCR-Scan
    $baeume = q("SELECT b.* FROM baeume b WHERE b.baum_id IN (
                   SELECT k.baum_id FROM korrekturen k
                   UNION SELECT baum_id FROM baeume WHERE zugang_korrektur IS NOT NULL OR obst_korrektur IS NOT NULL
                   UNION SELECT v.baum FROM besuche v JOIN baeume x ON x.baum_id = v.baum
                         WHERE v.methode = 'ocr' AND x.gebietsgruppe IN ($in))", $geschlossen)->fetchAll();

    $aenderungen = [];
    foreach ($baeume as $b) {
        $st = q("SELECT k.art, SUM(k.wert = 1) AS ja, SUM(k.wert = 0) AS nein FROM korrekturen k
                 JOIN spieler s ON s.id = k.spieler_id AND s.gesperrt = 0
                 WHERE k.baum_id = ? AND k.wert IS NOT NULL GROUP BY k.art", [$b['baum_id']])->fetchAll(PDO::FETCH_UNIQUE);
        $ocr = q("SELECT MAX(zeitpunkt) FROM besuche WHERE baum = ? AND methode = 'ocr'", [$b['baum_id']])->fetchColumn();
        $ocrNeu = $ocr && strtotime($ocr) > time() - OCR_BEWEIS_TAGE * 86400;

        $zJa = (int)($st['zugang']['ja'] ?? 0); $zNein = (int)($st['zugang']['nein'] ?? 0);
        $zugang = null; $grund = '';
        if ($zNein >= MIN_STIMMEN && $zNein > $zJa && !$ocrNeu) { $zugang = 0; $grund = "{$zNein}× nicht zugänglich, {$zJa}× zugänglich"; }
        // OCR-Scan beweist den Zugang (nur nötig, wo Regel oder Korrektur etwas anderes sagt)
        elseif ($ocr && (!regel_zugaenglich($b) || $b['zugang_korrektur'] !== null)) { $zugang = 1; $grund = 'Plakette erfolgreich gescannt'; }
        elseif ($zJa >= MIN_STIMMEN && $zJa > $zNein) { $zugang = 1; $grund = "{$zJa}× zugänglich, {$zNein}× nicht zugänglich"; }

        $oJa = (int)($st['obst']['ja'] ?? 0); $oNein = (int)($st['obst']['nein'] ?? 0);
        $obst = ($oJa + $oNein >= MIN_STIMMEN && $oJa !== $oNein) ? (int)($oJa > $oNein) : null;

        $alt = [$b['zugang_korrektur'] === null ? null : (int)$b['zugang_korrektur'], $b['obst_korrektur'] === null ? null : (int)$b['obst_korrektur']];
        if ($alt === [$zugang, $obst]) continue;
        q('UPDATE baeume SET zugang_korrektur = ?, obst_korrektur = ?, korrektur_seit = NOW() WHERE baum_id = ?', [$zugang, $obst, $b['baum_id']]);
        $aenderungen[] = $b + ['neu_zugang' => $zugang, 'neu_obst' => $obst, 'alt_zugang' => $alt[0], 'alt_obst' => $alt[1],
                               'grund_zugang' => $grund, 'obst_stimmen' => "{$oJa}× ja, {$oNein}× nein"];
    }
    return $aenderungen;
}

/** Tägliche Übersicht an Christoph (mail_admin), nur wenn sich etwas geändert hat */
function korrekturen_mail(array $aend): string {
    if (!$aend) return 'Korrekturen: keine Änderungen';
    $w = fn($v) => $v === null ? 'Regel' : ($v ? 'ja' : 'nein');
    $text = "Übernommene Korrekturen vom " . date('d.m.Y') . " (" . count($aend) . " Bäume)\n\n";
    $gruppen = [];
    foreach ($aend as $a) {
        $text .= "• Nr. {$a['nummer']} {$a['art']} – {$a['strasse']}, {$a['bezirk']}. Bezirk (BAUM_ID {$a['baum_id']})\n"
            . '  Gebietsgruppe: ' . ($a['gebietsgruppe'] ?: '–') . ' · laut regeln.json ' . (regel_zugaenglich($a) ? 'zugänglich' : 'NICHT zugänglich') . "\n";
        if ($a['alt_zugang'] !== $a['neu_zugang']) $text .= "  Zugänglich: {$w($a['alt_zugang'])} → {$w($a['neu_zugang'])}" . ($a['grund_zugang'] ? " ({$a['grund_zugang']})" : '') . "\n";
        if ($a['alt_obst'] !== $a['neu_obst']) $text .= "  Essbare Früchte: {$w($a['alt_obst'])} → {$w($a['neu_obst'])} ({$a['obst_stimmen']})\n";
        $text .= "  https://www.openstreetmap.org/?mlat={$a['lat']}&mlon={$a['lon']}#map=19/{$a['lat']}/{$a['lon']}\n\n";
        if ($a['alt_zugang'] !== $a['neu_zugang']) {
            $g = ($a['gebietsgruppe'] ?: '–') . ' → ' . $w($a['neu_zugang']);
            $gruppen[$g] = ($gruppen[$g] ?? 0) + 1;
        }
    }
    if ($gruppen) {
        arsort($gruppen);
        $text .= "Zugang nach Gebietsgruppe (Hinweis für regeln.json):\n";
        foreach ($gruppen as $g => $n) $text .= "  {$n}× $g\n";
    }
    mail_senden((string)cfg('mail_admin'), 'Tree and me: ' . count($aend) . ' Korrekturen übernommen', $text);
    return 'Korrekturen: ' . count($aend) . ' übernommen, Mail an ' . cfg('mail_admin');
}
