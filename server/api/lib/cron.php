<?php
// Nächtliche Aufgaben. Aufruf: https://<app>/api/cron?key=<cron_key> (all-inkl: KAS → Tools → Cronjobs)
declare(strict_types=1);

function ep_cron(): never {
    $key = (string)($_GET['key'] ?? '');
    if (strlen((string)cfg('cron_key')) < 20 || !hash_equals((string)cfg('cron_key'), $key)) fehler('verboten', 'Nein.', 403);
    @set_time_limit(max(60, (int)cfg('kataster_sekunden') + 30));
    header('Content-Type: text/plain; charset=utf-8');

    $log = [];
    $log[] = meldungen_abschliessen();
    $log[] = sammelmail_ma42();
    q('DELETE FROM sitzungen WHERE ablauf < NOW()');
    q('DELETE FROM mail_tokens WHERE ablauf < NOW()');
    q('DELETE FROM versuche WHERE zeitpunkt < ?', [jetzt(-86400)]);
    $log[] = 'Aufräumen: erledigt';
    try {
        $log[] = kataster_abgleich();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        protokoll('Kataster: ' . $e->getMessage());
        $log[] = 'Kataster: Fehler, siehe fehler.log';
    }
    echo date('c') . "\n" . implode("\n", $log) . "\n";
    exit;
}

/** Nach 3 Werktagen ohne erfolgreichen Scan: Meldung gilt als bestätigt, Punkte werden endgültig */
function meldungen_abschliessen(): string {
    $ids = q("SELECT id, baum, spieler FROM meldungen WHERE status = 'offen' AND vorlaeufig_bis <= NOW()")->fetchAll();
    foreach ($ids as $m) {
        q("UPDATE meldungen SET status = 'bestaetigt' WHERE id = ?", [$m['id']]);
        if ($m['spieler']) {
            q("UPDATE punkte SET vorlaeufig_bis = NULL WHERE spieler = ? AND baum = ? AND art = 'meldung' AND storniert = 0",
                [$m['spieler'], $m['baum']]);
        }
    }
    return 'Meldungen bestätigt: ' . count($ids);
}

/** Eine gesammelte Mail mit allen bestätigten, noch nicht gesendeten Meldungen */
function sammelmail_ma42(): string {
    $liste = q("SELECT m.id, m.grund, m.foto, m.erstellt, b.baum_id, b.nummer, b.art, b.bezirk, b.strasse, b.lat, b.lon
                FROM meldungen m JOIN baeume b ON b.baum_id = m.baum
                WHERE m.status = 'bestaetigt' AND m.mail_gesendet IS NULL ORDER BY b.bezirk, b.strasse LIMIT 50")->fetchAll();
    if (!$liste) return 'Sammel-Mail: nichts zu senden';

    $an = (string)cfg('ma42_mail') ?: (string)cfg('mail_admin');
    $test = !cfg('ma42_mail');
    $text = ($test ? "[TESTBETRIEB – diese Mail ginge sonst an die Wiener Stadtgärten]\n\n" : '')
        . "Sehr geehrte Damen und Herren,\n\n"
        . "Spielerinnen und Spieler der App „Tree and me“ haben bei folgenden Bäumen eine fehlende oder unlesbare Baumplakette gemeldet. "
        . "Fotos sind angehängt.\n\n";
    $anhaenge = [];
    foreach ($liste as $i => $m) {
        $n = $i + 1;
        $text .= "$n) Baum Nr. {$m['nummer']} – {$m['art']}\n"
            . "   {$m['strasse']}, {$m['bezirk']}. Bezirk (Kataster BAUM_ID {$m['baum_id']})\n"
            . '   Plakette ' . ($m['grund'] === 'fehlt' ? 'fehlt' : 'nicht lesbar') . ', gemeldet am ' . date('d.m.Y', strtotime($m['erstellt'])) . "\n"
            . "   Karte: https://www.openstreetmap.org/?mlat={$m['lat']}&mlon={$m['lon']}#map=19/{$m['lat']}/{$m['lon']}\n\n";
        if ($m['foto']) $anhaenge["baum-$n-nr-{$m['nummer']}." . pathinfo($m['foto'], PATHINFO_EXTENSION)] = daten_dir('meldungen') . '/' . $m['foto'];
    }
    $text .= "Mit freundlichen Grüßen\nTree and me\n" . app_link() . "\n";

    if (!mail_senden($an, 'Fehlende Baumplaketten (' . count($liste) . ' Bäume)', $text, $anhaenge)) {
        return 'Sammel-Mail: Versand fehlgeschlagen';
    }
    $ids = array_column($liste, 'id');
    q('UPDATE meldungen SET mail_gesendet = NOW() WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
    return 'Sammel-Mail: ' . count($liste) . " Meldungen an $an";
}
