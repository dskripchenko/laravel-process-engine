# Installation and configuration

## Install

```bash
composer require dskripchenko/laravel-process-engine
php artisan migrate
```

The service provider is discovered automatically and loads its migrations.
To change them or the configuration, publish:

```bash
php artisan vendor:publish --tag=process-engine-config
php artisan vendor:publish --tag=process-engine-migrations
php artisan vendor:publish --tag=process-engine-stubs      # make:process templates
```

## What has to run

| Piece | How |
|---|---|
| queue worker | `php artisan queue:work` — steps instances and runs effects (`RunInstance`, `ExecuteEffect` jobs) |
| scheduler | `schedule:run` every minute — the provider registers `process-engine:tick` (every minute) and `process-engine:archive` (daily) |

Without a queue: `php artisan process-engine:work --sync` ticks every second
and does the work in-process. Without the scheduler: run
`process-engine:work` (ticks only, the queue does the work), which also gives
second-level timer precision.

Lost jobs lose nothing: the tick finds pending events and due effects in the
database and dispatches them again. Jobs are dispatched after the
surrounding transaction commits.

## Configuration (`config/process-engine.php`)

| Key | Default | |
|---|---|---|
| `connection` | default connection | database connection for the engine tables |
| `table_prefix` | `pe_` | |
| `queue.connection`, `queue.name` | default, `default` | where the jobs go |
| `secret` | `APP_KEY` | signs task tokens |
| `schedule.enabled`, `.tick`, `.archive` | on | register the tick and the archive in the scheduler |
| `options.lease_ttl` | `2 minutes` | how long a worker owns an instance while stepping it |
| `options.events_per_run` | 100 | events per job before yielding |
| `options.max_context_bytes` | 262144 | context size limit |
| `options.message_ttl` | `7 days` | how long a published event waits for a subscriber |
| `options.defer_ttl` | `30 days` | how long a deferred event waits |
| `options.sweep_after` | `30 seconds` | pending events older than this are re-dispatched by the tick |
| `options.archive_after` | `30 days` | finished instances older than this are archived |
| `options.overdue_after` | `5 minutes` | a timer late by more counts as overdue in `status` |
| `options.poll_backoff_max` | `1 hour` | interval of a failing poll after its retries |
| `flags` | `[]` | `name => bool` for `flag('name')` guards |
| `allow_redefine_unused` | false | allow changing a stored version no instance uses (local development) |
| `processes` | `[]` | classes checked by `process-engine:validate` |
| `migrations` | `[]` | invokable classes returning `Migration`s, registered on boot |
| `delayed_process.forward` | true | forward laravel-delayed-process completions |

## Databases

PostgreSQL, MySQL 8 / MariaDB and SQLite are supported and tested. JSON
columns use the native type where there is one. For SQLite with more than
one worker, set on the connection:

```php
'transaction_mode' => 'IMMEDIATE',
'journal_mode' => 'wal',
'busy_timeout' => 10000,
```

## Replacing parts

Bind your own implementations in a service provider:

| Interface | Default |
|---|---|
| `Dskripchenko\ProcessEngine\Interpreter\FlagProvider` | flags from config |
| `Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar` | Monday to Friday, no holidays |
| `Dskripchenko\ProcessEngine\Runtime\Projector` | writes `project()` mappings into model columns |
| `Dskripchenko\ProcessEngine\Runtime\Dispatcher` | queue jobs |
| `Dskripchenko\ProcessEngine\Runtime\Clock` | Carbon (respects `Carbon::setTestNow()` / `travel()`) |

## Production checklist

- A queue worker and the scheduler run (or `process-engine:work`).
- `php artisan process-engine:validate --path=app/Processes` in CI.
- `php artisan process-engine:doctor` before removing effect classes or
  process methods: live versions may still use them.
- `php artisan process-engine:status --fail-on-problems` in your health
  checks (open incidents, overdue timers, stuck effects).
- Listen to `ProcessSignal` for `incident.opened` and alert someone.
