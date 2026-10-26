<?php

declare(strict_types=1);

use Dskripchenko\LaravelProcessEngine\EngineFactory;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\ConcurrentOrder;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Runtime\SyncDispatcher;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Real processes against a real database: forked workers race on the same
 * instances, duplicate events arrive concurrently, a worker is killed with
 * SIGKILL in the middle of an at-most-once call.
 */
beforeEach(function () {
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('pcntl is required');
    }
    if ((getenv('PE_DB') ?: 'sqlite') === 'sqlite') {
        $file = sys_get_temp_dir().'/pe-concurrency-'.getmypid().'.sqlite';
        @unlink($file);
        touch($file);
        config(['database.connections.testing' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => '', 'foreign_key_constraints' => false, 'busy_timeout' => 15000, 'journal_mode' => 'wal', 'transaction_mode' => 'IMMEDIATE']]);
        DB::purge('testing');
        $this->artisan('migrate:fresh')->run();
    }
    config(['process-engine.options.lease_ttl' => '3 seconds', 'process-engine.options.sweep_after' => '1 second']);
    app()->forgetInstance(Engine::class);
    foreach (['charge_calls', 'notify_calls'] as $table) {
        Schema::dropIfExists($table);
        Schema::create($table, function (Blueprint $t) {
            $t->id();
            $t->string('key', 512);
            $t->integer('pid');
        });
    }
});

/**
 * @param  callable(Engine): void  $work
 * @return list<int>
 */
function forkWorkers(int $count, callable $work): array
{
    $pids = [];
    for ($i = 0; $i < $count; $i++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            DB::purge('testing');
            DB::reconnect('testing');
            $engine = app(EngineFactory::class)->make(new SyncDispatcher);
            try {
                $work($engine);
            } catch (Throwable $e) {
                fwrite(STDERR, 'worker '.getmypid().': '.$e->getMessage()."\n");
            }
            posix_kill(getmypid(), SIGKILL);
        }
        $pids[] = $pid;
    }

    return $pids;
}

function settle(Engine $engine, int $seconds = 12): void
{
    $until = microtime(true) + $seconds;
    while (microtime(true) < $until) {
        $engine->tick();
        $ran = $engine->drain(500);
        if ($ran === 0) {
            usleep(250_000);
        }
    }
}

it('keeps instances consistent with several workers racing on duplicate events', function () {
    $engine = app(EngineFactory::class)->make(new SyncDispatcher);
    $ids = [];
    for ($i = 0; $i < 8; $i++) {
        $ids[] = $engine->start(ConcurrentOrder::class)->id();
    }
    DB::disconnect('testing');

    $pids = forkWorkers(4, function (Engine $engine) use ($ids) {
        $until = microtime(true) + 6;
        while (microtime(true) < $until) {
            foreach ($ids as $id) {
                $engine->send($id, 'submit', key: 'submit-'.$id);
                $engine->runInstance($id);
            }
            $engine->tick();
            $engine->drain(200);
            usleep(random_int(1_000, 20_000));
        }
    });
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
    DB::reconnect('testing');
    settle($engine, 6);

    foreach ($ids as $id) {
        $view = $engine->get($id);
        expect($view->active())->toBe(['waiting'], "instance {$id}: ".implode(',', $view->active()));
        $submits = array_filter($view->journal(), fn ($j) => $j->type === 'step' && ($j->data['event']['name'] ?? '') === 'submit');
        expect($submits)->toHaveCount(1);
    }
    $calls = DB::table('charge_calls')->select('key', DB::raw('count(*) as n'))->groupBy('key')->get();
    expect($calls)->toHaveCount(8)->and($calls->max('n'))->toBe(1);
})->group('concurrency');

it('turns a worker killed mid-call into unknown and reconciles without a second call', function () {
    $engine = app(EngineFactory::class)->make(new SyncDispatcher);
    $id = $engine->start(ConcurrentOrder::class)->id();
    DB::disconnect('testing');

    putenv('PE_SLOW_CHARGE=30');
    [$pid] = forkWorkers(1, function (Engine $engine) use ($id) {
        $engine->send($id, 'submit', key: 'submit-1');
        $engine->drain(100);
    });
    putenv('PE_SLOW_CHARGE');
    DB::reconnect('testing');

    // Wait until the charge started, then kill the worker in the middle of the call.
    $deadline = microtime(true) + 15;
    while (DB::table('charge_calls')->count() === 0 && microtime(true) < $deadline) {
        usleep(100_000);
    }
    posix_kill($pid, SIGKILL);
    pcntl_waitpid($pid, $status);
    expect(DB::table('charge_calls')->count())->toBe(1);

    settle($engine, 10);

    $view = $engine->get($id);
    expect($view->active())->toBe(['waiting'])
        ->and(DB::table('charge_calls')->count())->toBe(1)
        ->and(array_map(fn ($j) => $j->type, $view->journal()))->toContain('effect.lease_expired', 'effect.unknown', 'effect.reconciled');
})->group('concurrency');
