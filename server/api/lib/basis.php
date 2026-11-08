<?php
// Grundlagen: Konfiguration, Datenbank, JSON-Antworten, Schutz gegen Missbrauch
declare(strict_types=1);

date_default_timezone_set('Europe/Vienna');

function cfg(?string $key = null): mixed {
    static $c = null;
    if ($c === null) {
        // Bevorzugt eine Datei außerhalb des Web-Ordners, sonst config.php neben index.php
        // lib/ → api/ → Web-Ordner → darüber
        foreach ([dirname(__DIR__, 3) . '/tree-and-me-config.php', dirname(__DIR__) . '/config.php'] as $f) {
            if (is_file($f)) { $c = require $f; break; }
        }
        if (!is_array($c)) { http_response_code(500); exit('{"fehler":"config_fehlt"}'); }
        $c += [
            'daten_dir' => dirname(__DIR__) . '/daten',
            'besuch_max_abstand_m' => 100,
            'cookie_name' => 'tam_sitzung',
            'sitzung_tage' => 90,
            'ma42_mail' => '',
            'kataster_sekunden' => 25,
            'wfs' => 'https://data.wien.gv.at/daten/geo',
        ];
    }
    return $key === null ? $c : ($c[$key] ?? null);
}

function db(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $d = cfg('db');
        $pdo = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        // Datenbank rechnet in Wiener Zeit (Monatsgrenzen der Rangliste)
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $p = []): PDOStatement {
    $st = db()->prepare($sql);
    $st->execute($p);
    return $st;
}

function jetzt(int $plusSekunden = 0): string { return date('Y-m-d H:i:s', time() + $plusSekunden); }

function antwort(array $daten, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($daten, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/** Fehler mit maschinenlesbarem Code (für die App) und deutschem Text (zum Anzeigen) */
function fehler(string $code, string $text, int $http = 400, array $extra = []): never {
    antwort(['ok' => false, 'fehler' => $code, 'text' => $text] + $extra, $http);
}

function eingabe(): array {
    static $e = null;
    if ($e === null) {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        if (str_starts_with($ct, 'application/json')) {
            $e = json_decode(file_get_contents('php://input') ?: '[]', true);
            if (!is_array($e)) fehler('json', 'Ungültige Anfrage.');
        } else {
            $e = $_POST; // multipart (mit Foto) oder Formular
        }
    }
    return $e;
}

function feld(string $name, int $maxLen = 500): string {
    $v = eingabe()[$name] ?? '';
    if (!is_string($v) && !is_int($v) && !is_float($v)) return '';
    return mb_substr(trim((string)$v), 0, $maxLen);
}

function ip(): string { return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'; }

/** Schreibende Anfragen nur von der eigenen App-Adresse (Schutz gegen fremde Webseiten) */
function origin_pruefen(): void {
    $erlaubt = rtrim((string)cfg('app_url'), '/');
    $p = parse_url($erlaubt);
    $erlaubtOrigin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin !== $erlaubtOrigin) fehler('origin', 'Anfrage nicht erlaubt.', 403);
}

/** Zählt Versuche pro Schlüssel; zu viele in kurzer Zeit → 429 */
function bremse(string $schluessel, int $max, int $minuten): void {
    $n = (int)q('SELECT COUNT(*) FROM versuche WHERE schluessel = ? AND zeitpunkt > ?',
        [$schluessel, jetzt(-60 * $minuten)])->fetchColumn();
    if ($n >= $max) fehler('zu_viele', 'Zu viele Versuche. Bitte warte ein paar Minuten.', 429);
    q('INSERT INTO versuche (schluessel) VALUES (?)', [$schluessel]);
}

function zufall(int $bytes = 32): string { return bin2hex(random_bytes($bytes)); }

function daten_dir(string $unter = ''): string {
    $d = rtrim((string)cfg('daten_dir'), '/') . ($unter !== '' ? '/' . $unter : '');
    if (!is_dir($d)) mkdir($d, 0750, true);
    return $d;
}

function protokoll(string $text): void {
    @file_put_contents(daten_dir() . '/fehler.log', date('c') . ' ' . $text . "\n", FILE_APPEND);
}

/** Einfacher HTTPS-Abruf (Google-Zertifikate, Baumkataster) */
function http_get(string $url, int $timeout = 15, ?array &$header = null): ?string {
    $header = [];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_USERAGENT => 'TreeAndMe-Server/1.0',
        CURLOPT_HEADERFUNCTION => function ($ch, $zeile) use (&$header) {
            $t = explode(':', $zeile, 2);
            if (count($t) === 2) $header[strtolower(trim($t[0]))] = trim($t[1]);
            return strlen($zeile);
        },
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return ($body !== false && $status === 200) ? $body : null;
}

/** Entfernung in Metern zwischen zwei Koordinaten */
function abstand_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371000;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    return 2 * $r * asin(min(1, sqrt($a)));
}
