<?php
// Prüft das ID-Token von „Mit Google anmelden“ (Google Identity Services) ohne Zusatzbibliothek:
// Signatur mit Googles öffentlichen Schlüsseln (PEM), dazu iss, aud und exp.
declare(strict_types=1);

const GOOGLE_CERTS_URL = 'https://www.googleapis.com/oauth2/v1/certs';

function b64url_dec(string $s): string {
    $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    return $d === false ? '' : $d;
}

/** Holt Googles Schlüssel, zwischengespeichert so lange wie Google erlaubt (Cache-Control max-age) */
function google_zertifikate(bool $neu = false): array {
    $datei = daten_dir() . '/google-certs.json';
    if (!$neu && is_file($datei)) {
        $c = json_decode((string)file_get_contents($datei), true);
        if (is_array($c) && ($c['_ablauf'] ?? 0) > time()) return $c['keys'];
    }
    $header = [];
    $body = http_get(GOOGLE_CERTS_URL, 10, $header);
    $keys = $body ? json_decode($body, true) : null;
    if (!is_array($keys) || !$keys) throw new RuntimeException('Google-Zertifikate nicht abrufbar');
    $maxAge = preg_match('/max-age=(\d+)/', $header['cache-control'] ?? '', $m) ? (int)$m[1] : 3600;
    file_put_contents($datei, json_encode(['_ablauf' => time() + $maxAge, 'keys' => $keys]));
    return $keys;
}

/**
 * Gibt die geprüften Angaben zurück (sub, email, email_verified, name, picture)
 * oder wirft eine Exception mit dem Grund.
 */
function google_token_pruefen(string $jwt): array {
    $teile = explode('.', $jwt);
    if (count($teile) !== 3) throw new InvalidArgumentException('Token-Format');
    [$h64, $p64, $s64] = $teile;
    $header = json_decode(b64url_dec($h64), true);
    $daten = json_decode(b64url_dec($p64), true);
    if (!is_array($header) || !is_array($daten)) throw new InvalidArgumentException('Token unlesbar');
    if (($header['alg'] ?? '') !== 'RS256') throw new InvalidArgumentException('Algorithmus');

    $kid = (string)($header['kid'] ?? '');
    $keys = google_zertifikate();
    if (!isset($keys[$kid])) $keys = google_zertifikate(true); // Google hat Schlüssel gewechselt
    if (!isset($keys[$kid])) throw new InvalidArgumentException('Schlüssel unbekannt');

    $pub = openssl_pkey_get_public($keys[$kid]);
    if ($pub === false || openssl_verify("$h64.$p64", b64url_dec($s64), $pub, OPENSSL_ALGO_SHA256) !== 1) {
        throw new InvalidArgumentException('Signatur');
    }
    if (!in_array($daten['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)) {
        throw new InvalidArgumentException('Aussteller');
    }
    if (!in_array($daten['aud'] ?? '', (array)cfg('google_client_ids'), true)) {
        throw new InvalidArgumentException('Client-ID');
    }
    $t = time();
    if (($daten['exp'] ?? 0) < $t - 60) throw new InvalidArgumentException('abgelaufen');
    if (($daten['iat'] ?? 0) > $t + 300) throw new InvalidArgumentException('Zeit');
    if (empty($daten['sub'])) throw new InvalidArgumentException('sub fehlt');

    return [
        'sub' => (string)$daten['sub'],
        'email' => isset($daten['email']) ? mb_strtolower((string)$daten['email']) : null,
        'email_verified' => ($daten['email_verified'] ?? false) === true || ($daten['email_verified'] ?? '') === 'true',
    ];
}
