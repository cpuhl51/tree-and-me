# Korrekturen durch Spieler – Vorgabe für den Server

Die App lässt Spieler zwei Einschätzungen korrigieren, die aus `regeln.json` stammen:

| art | Bedeutung | wert |
| --- | --- | --- |
| `zugang` | Ist der Baum öffentlich zugänglich? | `true` / `false` / `null` (= eigene Meldung zurückgenommen) |
| `obst` | Trägt der Baum essbare Früchte? | `true` / `false` / `null` |

## Verhalten in der App (umgesetzt, v0.18)
- Grauer Baum → Knopf **„Baum ist doch zugänglich – melden und scannen“** → gilt sofort für diesen Spieler, Scan wird möglich.
- Spielbarer Baum → „Plakette geht nicht“ → **„Baum nicht zugänglich“** → kein Foto, keine Punkte, Baum wird für diesen Spieler grau.
- Baumfenster → **„Früchte falsch eingeordnet?“** → essbar ja/nein.
- Jede Meldung wird lokal gespeichert (`state.korrekturen[key].queue`) und an `CFG.api + 'korrektur'` geschickt, sobald `CFG.api` gesetzt ist und das Handy online ist. Bis dahin bleibt sie vorgemerkt.
- Rangfolge in der App: eigene Meldung > vom Server bestätigte Korrektur > `regeln.json`.

## Schnittstelle
### `POST /api/korrektur` (angemeldet)
```json
{ "baum_id": 385623, "art": "zugang", "wert": true, "lat": 48.24, "lon": 16.48, "zeit": "2026-11-08T11:20:00Z" }
```
Antwort: `{ "ok": true }`. Fehler wie üblich `{ ok:false, fehler, text }`, z. B. `zu_weit_weg`, `zu_viele_meldungen`.

### `GET /api/korrekturen?bbox=west,süd,ost,nord`
Liefert die **für alle übernommenen** Korrekturen im Kartenausschnitt:
```json
{ "ok": true, "baeume": [ { "baum_id": 385623, "zugang": true, "obst": null } ] }
```

## Datenbank
```sql
CREATE TABLE korrekturen (
  id INT AUTO_INCREMENT PRIMARY KEY,
  spieler_id INT NOT NULL,
  baum_id INT NOT NULL,
  art ENUM('zugang','obst') NOT NULL,
  wert TINYINT(1) NULL,              -- NULL = zurückgenommen
  lat DOUBLE NULL, lon DOUBLE NULL,
  erstellt DATETIME NOT NULL,
  UNIQUE KEY eine_meinung (spieler_id, baum_id, art)  -- neuere Meldung ersetzt ältere (Upsert)
);
ALTER TABLE baeume
  ADD gebietsgruppe VARCHAR(80) NULL,
  ADD zugang_korrektur TINYINT(1) NULL,  -- NULL = Einschätzung aus regeln.json gilt
  ADD obst_korrektur TINYINT(1) NULL,
  ADD korrektur_seit DATETIME NULL;
```

## Regeln
**Sofort (beim Eintreffen):**
- Meldung zählt nur, wenn `lat/lon` höchstens 100 m vom Baum entfernt sind (wie bei Besuchen).
- Höchstens 20 Korrekturen pro Spieler und Tag.
- Ein Besuch an einem Baum, der laut `regeln.json` nicht zugänglich ist, wird **angenommen**, wenn derselbe Spieler `zugang = true` gemeldet hat – oder die Korrektur für alle gilt.
- Meldet ein Spieler `zugang = false`, kann er dort keinen Besuch mehr werten.

**Nachts (Cronjob):**
- `zugang` wird für alle **zugänglich**, wenn (a) jemand an diesem Baum erfolgreich per Plakette (`methode = ocr`) gescannt hat – das beweist den Zugang – oder (b) mindestens 2 verschiedene Spieler `true` gemeldet haben und es mehr `true` als `false` gibt.
- `zugang` wird für alle **nicht zugänglich**, wenn mindestens 2 verschiedene Spieler `false` gemeldet haben und es mehr `false` als `true` gibt (und kein OCR-Scan in den letzten 90 Tagen).
- `obst`: mindestens 2 verschiedene Spieler, Mehrheit entscheidet.
- Punkte bereits gewerteter Besuche bleiben immer erhalten.
- Übersicht der übernommenen Korrekturen täglich an Christoph mailen (damit `regeln.json` bei Mustern angepasst werden kann, z. B. ganze Gebietsgruppen).
