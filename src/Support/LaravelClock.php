<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use DateTimeImmutable;
use Dskripchenko\ProcessEngine\Runtime\Clock;
use Illuminate\Support\Carbon;

/**
 * Time from Carbon, so `Carbon::setTestNow()` and `$this->travel()` in tests move the engine too.
 */
final class LaravelClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return Carbon::now('UTC')->toDateTimeImmutable();
    }
}
