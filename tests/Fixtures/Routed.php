<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Tests\Fixtures;

use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;

#[Idempotent(retry: new Retry(attemptTimeout: '6 hours'))]
#[OnQueue('slow')]
final class LoadRegion
{
    public function __invoke(): string
    {
        return 'loaded';
    }
}

#[Idempotent]
final class PlainCall
{
    public function __invoke(): string
    {
        return 'ok';
    }
}

final class RoutedFlow extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('work', fn (Builder $s) => $s
            ->invoke(LoadRegion::class)
            ->invoke(PlainCall::class));
    }
}
