<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Something observable happened in a process (see Dskripchenko\ProcessEngine\Runtime\Signal for the types).
 */
final class ProcessSignal
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $instanceId,
        public readonly array $data = [],
    ) {}
}
