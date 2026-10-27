<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Attributes;

use Attribute;

/**
 * Runs the effect (or the chunks of a batch) on its own queue: a slow
 * external service behind a single worker, long loads away from short calls.
 * `process-engine.routes` overrides it per class.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class OnQueue
{
    public function __construct(
        public readonly string $queue,
        public readonly ?string $connection = null,
    ) {}
}
