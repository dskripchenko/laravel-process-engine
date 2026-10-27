# Datenpipelines

Was die Bridge für Batches und abschaltbare Stufen des Kerns hinzufügt
([Pipelines](https://github.com/dskripchenko/process-engine/blob/main/docs/de/pipelines.md)):
Speicherung in der Datenbank, eine Queue pro Effekt, exklusive Starts für
Zeitpläne und Schalter, die in einer Tabelle gehalten werden.

Führen Sie nach dem Upgrade die Migrationen aus: `pe_batches`,
`pe_batch_items`, `pe_switches`, `pe_exclusive` kommen hinzu.

## Eine Queue pro Effekt

Ein langsamer externer Dienst hinter einem einzigen Worker, ein
sechsstündiger Ladevorgang abseits kurzer Aufrufe:

```php
use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;

#[Idempotent(retry: new Retry(attemptTimeout: '6 hours'))]
#[OnQueue('gar')]
final class LoadRegion implements BatchHandler { /* … */ }
```

Oder in der Konfiguration, die das Attribut überschreibt:

```php
'routes' => [
    NspdLookup::class => 'nspd',
    LoadRegion::class => ['queue' => 'gar', 'connection' => 'redis'],
],
```

Der Job eines Effekts (oder eines Chunks eines Batches) geht an seine Route;
das Timeout des Jobs ist das Versuchs-Timeout seiner Wiederholungsrichtlinie
plus 30 Sekunden, sodass die Queue nie einen Versuch abbricht, den die Engine
noch für lebendig hält. Halten Sie `retry_after` der Queue über dem längsten
davon. Das Weiterschalten von Instanzen bleibt in der Queue der Engine
(`process-engine.queue`).

## Exklusive Starts

```php
Processes::startExclusive(Monitoring::class, 'monitoring');
Processes::startExclusive(RiskSurface::class, "surface:{$profile}", ['profile' => $profile]);
```

Startet, sofern keine unbeendete Instanz die Sperre hält — dann wird diese
zurückgegeben. Für Zeitpläne, die sich nicht überschneiden dürfen. Die Sperre
gibt sich selbst frei, wenn ihre Instanz endet.

## Schalter

`FlagProvider` ist an `Switches` gebunden: `flag()`-Guards lesen wie bisher
`process-engine.flags`, Schalter abschaltbarer Zustände leben in
`pe_switches`. Ein neuer Schalter startet so, wie `process-engine.switches`
es vorgibt (an, wenn nicht aufgeführt); sobald er geändert wurde, gehört er
der Tabelle. Jede Prüfung liest die Tabelle, sodass ein lange laufender
Worker eine anderswo vorgenommene Änderung sofort sieht.

```php
Processes::switchOff('enrichment.dadata', Actor::user($id, $name), 'no key yet');
Processes::switchOn('enrichment.dadata', Actor::user($id, $name));
app(Switches::class)->all();   // every stored switch with who, when and why
```

Eine automatische Abschaltung kommt als Ereignis `ProcessSignal` mit dem Typ
`switch.changed` und `data.by.type = system`: Benachrichtigen Sie Menschen
aus einem Listener.

## Last

Auf PostgreSQL verarbeitet die Engine selbst etwa 10 000 Elemente eines
Batches pro Sekunde, konstant bis zu einer Million. Zum Messen auf Ihrer
Hardware:

```bash
PE_LOAD=1000000 PE_DB=pgsql PE_DB_USERNAME=… PE_DB_PASSWORD=… vendor/bin/pest tests/Feature/LoadTest.php
```
