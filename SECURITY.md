# Sicherheit

## Schwachstelle melden

Sicherheitsrelevante Funde bitte **nicht** öffentlich als Issue, sondern vertraulich an
**security@ruhrcoder.de** melden. Wir bestätigen den Eingang und melden uns mit einer Einschätzung zurück.

## Plugin-spezifische Angriffsflächen

Dieses Plugin exponiert öffentliche, unauthentifizierte Storefront-Routen und beeinflusst die
robots.txt. Daraus ergeben sich folgende zu beachtende Flächen:

- **Öffentliche Route `/llms.txt`** — liefert `text/plain` ohne Authentifizierung aus. Es dürfen
  ausschließlich für die Öffentlichkeit bestimmte Inhalte einfließen; keine internen Daten,
  keine Kundendaten, keine Preise mit Rabattlogik, keine Admin-Pfade.
- **Admin-Override-Felder (geplant, AD03)** — aktuell fließen ausschließlich automatisch generierte,
  öffentliche Shop-Daten in die llms.txt (kein Admin-Override implementiert). Sobald der Override kommt,
  gilt: im Admin gepflegter Inhalt wird öffentlich als reines `text/plain` ausgeliefert und muss vor
  der Ausgabe bereinigt werden (Markdown-Linktext maskieren, kein ungeprüftes Markup).
- **robots.txt-Manipulation** — die optionale Ergänzung von KI-Crawler-Regeln erfolgt ausschließlich
  über den Twig-Override des Core-Templates und wird durch einen expliziten Admin-Schalter
  gesteuert. Ohne Freigabe keine Änderung an der robots.txt.
- **Kein SSRF beim robots-Check** — die Prüfung wertet die vom Shop selbst erzeugte robots.txt aus;
  es werden keine beliebigen, extern steuerbaren URLs abgerufen.

## Unterstützte Versionen

Sicherheitsupdates gibt es für die jeweils aktuelle Minor-Version.
