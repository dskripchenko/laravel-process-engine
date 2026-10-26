<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\ProcessEngine\Runtime\Container;
use Illuminate\Contracts\Container\Container as Application;

/**
 * Effect handlers come from the Laravel container: constructor injection works as usual.
 */
final class LaravelContainer implements Container
{
    public function __construct(private readonly Application $app) {}

    public function get(string $class): object
    {
        return $this->app->make($class);
    }
}
