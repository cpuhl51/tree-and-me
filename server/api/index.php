<?php
// Tree and me – Schnittstelle. Alle Anfragen unter /api/… landen hier (siehe .htaccess).
declare(strict_types=1);

require __DIR__ . '/lib/basis.php';
require __DIR__ . '/lib/mail.php';
require __DIR__ . '/lib/google.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/kataster.php';
require __DIR__ . '/lib/spiel.php';
require __DIR__ . '/lib/korrektur.php';
require __DIR__ . '/lib/cron.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: same-origin');

set_exception_handler(function (Throwable $e) {
    if (db_offen() && db()->inTransaction()) db()->rollBack();
    protokoll(get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    fehler('server', 'Da ist am Server etwas schiefgegangen. Bitte später nochmal versuchen.', 500);
});
function db_offen(): bool { try { db(); return true; } catch (Throwable) { return false; } }

$basis = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
$pfad = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$pfad = trim(substr($pfad, strlen($basis)), '/');
$methode = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$routen = [
    'GET ich' => 'ep_ich',
    'POST ich' => 'ep_ich_aendern',
    'POST ich/loeschen' => 'ep_konto_loeschen',
    'POST auth/registrieren' => 'ep_registrieren',
    'GET auth/bestaetigen' => 'ep_bestaetigen',
    'POST auth/login' => 'ep_login',
    'POST auth/google' => 'ep_google',
    'POST auth/logout' => 'ep_logout',
    'POST auth/passwort-vergessen' => 'ep_passwort_vergessen',
    'POST auth/passwort-neu' => 'ep_passwort_neu',
    'POST besuch' => 'ep_besuch',
    'GET besuche' => 'ep_meine_besuche',
    'GET baum' => 'ep_baum',
    'GET rangliste' => 'ep_rangliste',
    'POST korrektur' => 'ep_korrektur',
    'GET korrekturen' => 'ep_korrekturen',
    'GET cron' => 'ep_cron',
    'GET status' => 'ep_status',
];

function ep_status(): never {
    q('SELECT 1');
    antwort(['ok' => true, 'version' => '1.0', 'zeit' => date('c')]);
}

$fn = $routen["$methode $pfad"] ?? null;
if ($fn === null) fehler('unbekannt', 'Unbekannte Adresse.', 404);
if ($methode !== 'GET') origin_pruefen();
$fn();
