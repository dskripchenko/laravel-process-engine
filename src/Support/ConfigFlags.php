<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\ProcessEngine\Interpreter\FlagProvider;
use Illuminate\Contracts\Config\Repository;

/**
 * `flag('name')` guards read `process-engine.flags.name`. Bind your own
 * FlagProvider (Pennant, a settings table) to replace it.
 */
final class ConfigFlags implements FlagProvider
{
    public function __construct(private readonly Repository $config) {}

    public function enabled(string $flag): bool
    {
        return (bool) $this->config->get('process-engine.flags.'.$flag, false);
    }
}
