<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Tests\Fixtures;

use Dskripchenko\ProcessEngine\Attributes\AtMostOnce;
use Dskripchenko\ProcessEngine\Attributes\Idempotent;
use Dskripchenko\ProcessEngine\Builder\Builder;
use Dskripchenko\ProcessEngine\Effect\Effect;
use Dskripchenko\ProcessEngine\Effect\Outcome;
use Dskripchenko\ProcessEngine\Effect\Reconcilable;
use Dskripchenko\ProcessEngine\Effect\Retry;
use Dskripchenko\ProcessEngine\Process;
use Illuminate\Support\Facades\DB;

/**
 * Records every call in a table: workers are separate processes, so counters must live in the database.
 */
#[AtMostOnce(retry: new Retry(attempts: 1, attemptTimeout: '2 seconds'), reconcileRetry: new Retry(attempts: 5, initialInterval: '1 second', jitter: false, attemptTimeout: '2 seconds'))]
final class CountedCharge implements Reconcilable
{
    public function __invoke(Effect $fx): string
    {
        DB::table('charge_calls')->insert(['key' => $fx->key, 'pid' => getmypid()]);
        $slow = (int) (getenv('PE_SLOW_CHARGE') ?: 0);
        if ($slow > 0) {
            sleep($slow);
        }

        return 'tx-'.substr(md5($fx->key), 0, 8);
    }

    public function reconcile(Effect $fx): Outcome
    {
        $found = DB::table('charge_calls')->where('key', $fx->key)->exists();

        return $found ? Outcome::succeeded('tx-'.substr(md5($fx->key), 0, 8)) : Outcome::notExecuted();
    }
}

#[Idempotent(retry: new Retry(attempts: 5, initialInterval: '1 second', jitter: false, attemptTimeout: '2 seconds'))]
final class CountedNotify
{
    public function __invoke(Effect $fx): void
    {
        DB::table('notify_calls')->insert(['key' => $fx->key, 'pid' => getmypid()]);
    }
}

final class ConcurrentOrder extends Process
{
    public const VERSION = 1;

    public function define(Builder $p): void
    {
        $p->state('new', fn (Builder $s) => $s->on('submit', to: 'charging'));
        $p->state('charging', fn (Builder $s) => $s
            ->entry(CountedNotify::class)
            ->invoke(CountedCharge::class, into: 'payment', onDone: 'waiting'));
        $p->state('waiting', fn (Builder $s) => $s->on('ship', to: 'shipped'));
        $p->final('shipped');
    }
}
