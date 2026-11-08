<?php
// Baumkataster der Stadt Wien (WFS ogdwien:BAUMKATOGD, CC BY 4.0)
declare(strict_types=1);

function wfs_url(array $p): string {
    return cfg('wfs') . '?' . http_build_query($p + [
        'service' => 'WFS', 'request' => 'GetFeature', 'version' => '2.0.0',
        'typeNames' => 'ogdwien:BAUMKATOGD', 'srsName' => 'EPSG:4326', 'outputFormat' => 'json',
    ]);
}

function baum_aus_feature(array $f): ?array {
    $p = $f['properties'] ?? [];
    $c = $f['geometry']['coordinates'] ?? null;
    if (empty($p['BAUM_ID']) || !is_array($c) || empty($p['BEZIRK'])) return null;
    return [
        'baum_id' => (int)$p['BAUM_ID'],
        'nummer' => isset($p['BAUMNUMMER']) ? mb_substr((string)$p['BAUMNUMMER'], 0, 20) : null,
        'art' => isset($p['GATTUNG_ART']) ? mb_substr((string)$p['GATTUNG_ART'], 0, 150) : null,
        'bezirk' => (int)$p['BEZIRK'],
        'strasse' => isset($p['OBJEKT_STRASSE']) ? mb_substr((string)$p['OBJEKT_STRASSE'], 0, 150) : null,
        'gebietsgruppe' => isset($p['GEBIETSGRUPPE']) ? mb_substr((string)$p['GEBIETSGRUPPE'], 0, 80) : null,
        'lat' => (float)$c[1],
        'lon' => (float)$c[0],
    ];
}

function baum_speichern(array $b, string $gesehen): void {
    q('INSERT INTO baeume (baum_id, nummer, art, bezirk, strasse, gebietsgruppe, lat, lon, zuletzt_gesehen, entfernt_seit)
       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
       ON DUPLICATE KEY UPDATE nummer = VALUES(nummer), art = VALUES(art), bezirk = VALUES(bezirk),
         strasse = VALUES(strasse), gebietsgruppe = VALUES(gebietsgruppe), lat = VALUES(lat), lon = VALUES(lon),
         zuletzt_gesehen = VALUES(zuletzt_gesehen), entfernt_seit = NULL',
        [$b['baum_id'], $b['nummer'], $b['art'], $b['bezirk'], $b['strasse'], $b['gebietsgruppe'], $b['lat'], $b['lon'], $gesehen]);
}

/** Baum aus der eigenen Tabelle; fehlt er, wird er beim Kataster nachgeschlagen. */
function baum_holen(int $baumId): ?array {
    $b = q('SELECT * FROM baeume WHERE baum_id = ?', [$baumId])->fetch();
    if ($b) return $b;
    $json = http_get(wfs_url(['CQL_FILTER' => 'BAUM_ID=' . $baumId, 'count' => 1]), 10);
    $f = $json ? (json_decode($json, true)['features'][0] ?? null) : null;
    $neu = $f ? baum_aus_feature($f) : null;
    if (!$neu || $neu['baum_id'] !== $baumId) return null;
    baum_speichern($neu, jetzt());
    return q('SELECT * FROM baeume WHERE baum_id = ?', [$baumId])->fetch() ?: null;
}

/**
 * Nächtlicher Abgleich in Etappen: jeder Aufruf arbeitet höchstens kataster_sekunden lang
 * und merkt sich, wo er war. Nach einem vollständigen Durchlauf werden Bäume, die nicht mehr
 * vorkamen, als entfernt markiert (Punkte bleiben).
 */
function kataster_abgleich(): string {
    $status = fn(string $k, ?string $default = null) =>
        q('SELECT wert FROM system_status WHERE schluessel = ?', [$k])->fetchColumn() ?: $default;
    $setze = fn(string $k, string $v) =>
        q('INSERT INTO system_status (schluessel, wert) VALUES (?, ?) ON DUPLICATE KEY UPDATE wert = VALUES(wert)', [$k, $v]);

    $start = $status('kataster_lauf_start');
    $letzterFertig = $status('kataster_fertig', '2000-01-01 00:00:00');
    if ($start === null) {
        // Neuer Durchlauf höchstens alle 20 Stunden
        if (strtotime($letzterFertig) > time() - 20 * 3600) return 'Kataster: aktuell (letzter Abgleich ' . $letzterFertig . ')';
        $start = jetzt();
        $setze('kataster_lauf_start', $start);
        $setze('kataster_index', '0');
        $setze('kataster_gesehen', '0');
    }
    $index = (int)$status('kataster_index', '0');
    $gesehen = (int)$status('kataster_gesehen', '0');
    $ende = time() + (int)cfg('kataster_sekunden');
    $seite = 2000;
    $gesamt = null;

    while (time() < $ende) {
        $json = http_get(wfs_url(['count' => $seite, 'startIndex' => $index, 'sortBy' => 'OBJECTID']), 60);
        $d = $json ? json_decode($json, true) : null;
        if (!is_array($d) || !isset($d['features'])) return "Kataster: Abruf bei Index $index fehlgeschlagen, nächster Versuch beim nächsten Aufruf";
        $gesamt = (int)($d['numberMatched'] ?? $d['totalFeatures'] ?? 0);
        db()->beginTransaction();
        foreach ($d['features'] as $f) {
            if ($b = baum_aus_feature($f)) { baum_speichern($b, $start); $gesehen++; }
        }
        db()->commit();
        $index += count($d['features']);
        $setze('kataster_index', (string)$index);
        $setze('kataster_gesehen', (string)$gesehen);
        if (count($d['features']) < $seite) {
            // Durchlauf fertig. Nur wenn fast alles ankam, gelten fehlende Bäume als entfernt.
            $entfernt = 0;
            if ($gesamt > 0 && $gesehen >= 0.95 * $gesamt) {
                $entfernt = q('UPDATE baeume SET entfernt_seit = NOW()
                               WHERE entfernt_seit IS NULL AND zuletzt_gesehen < ?', [$start])->rowCount();
            }
            q("DELETE FROM system_status WHERE schluessel IN ('kataster_lauf_start','kataster_index','kataster_gesehen')");
            $setze('kataster_fertig', jetzt());
            return "Kataster: fertig, $gesehen Bäume, $entfernt als entfernt markiert";
        }
    }
    return "Kataster: Etappe bis Index $index von " . ($gesamt ?? '?') . ', geht beim nächsten Aufruf weiter';
}
