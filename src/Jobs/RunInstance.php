<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Jobs;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Works off an instance's inbox. A lost or failed job loses nothing: the
 * ticker re-rings instances with pending events.
 */
final class RunInstance implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [5, 30];

    public function __construct(public readonly string $instanceId) {}

    public function handle(Engine $engine): void
    {
        $engine->runInstance($this->instanceId);
    }
}
