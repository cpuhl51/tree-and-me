# Tree and me – Server (all-inkl, PHP + MySQL)

Reines PHP ohne Zusatzbibliotheken. Getestet mit PHP 8.3 und MariaDB 10.11.

```
server/
  schema.sql            Datenbank-Tabellen (einmal in phpMyAdmin ausführen)
  api/                  wird nach  https://<app-domain>/api/  hochgeladen
    index.php           Router, alle Adressen
    .htaccess           leitet /api/… auf index.php, sperrt lib/, daten/, config.php
    config.sample.php   Vorlage für die Zugangsdaten
    lib/                basis, auth, google, mail, kataster, spiel, cron
```

## Einrichten bei all-inkl (einmalig)

1. **Subdomain** anlegen (KAS → Domain → Subdomain), z. B. `baum.deinedomain.at`, eigener Ordner, z. B. `/tree-and-me/`.
   PHP-Version **8.3 oder neuer** einstellen. SSL-Zertifikat (Let's Encrypt) aktivieren.
2. **Datenbank** anlegen (KAS → Datenbanken). In phpMyAdmin den Inhalt von `schema.sql` ausführen.
3. **FTP-Zugang** nur für den App-Ordner anlegen (KAS → FTP → Zusatz-FTP-Benutzer, Verzeichnis `/tree-and-me/`).
4. **Postfach/Adresse** für den Absender, z. B. `noreply@deinedomain.at`.
5. **config.php**: `config.sample.php` kopieren, ausfüllen, als `api/config.php` per FTP hochladen
   (oder besser als `tree-and-me-config.php` eine Ebene über dem App-Ordner – dann ist sie nie über das Web erreichbar).
   `ma42_mail` leer lassen, solange getestet wird: Sammelmails gehen dann nur an `mail_admin`.
6. **GitHub-Secrets** im Repo setzen: `FTP_SERVER`, `FTP_USER`, `FTP_PASSWORD`. Danach lädt jeder Push auf `main` App + API hoch.
7. **Cronjob** (KAS → Tools → Cronjobs): Adresse `https://baum.deinedomain.at/api/cron?key=<cron_key>`.
   Nachts mehrmals aufrufen (z. B. alle 15 Min. zwischen 2 und 4 Uhr). Jeder Aufruf arbeitet ~25 s am Baumkataster
   weiter; ein voller Abgleich (~233.000 Bäume) braucht ca. 10–15 Aufrufe.
8. Test: `https://baum.deinedomain.at/api/status` muss `{"ok":true,…}` zeigen.

## Schnittstelle

Alle Antworten sind JSON: `{"ok":true,…}` oder `{"ok":false,"fehler":"<code>","text":"<deutscher Text>"}`.
Anmeldung über ein HttpOnly-Cookie (`tam_sitzung`, 90 Tage). App und API müssen unter **derselben Domain** laufen.
Schreibende Anfragen (POST) werden nur mit passendem `Origin`-Header angenommen. In der App: `fetch('/api/…', {credentials:'same-origin'})`.

| Methode | Adresse | Eingabe | Zweck |
|---|---|---|---|
| GET | `ich` | – | eigenes Profil oder `spieler:null` |
| POST | `ich` | `name` | Spitzname ändern |
| POST | `ich/loeschen` | `bestaetigung:"LÖSCHEN"` | Konto + Spieldaten löschen (Play-Store-Pflicht) |
| POST | `auth/registrieren` | `name, email, passwort` | schickt Bestätigungsmail |
| GET | `auth/bestaetigen?t=` | – | Link aus der Mail → angemeldet, weiter zu `/#angemeldet` |
| POST | `auth/login` | `email, passwort` | |
| POST | `auth/google` | `credential` (ID-Token aus `google.accounts.id`) | `neu:true` → Namen wählen lassen |
| POST | `auth/logout` | – | |
| POST | `auth/passwort-vergessen` | `email` | Mail mit Link `/#passwort-neu=<token>` |
| POST | `auth/passwort-neu` | `token, passwort` | meldet alle Geräte ab, dieses an |
| POST | `besuch` | `baum_id, methode (ocr/planb), grund (fehlt/unlesbar/zu_hoch), lat, lon, foto` | Punkte vergeben (multipart bei Foto) |
| GET | `besuche` | – | eigene Besuche mit Punkten |
| GET | `baum?id=` | – | Erster, Besucherzahl, offene Meldung |
| GET | `rangliste` | `zeitraum=gesamt/monat/woche`, `bezirk=1..23`, `limit` | Liste + eigener Platz |
| GET | `cron?key=` | – | nächtliche Aufgaben |
| GET | `status` | – | Erreichbarkeit + Datenbank |

## Spielregeln im Code (`lib/spiel.php`)

- Besuch nur, wenn der Standort höchstens 100 m vom Baum entfernt ist (`besuch_max_abstand_m`).
- 1 Punkt pro Baum, nur einmal pro Spieler. +10 für den ersten Baum in einem neuen Bezirk.
- +3 für den Ersten an einem Baum; 30 Tage nach dessen Scan wird der Status frei und der nächste bekommt wieder +3.
- Plan B *fehlt/unlesbar*: Foto Pflicht, +5 vorläufig bis 3 Werktage später (Mo–Fr ohne österr. Feiertage).
  Nur die erste offene Meldung pro Baum bekommt die +5.
  Scannt in der Zeit jemand die Plakette: +5 storniert, −10, keine Mail. Sonst bestätigt der Cronjob die Meldung
  und schickt eine Sammelmail mit Fotos.
- Plan B *zu hoch*: Foto Pflicht, normale Punkte, keine Meldung.
- Bäume lädt der Server beim ersten Scan einzeln aus dem Kataster nach; der nächtliche Abgleich markiert entfernte
  Bäume (Punkte bleiben).
- Punkte stehen als Buchungen in `punkte` – nachvollziehbar und korrigierbar (`art='korrektur'`).
