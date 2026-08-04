# RcAiDiscovery

Ein Shopware 6 Plugin von [Ruhrcoder](https://ruhrcoder.de).

> **Status:** v0.6.1 — vollständig. `/llms.txt` und `/llms-full.txt` werden gespeichert und täglich
> aktualisiert, im Admin pflegbar; die KI-Regeln der robots.txt werden geprüft und auf Wunsch gesetzt.

---

## Worum geht's?

KI-Systeme wie ChatGPT oder Perplexity werden zunehmend zur Produktrecherche genutzt.
Damit ein Shop dort sauber auftaucht, braucht es zwei Dinge: eine **llms.txt**, die den KIs die
wichtigsten Inhalte strukturiert anbietet, und eine **robots.txt**, die die KI-Crawler auch
tatsächlich hereinlässt. RcAiDiscovery kümmert sich um beides.

## Was kann es? (Zielbild)

**llms.txt ausliefern**
Das Plugin liefert unter `/llms.txt` eine für KI-Systeme optimierte Übersicht des Shops aus –
im offiziellen llms.txt-Format (Markdown). Der Inhalt wird **automatisch aus den Shop-Daten
generiert** (Shopname, Beschreibung, Kernkategorien, wichtige Seiten, Kontakt) und lässt sich
im Admin **überschreiben und ergänzen**. Pro Sales-Channel-Domain.

In der Plugin-Konfiguration stehen dafür drei Felder bereit — **Titel**, **Kurzbeschreibung** und
**zusätzlicher Inhalt** (ein eigener Markdown-Abschnitt, der vor der Sitemap angehängt wird).
Jedes Feld wirkt einzeln: was leer bleibt, kommt weiterhin automatisch aus den Shop-Daten.

Die Dateien werden **gespeichert und täglich aktualisiert**, nicht bei jedem Abruf neu gebaut — je
Verkaufskanal-Domain eine Kurz- und eine Langfassung. In der Karte „Gespeicherte llms-Dateien"
zeigt der Admin Stand und Zustand jeder Datei und bietet drei Aktionen: *Jetzt aktualisieren*,
*Bearbeiten* und *Neu generieren*. Eine von Hand bearbeitete Datei gilt als redaktionell gepflegt
und wird von der geplanten Aktualisierung nicht überschrieben; *Neu generieren* ist der bewusste
Weg zurück zum automatischen Inhalt.

**robots.txt auf KI-Crawler prüfen**
Das Plugin prüft, ob die relevanten KI-Crawler durch die robots.txt zugelassen sind, und zeigt den
Status im Admin an – grün/rot je Crawler, gruppiert nach Zweck.

**KI-Regeln setzen**
Auf Wunsch trägt das Plugin die Regeln selbst in die von Shopware erzeugte robots.txt ein. Die
Entscheidung fällt je Gruppe, weil die Bots unterschiedlichen Wert haben:

| Gruppe | Beispiele | Was sie bringen |
|---|---|---|
| Suche und Zitation | OAI-SearchBot, Claude-SearchBot, PerplexityBot | Sichtbarkeit – der Shop wird in KI-Antworten zitiert und verlinkt |
| Abruf auf Nutzerwunsch | ChatGPT-User, Claude-User, MistralAI-User | direkter Kundenkontakt, oft mit Kaufabsicht |
| Training | GPTBot, ClaudeBot, CCBot | keine Sichtbarkeit – reine Abgabe von Inhalten |

Erlaubende Regeln übernehmen die Schutzregeln des Shops (etwa keine Indexierung von Parameter-URLs).
Eine bereits im Shop gepflegte Regel für einen Crawler bleibt unangetastet. `Bingbot` wird bewusst
nur geprüft und nie geregelt: er trägt Microsoft Copilot, ist aber zugleich die klassische
Bing-Suche.

## Voraussetzungen

- Shopware 6.7 (getestet) – Constraint `~6.7.0 || ~6.8.0`
- PHP ≥ 8.2

## Installation

```bash
bin/console plugin:refresh
bin/console plugin:install --activate RcAiDiscovery
bin/console cache:clear
```

## Entwicklung

```bash
composer install
composer quality   # cs-check + phpstan + phpunit
```

## Wartung

Die KI-Crawler-Liste veraltet planmäßig — neue Anbieter-Tokens erscheinen mehrmals im Jahr. Sie
steht als gepflegte Liste in `src/Service/Robots/AiCrawlerCatalog.php`; ein neuer Crawler wird dort
mit seiner Gruppe ergänzt, Prüfung, Regeln und Anzeige ziehen automatisch nach.

Die gespeicherten Dateien aktualisiert eine geplante Aufgabe täglich
(`rc_ai_discovery.llms_generate`). Sie lässt sich jederzeit von Hand auslösen:

```bash
bin/console scheduled-task:run-single rc_ai_discovery.llms_generate
```

## Lizenz

MIT
