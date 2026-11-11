# Tree and me – Android-Widget (Prototyp 0.1)

Startbildschirm-Widget: Antippen → Standort holen → Bäume im Umkreis von ca. 250 m aus dem Wiener Baumkataster laden → als Karte anzeigen.

- Native Android-App in Java, ohne Zusatzbibliotheken (minSdk 26 = Android 8).
- Antippen startet einen Vordergrunddienst vom Typ `location`. Android erlaubt den Standortzugriff, wenn der Dienst durch Interaktion mit einem Widget gestartet wird.
- Karte: OpenStreetMap-Kacheln (Zoom 17, 7 Tage zwischengespeichert, eigener User-Agent), Bäume per WFS `ogdwien:BAUMKATOGD`.
- Farben wie in der App: hellgrün Laub, dunkelgrün Nadel, rot essbares Obst, grau nicht zugänglich (Regeln aus `../regeln.json`, wird beim Bauen kopiert).
- Besuchte Bäume kennt das Widget noch nicht – die liegen in der Web-App. Kommt mit dem Server (dann per Schnittstelle) oder wenn das Widget in die Play-Store-App integriert wird.

Bauen: `gradle assembleDebug` (Android SDK 35, `local.properties` mit `sdk.dir`).
Für den Play Store wird das Widget in die TWA-Hülle der App übernommen (gleiche App, gleicher Paketname).
