<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine;

use Dskripchenko\LaravelProcessEngine\Store\DatabaseStore;
use Dskripchenko\LaravelProcessEngine\Support\ConfigFlags;
use Dskripchenko\LaravelProcessEngine\Support\EffectRoutes;
use Dskripchenko\LaravelProcessEngine\Support\EloquentProjector;
use Dskripchenko\LaravelProcessEngine\Support\LaravelClock;
use Dskripchenko\LaravelProcessEngine\Support\QueueDispatcher;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar;
use Dskripchenko\ProcessEngine\Interpreter\FlagProvider;
use Dskripchenko\ProcessEngine\Interpreter\WeekdayCalendar;
use Dskripchenko\ProcessEngine\Runtime\Clock;
use Dskripchenko\ProcessEngine\Runtime\Dispatcher;
use Dskripchenko\ProcessEngine\Runtime\Projector;
use Dskripchenko\ProcessEngine\Store\Store;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class ProcessEngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/process-engine.php', 'process-engine');

        $this->app->singleton(Store::class, fn (Application $app) => new DatabaseStore(
            $app['db']->connection(config('process-engine.connection')),
            (string) config('process-engine.table_prefix', 'pe_'),
        ));
        $this->app->singleton(Clock::class, LaravelClock::class);
        $this->app->singleton(FlagProvider::class, ConfigFlags::class);
        $this->app->singleton(BusinessCalendar::class, WeekdayCalendar::class);
        $this->app->singleton(Projector::class, EloquentProjector::class);
        $this->app->singleton(EffectRoutes::class, fn () => new EffectRoutes(
            (array) config('process-engine.routes', []),
            config('process-engine.queue.connection'),
            config('process-engine.queue.name'),
        ));
        $this->app->singleton(Dispatcher::class, fn (Application $app) => new QueueDispatcher(
            config('process-engine.queue.connection'),
            config('process-engine.queue.name'),
            $app->make(EffectRoutes::class),
            fn (): Store => $app->make(Store::class),
        ));

        $this->app->singleton(Engine::class, fn (Application $app): Engine => (new EngineFactory($app))->make());
        $this->app->singleton(ProcessManager::class, fn (Application $app): ProcessManager => new ProcessManager(
            $app->make(Engine::class),
            $app['db']->connection(config('process-engine.connection')),
            (string) config('process-engine.table_prefix', 'pe_'),
        ));
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/process-engine.php' => config_path('process-engine.php')], 'process-engine-config');
            $this->publishes([__DIR__.'/../database/migrations' => database_path('migrations')], 'process-engine-migrations');
            $this->publishes([__DIR__.'/../stubs' => base_path('stubs/process-engine')], 'process-engine-stubs');
            $this->commands([
                Console\TickCommand::class,
                Console\WorkCommand::class,
                Console\StatusCommand::class,
                Console\DoctorCommand::class,
                Console\MigrateCommand::class,
                Console\ArchiveCommand::class,
                Console\ValidateCommand::class,
                Console\GraphCommand::class,
                Console\MakeProcessCommand::class,
                Console\MakeEffectCommand::class,
            ]);
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if (! config('process-engine.schedule.enabled', true)) {
                return;
            }
            if (config('process-engine.schedule.tick', true)) {
                $schedule->command('process-engine:tick')->everyMinute()->withoutOverlapping()->runInBackground();
            }
            if (config('process-engine.schedule.archive', true)) {
                $schedule->command('process-engine:archive')->daily()->withoutOverlapping();
            }
        });

        $this->forwardDelayedProcesses();
    }

    /**
     * laravel-delayed-process completions become published events, correlated
     * by the delayed process uuid: a state waits with
     * `->correlate('delayed-process.completed', by: 'delayedUuid')`.
     */
    private function forwardDelayedProcesses(): void
    {
        $completed = 'Dskripchenko\\DelayedProcess\\Events\\ProcessCompleted';
        $failed = 'Dskripchenko\\DelayedProcess\\Events\\ProcessFailed';
        if (! class_exists($completed) || ! config('process-engine.delayed_process.forward', true)) {
            return;
        }
        Event::listen($completed, function (object $event): void {
            $process = $event->process;
            $this->app->make(Engine::class)->publish('delayed-process.completed', (string) $process->uuid, [
                'uuid' => (string) $process->uuid, 'data' => (array) $process->data,
            ], 'delayed:'.$process->uuid.':done');
        });
        if (class_exists($failed)) {
            Event::listen($failed, function (object $event): void {
                $process = $event->process;
                if (! $process->status->isTerminal()) {
                    return;
                }
                $this->app->make(Engine::class)->publish('delayed-process.failed', (string) $process->uuid, [
                    'uuid' => (string) $process->uuid, 'message' => (string) $process->error_message,
                ], 'delayed:'.$process->uuid.':failed');
            });
        }
    }
}
