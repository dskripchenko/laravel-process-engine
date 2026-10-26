<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine;

use Dskripchenko\LaravelProcessEngine\Support\LaravelContainer;
use Dskripchenko\LaravelProcessEngine\Support\SignalForwarder;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar;
use Dskripchenko\ProcessEngine\Interpreter\FlagProvider;
use Dskripchenko\ProcessEngine\Runtime\Clock;
use Dskripchenko\ProcessEngine\Runtime\Dispatcher;
use Dskripchenko\ProcessEngine\Runtime\EngineOptions;
use Dskripchenko\ProcessEngine\Runtime\Projector;
use Dskripchenko\ProcessEngine\Store\Store;
use Illuminate\Contracts\Foundation\Application;

/**
 * Builds an engine from the container and config, with a given doorbell
 * (the queue by default; a SyncDispatcher for `process-engine:work --sync`).
 */
final class EngineFactory
{
    public function __construct(private readonly Application $app) {}

    public function make(?Dispatcher $dispatcher = null): Engine
    {
        $o = (array) config('process-engine.options', []);
        $engine = new Engine(
            store: $this->app->make(Store::class),
            container: new LaravelContainer($this->app),
            dispatcher: $dispatcher ?? $this->app->make(Dispatcher::class),
            clock: $this->app->make(Clock::class),
            options: new EngineOptions(
                leaseTtl: (string) ($o['lease_ttl'] ?? '2 minutes'),
                eventsPerRun: (int) ($o['events_per_run'] ?? 100),
                maxContextBytes: (int) ($o['max_context_bytes'] ?? 262144),
                messageTtl: (string) ($o['message_ttl'] ?? '7 days'),
                deferTtl: (string) ($o['defer_ttl'] ?? '30 days'),
                sweepAfter: (string) ($o['sweep_after'] ?? '30 seconds'),
                archiveAfter: (string) ($o['archive_after'] ?? '30 days'),
                overdueAfter: (string) ($o['overdue_after'] ?? '5 minutes'),
                pollBackoffMax: (string) ($o['poll_backoff_max'] ?? '1 hour'),
                secret: (string) (config('process-engine.secret') ?: config('app.key') ?: 'process-engine'),
            ),
            listener: new SignalForwarder($this->app['events']),
            flags: $this->app->make(FlagProvider::class),
            calendar: $this->app->make(BusinessCalendar::class),
            allowRedefineUnused: (bool) config('process-engine.allow_redefine_unused', false),
            projector: $this->app->make(Projector::class),
        );
        foreach ((array) config('process-engine.migrations', []) as $factory) {
            $engine->registerMigration($this->app->make($factory)());
        }

        return $engine;
    }
}
