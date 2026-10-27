<?php

declare(strict_types=1);

use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches\Script;

/**
 * A batch at scale (ADR-014 §5): PE_LOAD=<elements> to run, e.g. PE_LOAD=1000000
 * with PE_DB=pgsql. Reports the time and the elements per second.
 */
it('works through a large batch without slowing down', function () {
    $n = (int) getenv('PE_LOAD');
    Script::reset();
    $t = new ProcessTest(store: app(Store::class), clock: new VirtualClock);

    $started = microtime(true);
    $id = $t->engine->start(Batches\Large::class, ['n' => $n])->id();
    $marks = [];
    $done = 0;
    while (true) {
        $ran = $t->engine->drain(500);
        $batch = $t->engine->batches($id)[0] ?? null;
        $finished = $batch !== null ? array_sum(array_intersect_key($batch->summary(), array_flip(['done', 'not_found', 'failed']))) : 0;
        if ($finished >= $done + intdiv($n, 10)) {
            $done = $finished;
            $marks[] = sprintf('%7d in %6.1fs', $done, microtime(true) - $started);
        }
        if ($ran === 0) {
            break;
        }
    }
    $seconds = microtime(true) - $started;
    fwrite(STDERR, "\n".implode("\n", $marks).sprintf("\n%d elements in %.1fs, %.0f/s\n", $n, $seconds, $n / $seconds));

    expect($t->view($id)->final())->toBe('done')
        ->and($t->engine->batches($id)[0]->summary()['total'])->toBe($n);
})->skip(fn () => ! getenv('PE_LOAD'), 'PE_LOAD=<elements> to run');
