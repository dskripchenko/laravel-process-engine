<?php

declare(strict_types=1);

use Dskripchenko\LaravelProcessEngine\Facades\Processes;
use Dskripchenko\LaravelProcessEngine\Jobs\ExecuteEffect;
use Dskripchenko\LaravelProcessEngine\Support\EffectRoutes;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\ClaimFlow;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\LoadRegion;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\PlainCall;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\RoutedFlow;
use Dskripchenko\ProcessEngine\Store\Store;
use Illuminate\Support\Facades\Queue;

/**
 * @return array<string, ExecuteEffect> handler class => its queued job
 */
function effectJobs(): array
{
    $jobs = [];
    foreach (Queue::pushed(ExecuteEffect::class) as $job) {
        $jobs[app(Store::class)->findEffect($job->effectId)->class] = $job;
    }

    return $jobs;
}

it('queues an effect where its #[OnQueue] says, with the job timeout of its attempt', function () {
    Queue::fake();
    Processes::start(RoutedFlow::class);

    $jobs = effectJobs();
    expect($jobs[LoadRegion::class]->queue)->toBe('slow')
        ->and($jobs[LoadRegion::class]->timeout)->toBe(6 * 3600 + 30)
        ->and($jobs[PlainCall::class]->queue)->toBe('default')
        ->and($jobs[PlainCall::class]->timeout)->toBe(120 + 30);
});

it('lets the routes of the config override the attribute', function () {
    config()->set('process-engine.routes', [LoadRegion::class => ['queue' => 'gar', 'connection' => 'redis'], PlainCall::class => 'fast']);
    app()->forgetInstance(EffectRoutes::class);
    Queue::fake();
    Processes::start(RoutedFlow::class);

    $jobs = effectJobs();
    expect($jobs[LoadRegion::class]->queue)->toBe('gar')
        ->and($jobs[LoadRegion::class]->connection)->toBe('redis')
        ->and($jobs[PlainCall::class]->queue)->toBe('fast');
});

it('starts exclusively: one unfinished instance per lock, a new one once it finished', function () {
    $first = Processes::startExclusive(ClaimFlow::class, 'claims:nightly');
    $again = Processes::startExclusive(ClaimFlow::class, 'claims:nightly');
    $other = Processes::startExclusive(ClaimFlow::class, 'claims:other');
    expect($again->id())->toBe($first->id())->and($other->id())->not->toBe($first->id());

    Processes::send($first->id(), 'submit');
    Processes::send($first->id(), 'approve');
    expect(Processes::get($first->id())->isFinished())->toBeTrue();

    $next = Processes::startExclusive(ClaimFlow::class, 'claims:nightly');
    expect($next->id())->not->toBe($first->id())->and($next->isFinished())->toBeFalse();
});
