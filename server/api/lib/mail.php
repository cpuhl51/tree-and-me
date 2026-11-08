<?php
// Mails über PHP mail() (bei all-inkl vorhanden). Text-Mails, optional mit Anhängen.
declare(strict_types=1);

function mail_senden(string $an, string $betreff, string $text, array $anhaenge = []): bool {
    $von = (string)cfg('mail_absender');
    $kopf = [
        'From' => 'Tree and me <' . $von . '>',
        'MIME-Version' => '1.0',
        'Auto-Submitted' => 'auto-generated',
    ];
    if (!$anhaenge) {
        $kopf['Content-Type'] = 'text/plain; charset=UTF-8';
        $kopf['Content-Transfer-Encoding'] = 'base64';
        $inhalt = chunk_split(base64_encode($text));
    } else {
        $grenze = 'tam_' . zufall(12);
        $kopf['Content-Type'] = 'multipart/mixed; boundary="' . $grenze . '"';
        $inhalt = "--$grenze\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text));
        foreach ($anhaenge as $name => $pfad) {
            if (!is_file($pfad)) continue;
            $typ = mime_content_type($pfad) ?: 'application/octet-stream';
            $inhalt .= "--$grenze\r\nContent-Type: $typ; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\n"
                . "Content-Disposition: attachment; filename=\"$name\"\r\n\r\n"
                . chunk_split(base64_encode((string)file_get_contents($pfad)));
        }
        $inhalt .= "--$grenze--\r\n";
    }
    $ok = mail($an, mb_encode_mimeheader($betreff, 'UTF-8'), $inhalt, $kopf, '-f' . $von);
    if (!$ok) protokoll("Mail an $an fehlgeschlagen: $betreff");
    return $ok;
}

function app_link(string $anker = ''): string {
    return rtrim((string)cfg('app_url'), '/') . '/' . $anker;
}

function api_link(string $pfad): string {
    return rtrim((string)cfg('app_url'), '/') . '/api/' . ltrim($pfad, '/');
}
