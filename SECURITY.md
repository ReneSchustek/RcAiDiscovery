# Sicherheit

## Schwachstelle melden

Sicherheitsrelevante Funde bitte **nicht** öffentlich als Issue, sondern vertraulich an
**info@ruhrcoder.de** melden. Wir bestätigen den Eingang und melden uns mit einer Einschätzung zurück.

## Plugin-spezifische Angriffsflächen

Dieses Plugin exponiert öffentliche, unauthentifizierte Storefront-Routen und beeinflusst die
robots.txt. Daraus ergeben sich folgende zu beachtende Flächen:

- **Öffentliche Route `/llms.txt`.** Sie liefert `text/plain` ohne Authentifizierung aus. Hinein
  dürfen ausschließlich Inhalte, die für die Öffentlichkeit bestimmt sind: keine internen Daten,
  keine Kundendaten, keine Preise mit Rabattlogik, keine Admin-Pfade.
- **Im Admin gepflegte Inhalte.** Titel, Kurzbeschreibung und zusätzlicher Inhalt aus der
  Plugin-Konfiguration sowie von Hand bearbeitete Dateien werden öffentlich als `text/plain`
  ausgeliefert. Titel und Kurzbeschreibung bringt das Plugin auf eine Zeile, den zusätzlichen
  Inhalt übernimmt es unverändert. Wer dort schreiben darf, bestimmt also den öffentlichen Text;
  interne Angaben gehören nicht hinein.
- **Ergänzung der robots.txt.** Die optionalen Regeln für KI-Crawler ergänzt ein Subscriber auf das
  `RobotsPageLoadedEvent` des Kerns, gesteuert über einen ausdrücklichen Admin-Schalter. Ohne
  Freigabe ändert sich an der robots.txt nichts.
- **Kein SSRF beim robots-Check.** Die Prüfung wertet die vom Shop selbst erzeugte robots.txt aus
  und ruft keine beliebigen, von außen steuerbaren URLs ab.

## Unterstützte Versionen

Sicherheitsupdates gibt es für die jeweils aktuelle Minor-Version.
