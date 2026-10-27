<?php

declare(strict_types=1);

use Dskripchenko\LaravelProcessEngine\Facades\Processes;
use Dskripchenko\LaravelProcessEngine\Support\Switches;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Runtime\VirtualClock;
use Dskripchenko\ProcessEngine\Store\BatchRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Batches\Script;

/**
 * Batches (ADR-014) and switches (ADR-015) on the database.
 */
beforeEach(fn () => Script::reset());

function pipelineTest(): ProcessTest
{
    return new ProcessTest(store: app(Store::class), clock: new VirtualClock);
}

it('works through a batch on the database store', function () {
    Script::$flaky = ['30' => 1, '31' => 9];
    $t = pipelineTest()->begin(Batches\Enrich::class, ['n' => 2500])->assertFinal('done');

    $batch = $t->engine->batches($t->id())[0];
    expect($batch->status)->toBe(BatchRecord::DONE)
        ->and($batch->summary())->toMatchArray(['done' => 2142, 'not_found' => 357, 'failed' => 1, 'total' => 2500])
        ->and(array_values(array_unique(Script::seen())))->toHaveCount(2500)
        ->and($t->engine->batchItems($batch->id, 'failed')[0]->key)->toBe('31')
        ->and($t->engine->batchItems($batch->id, null, 3, 10))->toHaveCount(3);
});

it('survives a crash inside a batch on the database store', function (string $point, int $skip) {
    $t = pipelineTest()->crashAt($point, $skip)->begin(Batches\Enrich::class, ['n' => 45]);
    $t->recover()->recover()->assertFinal('done');

    $batch = $t->engine->batches($t->id())[0];
    expect($batch->summary())->toMatchArray(['done' => 39, 'not_found' => 6, 'failed' => 0, 'deferred' => 0, 'total' => 45])
        ->and($batch->running)->toBe(0)
        ->and(array_filter($t->view()->journal(), fn ($j) => $j->type === 'batch.done'))->toHaveCount(1);
})->with([['batch.after_call', 1], ['batch.after_record', 2], ['effect.after_claim', 3]]);

it('archives batches with their elements', function () {
    $t = pipelineTest()->begin(Batches\Enrich::class, ['n' => 30])->assertFinal('done');
    $t->travel('31 days', '1 day');

    expect($t->engine->archive())->toBe(1)
        ->and(DB::table('pe_batches')->count())->toBe(0)
        ->and(DB::table('pe_batch_items')->count())->toBe(0);
});

it('keeps switches in the database: off from the admin, off by the engine, on again', function () {
    $operator = Actor::user('7', 'Operator');
    Processes::switchOff('enrichment.dadata', $operator, 'no key yet');
    expect(app(Switches::class)->state('enrichment.dadata'))->toMatchArray(['on' => false, 'reason' => 'no key yet'])
        ->and(DB::table('pe_switches')->where('name', 'enrichment.dadata')->value('on'))->toBeFalsy();

    $skipped = Processes::start(Batches\Pipeline::class, ['n' => 5]);
    expect(Processes::get($skipped->id())->active())->toBe(['merge'])->and(Script::$calls)->toBe([]);

    Processes::switchOn('enrichment.dadata', $operator, 'the key is set');
    Script::$fatal = '3';
    $failed = Processes::start(Batches\Pipeline::class, ['n' => 5]);
    expect(Processes::get($failed->id())->active())->toBe(['merge'])
        ->and(app(Switches::class)->state('enrichment.dadata'))->toMatchArray(['on' => false, 'reason' => 'the key was revoked'])
        ->and(app(Switches::class)->state('enrichment.dadata')['by']['type'])->toBe('system')
        ->and(array_keys(app(Switches::class)->all()))->toBe(['enrichment.dadata']);
});

it('starts a new switch as the config says', function () {
    config()->set('process-engine.switches', ['enrichment.newdb' => false]);

    expect(app(Switches::class)->isOn('enrichment.newdb'))->toBeFalse()
        ->and(app(Switches::class)->isOn('enrichment.dadata'))->toBeTrue();
});
