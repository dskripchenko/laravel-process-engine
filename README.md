# laravel-process-engine

[![tests](https://github.com/dskripchenko/laravel-process-engine/actions/workflows/tests.yml/badge.svg)](https://github.com/dskripchenko/laravel-process-engine/actions/workflows/tests.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Laravel bridge for [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine):
long-running processes as statecharts with durable execution, explicit retry
semantics for side effects, timers, external events, versions and operator
actions — on your database and your queue.

- A database store for PostgreSQL, MySQL / MariaDB and SQLite (migrations included).
- Queue jobs as the doorbell, the tick and the archive registered in the scheduler.
- Effect handlers resolved from the container — inject your services.
- `Processes` facade, `HasProcesses` for models: `startProcess()`,
  `process()`, `whereProcessIn('state')` scopes, and projection of the current
  state into a column of the model.
- Artisan: `tick`, `work`, `status`, `doctor`, `migrate`, `archive`,
  `validate`, `graph`, `make:process`, `make:process-effect`.
- Engine signals as Laravel events; `flag()` guards from config;
  laravel-delayed-process completions forwarded as events.

## Install

```bash
composer require dskripchenko/laravel-process-engine
php artisan migrate
```

Run a queue worker and the scheduler (`schedule:run` every minute) as usual.

## Use

```php
use Dskripchenko\LaravelProcessEngine\Concerns\HasProcesses;

final class Claim extends Model
{
    use HasProcesses;
}
```

```php
use Dskripchenko\LaravelProcessEngine\Facades\Processes;

$process = $claim->startProcess(ClaimSettlement::class, new ClaimContext(policyId: $policy->id));

Processes::send($process->id(), new FormSubmitted($request->validated()),
    key: $request->header('Idempotency-Key'), by: Actor::user($request->user()->id));

Processes::publish(new PartyDecision($payload['approved']),
    correlate: $payload['application_id'], key: $payload['message_id']);

$claim->process(ClaimSettlement::class)->expects();
Claim::whereProcessIn('awaitingDocuments')->paginate();
```

## Documentation

| | English | Deutsch | Русский | 中文 |
|---|---|---|---|---|
| Installation and configuration | [en](docs/en/installation.md) | [de](docs/de/installation.md) | [ru](docs/ru/installation.md) | [zh](docs/zh/installation.md) |
| Using it in an application | [en](docs/en/usage.md) | [de](docs/de/usage.md) | [ru](docs/ru/usage.md) | [zh](docs/zh/usage.md) |
| Data pipelines: queues per effect, exclusive starts, switches | [en](docs/en/pipelines.md) | [de](docs/de/pipelines.md) | [ru](docs/ru/pipelines.md) | [zh](docs/zh/pipelines.md) |

Processes themselves — the builder, effects, events, versions, operations —
are documented in [dskripchenko/process-engine](https://github.com/dskripchenko/process-engine#documentation).

## Requirements

PHP 8.2 – 8.5, Laravel 11, 12 or 13.

## License

MIT. See [LICENSE](LICENSE).
