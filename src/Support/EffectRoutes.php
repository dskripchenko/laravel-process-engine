<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;
use Dskripchenko\ProcessEngine\Support\Duration;
use ReflectionClass;

/**
 * Where the job of an effect goes and how long it may take: the route of its
 * handler class (`process-engine.routes`, then `#[OnQueue]`, then the
 * engine's queue) and a timeout just over the attempt timeout of its retry
 * policy, so the queue never kills an attempt the engine still counts as alive.
 */
final class EffectRoutes
{
    /** Seconds over the attempt timeout before the queue may kill the job. */
    public const TIMEOUT_MARGIN = 30;

    /** @var array<string, array{queue: ?string, connection: ?string}> */
    private array $resolved = [];

    /**
     * @param  array<string, string|array{queue?: ?string, connection?: ?string}>  $routes
     */
    public function __construct(
        private readonly array $routes,
        private readonly ?string $connection,
        private readonly ?string $queue,
    ) {}

    /**
     * @return array{queue: ?string, connection: ?string}
     */
    public function for(string $class): array
    {
        return $this->resolved[$class] ??= $this->resolve($class);
    }

    /**
     * @param  array<string, mixed>  $retry  the effect's retry policy (Retry::toArray())
     */
    public function timeout(array $retry): ?int
    {
        $spec = $retry['attemptTimeout'] ?? null;

        return is_string($spec) && Duration::isValid($spec) ? Duration::seconds($spec) + self::TIMEOUT_MARGIN : null;
    }

    /**
     * @return array{queue: ?string, connection: ?string}
     */
    private function resolve(string $class): array
    {
        $route = $this->routes[$class] ?? null;
        if (is_string($route)) {
            return ['queue' => $route, 'connection' => $this->connection];
        }
        if (is_array($route)) {
            return ['queue' => $route['queue'] ?? $this->queue, 'connection' => $route['connection'] ?? $this->connection];
        }
        if (class_exists($class)) {
            $attribute = (new ReflectionClass($class))->getAttributes(OnQueue::class)[0] ?? null;
            if ($attribute !== null) {
                $onQueue = $attribute->newInstance();

                return ['queue' => $onQueue->queue, 'connection' => $onQueue->connection ?? $this->connection];
            }
        }

        return ['queue' => $this->queue, 'connection' => $this->connection];
    }
}
