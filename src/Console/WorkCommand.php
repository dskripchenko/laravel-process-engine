<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\LaravelProcessEngine\EngineFactory;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Illuminate\Console\Command;

/**
 * A long-running loop: ticks every few seconds (second-level timer precision).
 * With --sync it also runs the work itself, so no queue worker is needed.
 */
final class WorkCommand extends Command
{
    protected $signature = 'process-engine:work
        {--sleep=1 : Seconds between ticks}
        {--sync : Step instances and run effects in this process instead of the queue}
        {--max-time=0 : Stop after this many seconds (0: never)}';

    protected $description = 'Run the process engine loop';

    public function handle(Engine $engine, EngineFactory $factory): int
    {
        $dispatcher = $this->option('sync') ? new SyncDispatcher : null;
        $engine = $dispatcher !== null ? $factory->make($dispatcher) : $engine;
        $started = time();
        $maxTime = (int) $this->option('max-time');
        $sleep = max(1, (int) $this->option('sleep'));

        while (true) {
            $engine->tick();
            if ($dispatcher !== null) {
                $engine->drain(1000);
            }
            if ($maxTime > 0 && time() - $started >= $maxTime) {
                break;
            }
            sleep($sleep);
        }

        return self::SUCCESS;
    }
}
