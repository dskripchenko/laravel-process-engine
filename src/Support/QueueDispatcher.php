<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\LaravelProcessEngine\Jobs\ExecuteEffect;
use Dskripchenko\LaravelProcessEngine\Jobs\RunInstance;
use Dskripchenko\ProcessEngine\Runtime\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;

/**
 * Rings the doorbell by queueing a job. Jobs are dispatched after the
 * surrounding database transaction commits (the host's, if there is one).
 */
final class QueueDispatcher implements Dispatcher
{
    public function __construct(private readonly ?string $connection, private readonly ?string $queue) {}

    public function instanceReady(string $instanceId): void
    {
        $job = RunInstance::dispatch($instanceId)->afterCommit();
        $this->route($job);
    }

    public function effectReady(string $effectId): void
    {
        $job = ExecuteEffect::dispatch($effectId)->afterCommit();
        $this->route($job);
    }

    private function route(PendingDispatch $job): void
    {
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }
    }
}
