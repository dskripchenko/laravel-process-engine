# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project follows [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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

[1.0.0]: https://github.com/dskripchenko/laravel-process-engine/releases/tag/v1.0.0
