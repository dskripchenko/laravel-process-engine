<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Closure;
use Dskripchenko\LaravelProcessEngine\Jobs\ExecuteEffect;
use Dskripchenko\LaravelProcessEngine\Jobs\RunInstance;
use Dskripchenko\ProcessEngine\Runtime\Dispatcher;
use Dskripchenko\ProcessEngine\Store\Store;

/**
 * Rings the doorbell by queueing a job. Jobs are dispatched after the
 * surrounding database transaction commits (the host's, if there is one).
 * Instances go to the engine's queue; an effect goes where its handler class
 * is routed (EffectRoutes), with a job timeout taken from its retry policy.
 */
final class QueueDispatcher implements Dispatcher
{
    /**
     * @param  Closure(): Store  $store  resolved lazily: the store is built after the dispatcher
     */
    public function __construct(
        private readonly ?string $connection,
        private readonly ?string $queue,
        private readonly ?EffectRoutes $routes = null,
        private readonly ?Closure $store = null,
    ) {}

    public function instanceReady(string $instanceId): void
    {
        $job = RunInstance::dispatch($instanceId)->afterCommit();
        if ($this->connection !== null) {
            $job->onConnection($this->connection);
        }
        if ($this->queue !== null) {
            $job->onQueue($this->queue);
        }
    }

    public function effectReady(string $effectId): void
    {
        $route = ['queue' => $this->queue, 'connection' => $this->connection];
        $timeout = null;
        $effect = $this->routes !== null && $this->store !== null ? ($this->store)()->findEffect($effectId) : null;
        if ($effect !== null) {
            $route = $this->routes->for($effect->class);
            $timeout = $this->routes->timeout($effect->retry);
        }

        $job = new ExecuteEffect($effectId);
        $job->timeout = $timeout;
        $pending = dispatch($job)->afterCommit();
        if ($route['connection'] !== null) {
            $pending->onConnection($route['connection']);
        }
        if ($route['queue'] !== null) {
            $pending->onQueue($route['queue']);
        }
    }
}
