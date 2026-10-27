# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.0] - 2026-10-27

### Added
- Batches and their elements on the database (`pe_batches`, `pe_batch_items`),
  removed with the archived instance. Requires `dskripchenko/process-engine` ^1.2.
- A queue per effect: `#[OnQueue]` on the handler class or
  `process-engine.routes`; the job timeout follows the attempt timeout of the
  effect's retry policy.
- `Processes::startExclusive()`: one unfinished instance per lock, for schedules
  that must not overlap (`pe_exclusive`).
- Switches of switchable states in `pe_switches`: `FlagProvider` is bound to
  `Switches` (flags from the config as before, switches from the table; the
  first state of a new switch from `process-engine.switches`).
- The `Processes` facade resolves `ProcessManager`, which forwards every call to
  the engine (`Processes::engine()` returns it).
- `tests/Feature/LoadTest.php`: a batch at scale (`PE_LOAD=<elements>`).

## [1.0.1] - 2026-10-26

### Fixed
- SQLite with several workers on PHP 8.2 / 8.3 failed with "database is
  locked": Laravel honours `transaction_mode` only on PHP 8.4+, and a
  deferred transaction that read first cannot upgrade to a write once another
  connection committed. The store now takes the write lock when a
  transaction begins. `transaction_mode` is no longer needed.

## [1.0.0] - 2026-10-26

### Added
- Database store for PostgreSQL, MySQL / MariaDB and SQLite with migrations,
  conditional updates for leases, claims and fencing, and an archive of one
  compressed row per finished instance.
- Queue jobs as the engine's doorbell (dispatched after commit), the tick and the
  archive registered in the scheduler.
- `Processes` facade, effect handlers from the container, Carbon-based clock,
  config-driven feature flags, engine signals as `ProcessSignal` events.
- `HasProcesses` for models: `startProcess()`, `process()`, `processInstances()`,
  `whereProcessIn()` / `whereProcessStatus()` scopes; projection of the current
  state into a model column inside the step transaction.
- Artisan commands: `process-engine:tick`, `work` (with `--sync`), `status`,
  `doctor`, `migrate`, `archive`, `validate`, `graph`; `make:process`,
  `make:process-effect`.
- Forwarding of laravel-delayed-process completions as published events.

[1.0.1]: https://github.com/dskripchenko/laravel-process-engine/releases/tag/v1.0.1
[1.0.0]: https://github.com/dskripchenko/laravel-process-engine/releases/tag/v1.0.0
