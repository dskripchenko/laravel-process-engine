# Data pipelines

What the bridge adds for batches and switchable stages of the core
([pipelines](https://github.com/dskripchenko/process-engine/blob/main/docs/en/pipelines.md)):
storage in the database, a queue per effect, exclusive starts for schedules and
switches kept in a table.

Run the migrations after upgrading: `pe_batches`, `pe_batch_items`,
`pe_switches`, `pe_exclusive` are added.

## A queue per effect

A slow external service behind a single worker, a six-hour load away from short
calls:

```php
use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;

#[Idempotent(retry: new Retry(attemptTimeout: '6 hours'))]
#[OnQueue('gar')]
final class LoadRegion implements BatchHandler { /* … */ }
```

Or in the config, which overrides the attribute:

```php
'routes' => [
    NspdLookup::class => 'nspd',
    LoadRegion::class => ['queue' => 'gar', 'connection' => 'redis'],
],
```

The job of an effect (or a chunk of a batch) goes to its route; the timeout of
the job is the attempt timeout of its retry policy plus 30 seconds, so the queue
never kills an attempt the engine still counts as alive. Keep the queue's
`retry_after` above the longest one. Stepping instances stays on the engine's
queue (`process-engine.queue`).

## Exclusive starts

```php
Processes::startExclusive(Monitoring::class, 'monitoring');
Processes::startExclusive(RiskSurface::class, "surface:{$profile}", ['profile' => $profile]);
```

Starts unless an unfinished instance holds the lock — then returns that one. For
schedules that must not overlap. The lock frees itself when its instance finishes.

## Switches

`FlagProvider` is bound to `Switches`: `flag()` guards read
`process-engine.flags` as before, switches of switchable states live in
`pe_switches`. A new switch starts as `process-engine.switches` says (on when not
listed); once changed, the table owns it. Every check reads the table, so a
long-running worker sees a change made elsewhere at once.

```php
Processes::switchOff('enrichment.dadata', Actor::user($id, $name), 'no key yet');
Processes::switchOn('enrichment.dadata', Actor::user($id, $name));
app(Switches::class)->all();   // every stored switch with who, when and why
```

An automatic switch-off comes as the event `ProcessSignal` with type
`switch.changed` and `data.by.type = system`: notify people from a listener.

## Load

On PostgreSQL the engine itself handles about 10 000 elements of a batch a
second, flat to a million. To measure on your hardware:

```bash
PE_LOAD=1000000 PE_DB=pgsql PE_DB_USERNAME=… PE_DB_PASSWORD=… vendor/bin/pest tests/Feature/LoadTest.php
```
