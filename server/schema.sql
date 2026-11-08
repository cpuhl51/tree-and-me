-- Tree and me – Datenbank (MySQL 8 / MariaDB 10.6+)
-- Einmalig in phpMyAdmin (all-inkl KAS → Datenbanken → phpMyAdmin) ausführen.
-- Zeichensatz utf8mb4 für Umlaute und Emojis in Namen.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS spieler (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(30)  NOT NULL,
  avatar           VARCHAR(100) NULL,               -- Dateiname im Upload-Ordner (später)
  google_sub       VARCHAR(64)  NULL,
  email            VARCHAR(190) NULL,
  passwort_hash    VARCHAR(255) NULL,
  email_bestaetigt TINYINT(1)   NOT NULL DEFAULT 0,
  gesperrt         TINYINT(1)   NOT NULL DEFAULT 0,
  erstellt         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_name (name),
  UNIQUE KEY uq_google (google_sub),
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Hinweis: utf8mb4_unicode_ci macht "Anna" und "anna" gleich → Namen sind ohne Groß/Klein eindeutig.

CREATE TABLE IF NOT EXISTS baeume (
  baum_id         INT UNSIGNED NOT NULL PRIMARY KEY,  -- BAUM_ID aus dem Kataster
  nummer          VARCHAR(20)  NULL,                  -- BAUMNUMMER (Plakette)
  art             VARCHAR(150) NULL,                  -- GATTUNG_ART
  bezirk          TINYINT UNSIGNED NOT NULL,
  strasse         VARCHAR(150) NULL,
  lat             DECIMAL(10,8) NOT NULL,
  lon             DECIMAL(11,8) NOT NULL,
  zuletzt_gesehen DATETIME     NULL,                  -- letzter Abgleich, in dem der Baum vorkam
  entfernt_seit   DATETIME     NULL,
  KEY ix_bezirk (bezirk)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Besuch pro Spieler und Baum (Regel: nur der erste erfolgreiche Scan zählt)
CREATE TABLE IF NOT EXISTS besuche (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler   INT UNSIGNED NOT NULL,
  baum      INT UNSIGNED NOT NULL,
  zeitpunkt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  methode   ENUM('ocr','planb') NOT NULL,
  grund     ENUM('fehlt','unlesbar','zu_hoch') NULL,
  lat       DECIMAL(10,8) NULL,
  lon       DECIMAL(11,8) NULL,
  UNIQUE KEY uq_spieler_baum (spieler, baum),
  KEY ix_baum (baum),
  CONSTRAINT fk_besuch_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE,
  CONSTRAINT fk_besuch_baum    FOREIGN KEY (baum)    REFERENCES baeume(baum_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Punkte als Buchungen: jede Gutschrift/Abzug eine Zeile. Rangliste = Summe.
CREATE TABLE IF NOT EXISTS punkte (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  spieler   INT UNSIGNED NOT NULL,
  baum      INT UNSIGNED NULL,
  besuch    INT UNSIGNED NULL,
  art       ENUM('baum','bezirk','erster','meldung','meldung_widerlegt','korrektur') NOT NULL,
  punkte    SMALLINT     NOT NULL,
  zeitpunkt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vorlaeufig_bis DATETIME NULL,          -- nur bei art='meldung'
  storniert TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_spieler_zeit (spieler, zeitpunkt),
  KEY ix_zeit (zeitpunkt),
  CONSTRAINT fk_punkte_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Wer gerade "Erster" an einem Baum ist (läuft 30 Tage nach "seit" ab)
CREATE TABLE IF NOT EXISTS erster_status (
  baum    INT UNSIGNED NOT NULL PRIMARY KEY,
  spieler INT UNSIGNED NOT NULL,
  seit    DATETIME     NOT NULL,
  CONSTRAINT fk_erster_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS meldungen (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baum          INT UNSIGNED NOT NULL,
  spieler       INT UNSIGNED NULL,
  grund         ENUM('fehlt','unlesbar') NOT NULL,
  foto          VARCHAR(100) NULL,
  status        ENUM('offen','bestaetigt','widerlegt') NOT NULL DEFAULT 'offen',
  erstellt      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  vorlaeufig_bis DATETIME    NOT NULL,
  mail_gesendet DATETIME     NULL,
  KEY ix_baum_status (baum, status),
  CONSTRAINT fk_meldung_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Für später (Stufe 4): Nachrichten und öffentliche Fotos
CREATE TABLE IF NOT EXISTS nachrichten (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baum      INT UNSIGNED NOT NULL,
  spieler   INT UNSIGNED NOT NULL,
  text      VARCHAR(280) NOT NULL,
  erstellt  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  gemeldet  TINYINT(1)   NOT NULL DEFAULT 0,
  gesperrt  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_baum (baum),
  CONSTRAINT fk_nachricht_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fotos (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  baum         INT UNSIGNED NOT NULL,
  spieler      INT UNSIGNED NOT NULL,
  art          ENUM('selfie','baum') NOT NULL,
  datei        VARCHAR(100) NOT NULL,
  datum        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  einwilligung TINYINT(1)   NOT NULL DEFAULT 0,
  freigegeben  TINYINT(1)   NOT NULL DEFAULT 0,
  KEY ix_baum (baum),
  CONSTRAINT fk_foto_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login-Sitzungen: im Cookie steht nur ein Zufallswert, hier nur sein SHA-256
CREATE TABLE IF NOT EXISTS sitzungen (
  token_hash CHAR(64)     NOT NULL PRIMARY KEY,
  spieler    INT UNSIGNED NOT NULL,
  erstellt   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ablauf     DATETIME     NOT NULL,
  KEY ix_spieler (spieler),
  CONSTRAINT fk_sitzung_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Einmal-Links per Mail (E-Mail bestätigen, Passwort zurücksetzen)
CREATE TABLE IF NOT EXISTS mail_tokens (
  token_hash CHAR(64)     NOT NULL PRIMARY KEY,
  spieler    INT UNSIGNED NOT NULL,
  zweck      ENUM('bestaetigen','passwort') NOT NULL,
  ablauf     DATETIME     NOT NULL,
  CONSTRAINT fk_token_spieler FOREIGN KEY (spieler) REFERENCES spieler(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Schutz gegen Passwort-Raten und Mail-Spam
CREATE TABLE IF NOT EXISTS versuche (
  id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  schluessel VARCHAR(200) NOT NULL,       -- z. B. "login:ip:1.2.3.4" oder "login:mail:x@y"
  zeitpunkt DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_key_zeit (schluessel, zeitpunkt)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kleine Schlüssel/Wert-Ablage für den nächtlichen Kataster-Abgleich
CREATE TABLE IF NOT EXISTS system_status (
  schluessel VARCHAR(50) NOT NULL PRIMARY KEY,
  wert       VARCHAR(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
