<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Jobs;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs one effect. Retries are the engine's business (its retry policy),
 * not the queue's: the job itself is tried once.
 */
final class ExecuteEffect implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $effectId) {}

    public function handle(Engine $engine): void
    {
        $engine->executeEffect($this->effectId);
    }
}
