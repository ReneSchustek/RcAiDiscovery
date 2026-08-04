# Changelog

Alle nennenswerten Änderungen an RcAiDiscovery werden hier dokumentiert.
Format nach [Keep a Changelog](https://keepachangelog.com/de/1.0.0/), Versionierung nach [SemVer](https://semver.org/lang/de/).

## [0.6.2] - 2026-08-04 — Abhängigkeitsliste nachgezogen

> **Deployment:** `php bin/console plugin:update RcAiDiscovery`. Keine Code-Änderung.

### Behoben

- **Die eingecheckte `composer.lock` war veralteter als das, was tatsächlich installiert
  wurde.** Sie stand auf `shopware/core 6.7.11.1` und zog damit `composer/composer 2.10.1`,
  `dompdf 3.1.4` und `guzzle 7.12.3` — Fassungen, für die dreizehn Sicherheitsmeldungen
  vorliegen, darunter eine hohe. Im installierten `vendor/` lagen längst die reparierten
  Fassungen; deshalb meldete `composer audit` sauber, während die Meldungen am Repository
  stehen blieben. Der Lock steht jetzt auf `6.7.12.2`, `composer/composer 2.10.2`,
  `dompdf 3.1.6` und `guzzle 7.15.2`.

Am Code des Plugins wurde nichts geändert.

## [0.6.1] – 2026-07-27

### Behoben
- **Zweitsprachen enthielten keine Inhalte:** fehlt einer Kategorie die Übersetzung, zeigt die
  Storefront den Wert aus der Rückfallkette — die llms-Dateien übersprangen sie dagegen. In einem
  zweisprachigen Shop blieb die Datei der Zweitsprache dadurch nahezu leer. Kategoriename,
  Beschreibungen und der Name des Verkaufskanals folgen jetzt derselben Rückfallkette wie die
  Storefront. Kategorien ohne jeden Namen bleiben wie bisher außen vor.

## [0.6.0] – 2026-07-27

### Hinzugefügt
- **Gespeicherte llms-Dateien**: der Inhalt wird nicht mehr bei jedem Abruf neu gebaut, sondern je
  Verkaufskanal-Domain gespeichert und turnusmäßig (täglich) aktualisiert. Ein Abruf kostet damit
  einen Datensatz statt mehrerer Kategorie-Abfragen.
- Neue Karte in der Konfiguration: Stand und Zustand je Datei, mit **Jetzt aktualisieren**,
  **Bearbeiten** und **Neu generieren**.
- **Freier Inhalt**: eine Datei lässt sich vollständig von Hand schreiben. Sie gilt dann als
  bearbeitet und wird von der geplanten Aktualisierung nicht mehr überschrieben; „Neu generieren"
  stellt den automatischen Inhalt wieder her.
- Kaltstart: fehlt eine Datei noch, wird sie beim ersten Abruf einmalig erzeugt und gespeichert.

### Geändert
- Die ausgelieferten Dateien enthalten die fertigen absoluten Links; die Auslieferung selbst kommt
  ohne Verkaufskanal-Kontext aus.

## [0.5.0] – 2026-07-27

### Hinzugefügt
- **KI-Regeln in der robots.txt**: ein Schalter aktiviert das Schreiben, danach entscheidet je
  Gruppe — *Suche und Zitation*, *Abruf auf Nutzerwunsch*, *Training* — ob die Crawler erlaubt oder
  gesperrt werden. So lässt sich „gefunden werden" getrennt von „Inhalte fürs Modelltraining
  abgeben" regeln.
- Überarbeiteter Crawler-Katalog nach Zweck gruppiert, u. a. mit Claude-User, Claude-SearchBot,
  MistralAI-User, DuckAssistBot, Applebot, Meta-ExternalFetcher, Google-CloudVertexBot, GoogleOther.
- Erlaubende Regeln übernehmen die Schutzregeln des Shops (keine Indexierung von Parameter-URLs) —
  ein eigener Regelblock ersetzt sonst den Sammelblock samt dieser Regeln.
- Bereits im Shop gepflegte Regeln für einen Crawler bleiben unangetastet.
- Die Statusanzeige gruppiert die Crawler nach Zweck und weist Bingbot als Sonderfall aus: er
  trägt zugleich die klassische Suche und wird deshalb nur geprüft, nie geregelt.

### Geändert
- Abgelöste Tokens (`anthropic-ai`, `Claude-Web`) werden weiterhin ausgewertet, aber nicht mehr
  als neue Regel geschrieben.

## [0.4.0] – 2026-07-27

### Hinzugefügt
- **Admin-Konfiguration für die llms.txt** (je Sales-Channel): Titel, Kurzbeschreibung und ein
  eigener Markdown-Abschnitt lassen sich pflegen. Leere Felder bedeuten weiterhin „automatisch
  aus den Shop-Daten".
- Der Zusatz-Abschnitt wird vor der Sitemap eingefügt, damit die Sitemap-Zeile den Abschluss bildet.
- Ist eine Kurzbeschreibung gepflegt, entfällt die Kategorie-Abfrage der automatischen Ermittlung.
- Unit-Tests für die Konfigurations-Normalisierung (mehrzeilige Eingaben, Leerwerte, Zeilenenden)
  sowie ein Regressionstest, der die unveränderte Ausgabe ohne Overrides absichert.

## [0.3.0] – 2026-07-23

### Hinzugefügt
- **robots.txt-KI-Crawler-Check**: prüft für alle aktiven Storefront-Sales-Channels, ob die
  relevanten KI-Crawler (GPTBot, ClaudeBot, Google-Extended, PerplexityBot u. a.) durch die
  tatsächlich ausgelieferte robots.txt zugelassen sind.
- Auswertung der **effektiv gerenderten** robots.txt (inkl. Core-Defaults aus dem Twig-Template)
  über den Core-`RobotsDirectiveParser`; eigene Longest-Match-Bewertung pro Crawler.
- Admin-Statusanzeige (grün/rot je Crawler) als schlanke Komponente in der Plugin-Konfiguration,
  gespeist vom Admin-API-Endpoint `/api/_action/rc-ai-discovery/robots-check`.
- Unit-Tests der Bewertungslogik (Default-Allow, gezielter Disallow, Staging, leere/leere-Disallow,
  Case-Insensitivität).

### Behoben
- Korrektes robots.txt-Wildcard-Matching (`*`/`$`): Muster wie `Disallow: /*.pdf` melden den Crawler
  nicht mehr fälschlich als blockiert. Mehrere Blöcke desselben User-Agents werden zusammengeführt.
- effektive robots.txt wird über den `TemplateFinder` aufgelöst — berücksichtigt spätere
  `sw_extends`-Overrides (relevant ab AD05).
- Admin-Endpoint mit ACL (`sales_channel:read`); Fehler pro Sales-Channel werden abgefangen, als
  „unbekannt" gemeldet und mit Kontext geloggt (Graceful Degradation).
- Statusgründe als sprachneutrale Codes, im Admin per Snippet (de/en) übersetzt; Admin-Komponente
  nutzt den `init.httpClient`, `mt-button`, `sw-loader` und das Notification-Mixin; a11y
  (Statustext statt reiner Farbe). Card-Titel korrekt de-DE/en-GB.
- llms.txt: Markdown-Linktext maskiert (kein Link-Aufbruch durch eckige Klammern in Namen).

## [0.2.0] – 2026-07-23

### Hinzugefügt
- Storefront-Route **`/llms.txt`** (und ausführliche **`/llms-full.txt`**), `text/plain`,
  pro Sales-Channel-Domain, mit HTTP-Cache.
- **`LlmsTxtGenerator`**: erzeugt den Inhalt automatisch aus Shop-Daten (Shop-Name,
  Kurzbeschreibung aus der Navigations-Kategorie, Top-Level-Kategorien, Service-/Footer-Seiten,
  Sitemap-Verweis) im llms.txt-Markdown-Format. Links als absolute SEO-URLs.
- Unit-Tests für den Generator (Struktur, Kurz-/Voll-Variante, Fallbacks, Edge-Cases).

## [0.1.0] – 2026-07-23

### Hinzugefügt
- Plugin-Skeleton: Verzeichnisstruktur, `composer.json` (Namespace `Ruhrcoder\RcAiDiscovery`),
  Plugin-Klasse, Quality-Toolchain (PHPUnit, PHPStan Level 8, PHP-CS-Fixer), Doku-Grundgerüst.
- Bootstrap-Smoke-Test (Vererbung, `final`, `strict_types`, Namespace, Icon).

> Noch keine fachliche Funktion. llms.txt-Auslieferung und robots.txt-KI-Check folgen in den Briefs AD02 ff.
