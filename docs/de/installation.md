# Installation und Konfiguration

## Installation

```bash
composer require dskripchenko/laravel-process-engine
php artisan migrate
```

Der Service Provider wird automatisch erkannt und lädt seine Migrationen. Um
diese oder die Konfiguration zu ändern, veröffentlichen Sie sie:

```bash
php artisan vendor:publish --tag=process-engine-config
php artisan vendor:publish --tag=process-engine-migrations
php artisan vendor:publish --tag=process-engine-stubs      # make:process templates
```

## Was laufen muss

| Komponente | Wie |
|---|---|
| Queue-Worker | `php artisan queue:work` — schaltet Instanzen weiter und führt Effekte aus (Jobs `RunInstance`, `ExecuteEffect`) |
| Scheduler | `schedule:run` jede Minute — der Provider registriert `process-engine:tick` (jede Minute) und `process-engine:archive` (täglich) |

Ohne Queue: `php artisan process-engine:work --sync` tickt jede Sekunde und
erledigt die Arbeit im selben Prozess. Ohne Scheduler: Führen Sie
`process-engine:work` aus (nur Ticks, die Queue erledigt die Arbeit), was
zugleich sekundengenaue Timer ermöglicht.

Verlorene Jobs verlieren nichts: Der Tick findet ausstehende Ereignisse und
fällige Effekte in der Datenbank und dispatcht sie erneut. Jobs werden
dispatcht, nachdem die umgebende Transaktion committet wurde.

## Konfiguration (`config/process-engine.php`)

| Schlüssel | Standard | |
|---|---|---|
| `connection` | Standardverbindung | Datenbankverbindung für die Engine-Tabellen |
| `table_prefix` | `pe_` | |
| `queue.connection`, `queue.name` | Standard, `default` | wohin die Jobs gehen |
| `secret` | `APP_KEY` | signiert Task-Tokens |
| `schedule.enabled`, `.tick`, `.archive` | an | Tick und Archivierung im Scheduler registrieren |
| `options.lease_ttl` | `2 minutes` | wie lange ein Worker eine Instanz besitzt, während er sie weiterschaltet |
| `options.events_per_run` | 100 | Ereignisse pro Job, bevor er abgibt |
| `options.max_context_bytes` | 262144 | Größengrenze des Kontexts |
| `options.message_ttl` | `7 days` | wie lange ein veröffentlichtes Ereignis auf einen Abonnenten wartet |
| `options.defer_ttl` | `30 days` | wie lange ein zurückgestelltes Ereignis wartet |
| `options.sweep_after` | `30 seconds` | ausstehende Ereignisse, die älter sind, werden vom Tick erneut dispatcht |
| `options.archive_after` | `30 days` | abgeschlossene Instanzen, die älter sind, werden archiviert |
| `options.overdue_after` | `5 minutes` | ein Timer, der um mehr als das verspätet ist, gilt in `status` als überfällig |
| `options.poll_backoff_max` | `1 hour` | Intervall eines fehlschlagenden Polls nach seinen Wiederholungen |
| `flags` | `[]` | `name => bool` für `flag('name')`-Guards |
| `allow_redefine_unused` | false | erlaubt das Ändern einer gespeicherten Version, die keine Instanz verwendet (lokale Entwicklung) |
| `processes` | `[]` | Klassen, die von `process-engine:validate` geprüft werden |
| `migrations` | `[]` | aufrufbare Klassen, die `Migration`s zurückgeben, beim Booten registriert |
| `delayed_process.forward` | true | Abschlüsse von laravel-delayed-process weiterleiten |

## Datenbanken

PostgreSQL, MySQL 8 / MariaDB und SQLite werden unterstützt und getestet.
JSON-Spalten verwenden den nativen Typ, wo es einen gibt. Für SQLite mit mehr
als einem Worker setzen Sie an der Verbindung:

```php
'journal_mode' => 'wal',
'busy_timeout' => 10000,
```

Der Store nimmt die Schreibsperre beim Beginn der Transaktion, daher ist
`transaction_mode` nicht nötig (Laravel wendet es ohnehin erst ab PHP 8.4 an).

## Teile ersetzen

Binden Sie Ihre eigenen Implementierungen in einem Service Provider:

| Interface | Standard |
|---|---|
| `Dskripchenko\ProcessEngine\Interpreter\FlagProvider` | Flags aus der Konfiguration |
| `Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar` | Montag bis Freitag, keine Feiertage |
| `Dskripchenko\ProcessEngine\Runtime\Projector` | schreibt `project()`-Zuordnungen in Modellspalten |
| `Dskripchenko\ProcessEngine\Runtime\Dispatcher` | Queue-Jobs |
| `Dskripchenko\ProcessEngine\Runtime\Clock` | Carbon (berücksichtigt `Carbon::setTestNow()` / `travel()`) |

## Checkliste für die Produktion

- Ein Queue-Worker und der Scheduler laufen (oder `process-engine:work`).
- `php artisan process-engine:validate --path=app/Processes` in der CI.
- `php artisan process-engine:doctor`, bevor Effektklassen oder
  Prozessmethoden entfernt werden: Lebende Versionen könnten sie noch verwenden.
- `php artisan process-engine:status --fail-on-problems` in Ihren
  Health-Checks (offene Incidents, überfällige Timer, hängende Effekte).
- Auf `ProcessSignal` für `incident.opened` hören und jemanden alarmieren.
