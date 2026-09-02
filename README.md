# mensamax-api

Holt Bestellungen, Speiseplan und Kontostand aus [Mensamax](https://mensamax.de) und veröffentlicht
sie per **MQTT mit Home-Assistant-Discovery**. Home Assistant legt daraus pro Kind ein Gerät
„Mensamax <Name>“ mit fertigen Entitäten an. Es läuft als Cron-artiger Job in einem Docker-Container,
ein Webserver ist nicht nötig.

## Was in Home Assistant ankommt

Pro Konto (Kind) ein Gerät mit diesen Entitäten. `<id>` ist die Account-ID aus der Konfiguration.

| Entität | Zustand | Attribute |
|---|---|---|
| `sensor.mensamax_<id>_kontostand` | aktueller Kontostand in € | komplette Bilanz |
| `sensor.mensamax_<id>_bestellsumme` | Summe der Bestellungen ab heute bis Ende des Horizonts | Anzahl, Horizont |
| `sensor.mensamax_<id>_restbetrag` | Kontostand minus Bestellsumme | `covered_until`, `uncovered_from`, `mensamax_future` |
| `binary_sensor.mensamax_<id>_guthaben_niedrig` | an, wenn Kontostand unter `MENSAMAX_LOW_BALANCE` | Betrag, Schwelle |
| `sensor.mensamax_<id>_heute` | Kurztext des bestellten Gerichts, „Keine Bestellung“ oder „Kein Essen“ | Datum, Gericht-Nummer, Volltext, Vorspeise, Nachspeise, Preis, alle angebotenen Gerichte |
| `sensor.mensamax_<id>_morgen` | wie heute | wie heute |
| `sensor.mensamax_<id>_naechste_bestellung` | Kurztext der nächsten Bestellung | wie heute |
| `sensor.mensamax_<id>_diese_woche` | Anzahl bestellter Tage | `days` (Mo–Fr mit Status, Gericht-Nummer, Volltext, Kurztext), `missing_required`, `missing_optional`, `complete`, `order_deadline`, `summary` |
| `sensor.mensamax_<id>_naechste_woche` | wie oben | wie oben |
| `sensor.mensamax_<id>_in_zwei_wochen` | wie oben | wie oben |
| `binary_sensor.mensamax_<id>_bestellung_fehlt` | an, wenn an einem noch änderbaren Pflichttag nichts bestellt ist | `missing_required`, `missing_optional`, `missed_required` (Frist verpasst), `next_missing` |
| `sensor.mensamax_<id>_pruefung` | Bestellfrist der nächsten noch änderbaren Woche (Zeitstempel) | Woche, Tage mit vorausgewähltem Gericht **und allen Alternativen**, `summary` |
| `binary_sensor.mensamax_<id>_pruefung_faellig` | an, wenn die Frist in höchstens `MENSAMAX_REVIEW_WINDOW_DAYS` Tagen abläuft | Woche, Frist, `summary` |
| `sensor.mensamax_<id>_letzte_aktualisierung` | Zeitstempel des letzten Abrufs | Horizont, `llm_error`, konfigurierte Wochentage |

Jedes bestellte Gericht enthält `number` (Gericht 1, 2, 3 …), `group` (z. B. „Menü 1 (vegetarisch)“),
`main` (Volltext), `main_short` (KI-Kurzfassung), `starter`, `dessert`, `full_text` und `price`.

Sensoren werden „nicht verfügbar“, wenn 26 Stunden lang kein Update kam oder der letzte Abruf
fehlgeschlagen ist (Availability-Topic).

### Hintergrund: Fristen und Vorauswahl

Mensamax liefert pro Gericht die Bestellfrist (`bestellungBis`). Bei dieser Mensa ist das der
Mittwoch 00:05 Uhr der Vorwoche. Da im Konto immer Gericht 1 vorausgewählt ist, lautet die Frage
nicht „ist bestellt?“, sondern „haben wir die Auswahl geprüft?“. Dafür gibt es `pruefung` und
`pruefung_faellig`: Sie zeigen immer die nächste Woche, deren Frist noch nicht abgelaufen ist,
inklusive aller Alternativen pro Tag. Eine HA-Automation kann beim Einschalten von
`pruefung_faellig` eine Benachrichtigung mit `summary` schicken.

Tage ohne Angebot (Wochenende, Ferien, gesperrte Tage) werden über den Speiseplan erkannt und
lösen keine Warnung aus.

### Beispiel: Markdown-Karte

Eine Lovelace-Markdown-Karte, die heute und morgen zeigt und nur bei Bedarf warnt.
`anna` durch die eigene Account-ID ersetzen.

```jinja
<strong>Mensa Anna</strong>

{% set heute = state_attr('sensor.mensamax_anna_heute', 'order') %}
{% set heute_status = state_attr('sensor.mensamax_anna_heute', 'status') %}
{% set morgen = state_attr('sensor.mensamax_anna_morgen', 'order') %}
{% set morgen_status = state_attr('sensor.mensamax_anna_morgen', 'status') %}

**Heute**{% if heute %}, {{ heute.group }}: {{ heute.full_text }}
{% elif heute_status == 'missing' %}: ⚠️ keine Bestellung
{% else %}: kein Mensa-Essen{% if state_attr('sensor.mensamax_anna_heute', 'message') %} ({{ state_attr('sensor.mensamax_anna_heute', 'message') }}){% endif %}
{% endif %}

**Morgen**{% if morgen %}, {{ morgen.group }}: {{ morgen.full_text }}
{% elif morgen_status == 'missing' %}: ⚠️ keine Bestellung
{% else %}: kein Mensa-Essen{% if state_attr('sensor.mensamax_anna_morgen', 'message') %} ({{ state_attr('sensor.mensamax_anna_morgen', 'message') }}){% endif %}
{% endif %}

{% if is_state('binary_sensor.mensamax_anna_guthaben_niedrig', 'on') %}
> 💰 **Guthaben knapp:** nur noch {{ states('sensor.mensamax_anna_kontostand') }} € (Schwelle {{ state_attr('binary_sensor.mensamax_anna_guthaben_niedrig', 'threshold') }} €). Bitte aufladen.
{% endif %}

{% if is_state('binary_sensor.mensamax_anna_bestellung_fehlt', 'on') %}
> ❗ **Bestellung fehlt** an Pflichttagen: {{ state_attr('binary_sensor.mensamax_anna_bestellung_fehlt', 'missing_required') | join(', ') }}
{% endif %}

{% if is_state('binary_sensor.mensamax_anna_pruefung_faellig', 'on') %}
> 📋 **Menü prüfen bis {{ as_timestamp(state_attr('binary_sensor.mensamax_anna_pruefung_faellig', 'order_deadline')) | timestamp_custom('%a %d.%m. %H:%M') }}** ({{ state_attr('binary_sensor.mensamax_anna_pruefung_faellig', 'week') }}):
{{ state_attr('binary_sensor.mensamax_anna_pruefung_faellig', 'summary') | replace('\n', '  \n') }}
{% endif %}
```

Der Status von `heute`/`morgen` ist `ordered`, `missing`, `not_needed` oder `not_offered`; gewarnt
wird nur bei `missing`. Für Automationen, etwa ein E-Paper-Display, eignet sich
`sensor.mensamax_<id>_letzte_aktualisierung` als Trigger: Er ändert sich genau einmal pro Abruf.

## Konfiguration

Alles über Umgebungsvariablen, siehe [`.env.example`](.env.example). Mehrere Kinder werden über
`MENSAMAX_ACCOUNTS=anna,ben` angelegt; jede Einstellung kann per `MENSAMAX_<ID>_<KEY>` je Konto
überschrieben werden, sonst gilt `MENSAMAX_<KEY>`.

| Variable | Bedeutung | Default |
|---|---|---|
| `MENSAMAX_ACCOUNTS` | Komma-Liste der Konto-IDs (a-z, 0-9, _) | Pflicht |
| `MENSAMAX_<ID>_NAME` | Anzeigename in HA | ID |
| `MENSAMAX_<ID>_PROJECT`, `_USERNAME`, `_PASSWORD` | Mensamax-Login | Pflicht |
| `MENSAMAX_REQUIRED_WEEKDAYS` | Pflichttage, ISO-Wochentage 1–7 | `2,3,4` |
| `MENSAMAX_OPTIONAL_WEEKDAYS` | optionale Tage | `1` |
| `MENSAMAX_LOW_BALANCE` | Schwelle für „Guthaben niedrig“ in € | `20` |
| `MENSAMAX_REVIEW_WINDOW_DAYS` | Tage vor der Frist, ab denen „Menüprüfung fällig“ anspringt | `3` |
| `MENSAMAX_LOOKAHEAD_WEEKS` | abgerufene Wochen ab der aktuellen | `4` |
| `MQTT_HOST`, `MQTT_PORT`, `MQTT_USERNAME`, `MQTT_PASSWORD`, `MQTT_TLS` | Broker | Host Pflicht |
| `MQTT_DISCOVERY_PREFIX`, `MQTT_TOPIC_PREFIX` | Topics | `homeassistant`, `mensamax` |
| `LLM_PROVIDER` | `claude`, `openai` oder `none` | `none` |
| `LLM_MODEL`, `LLM_API_KEY` | Modell und Schlüssel | Provider-Default |
| `LLM_SHORT_MAX_LENGTH` | Zielzeichenzahl der Kurzfassung | `30` |
| `TZ` | Zeitzone | `Europe/Berlin` |

Kurzfassungen werden in `data/short-texts.json` gecacht; das LLM wird nur für neue Gerichte gefragt.

## Betrieb mit Docker

```sh
cp .env.example .env   # ausfüllen
docker compose up -d --build
docker compose logs -f
```

Der Container ruft `bin/mensamax publish` alle `PUBLISH_INTERVAL` Sekunden auf (Default 3600).
Einmalig ausführen:

```sh
docker compose run --rm mensamax publish -v
docker compose run --rm mensamax publish --dry-run   # nur JSON ausgeben, nichts senden
docker compose run --rm mensamax remove ben          # Entitäten eines Kontos aus HA entfernen
```

## Lokal entwickeln

```sh
composer install
bin/mensamax publish --dry-run --account=anna
vendor/bin/phpunit
```

Die Auswertung (`OverviewBuilder`) ist eine reine Funktion aus Snapshot, Konfiguration und
Zeitpunkt und wird mit einer echten Mensamax-Antwort in `tests/Fixtures` getestet.

## Struktur

- `src/Mensamax` – GraphQL-Client (`login`, `meineDaten`, `meinKontostand`, `meinSpeiseplan`) und Umwandlung in `AccountSnapshot`
- `src/Domain` – Wertobjekte: `DayPlan`, `Menu`, `DayKind`, `DayStatus`
- `src/Overview` – Berechnung von heute, Wochen, Warnungen, Bilanz und Menüprüfung
- `src/Llm` – Kürzung der Gerichtsnamen mit Claude oder OpenAI, mit Datei-Cache
- `src/HomeAssistant` – Entitätsdefinitionen (Discovery-Payloads) und MQTT-Publisher
- `src/Command` – Konsolenbefehle `publish` und `remove`

## Lizenz

MIT, siehe [LICENSE](LICENSE).
