<?php

declare(strict_types=1);

use Dskripchenko\LaravelProcessEngine\Events\ProcessSignal;
use Dskripchenko\LaravelProcessEngine\Facades\Processes;
use Dskripchenko\LaravelProcessEngine\Models\ProcessInstance;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\Claim;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\ClaimFlow;
use Dskripchenko\LaravelProcessEngine\Tests\Fixtures\Mailer;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Tests\Fixtures\Runtime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;

it('runs a process through the facade on the database with the sync queue', function () {
    app()->instance(Mailer::class, $mailer = new Mailer);
    $claim = Claim::create();
    $view = Processes::start(ClaimFlow::class, ['to' => 'client@example.com'], subject: $claim);
    expect($view->active())->toBe(['draft'])->and($claim->fresh()->status)->toBe('Draft');

    Processes::send($view->id(), 'submit');
    $view = Processes::get($view->id());
    expect($view->active())->toBe(['review'])
        ->and($view->contextArray()['mail'])->toBe('mail-1')
        ->and($mailer->sent)->toBe(['client@example.com'])
        ->and($claim->fresh()->status)->toBe('In review');

    Processes::send($view->id(), 'approve');
    expect(Processes::get($view->id())->final())->toBe('approved')->and($claim->fresh()->status)->toBe('Approved');
});

it('fires timers from the scheduled tick', function () {
    $claim = Claim::create();
    $id = $claim->startProcess(ClaimFlow::class, ['to' => 'x@example.com'])->id();
    Processes::send($id, 'submit');

    $this->travel(3)->days();
    $this->artisan('process-engine:tick')->assertSuccessful();

    expect(Processes::get($id)->final())->toBe('expired')->and($claim->fresh()->status)->toBe('Expired');
});

it('finds models by process state and binds instances to them', function () {
    $a = Claim::create();
    $b = Claim::create();
    Claim::create();
    $a->startProcess(ClaimFlow::class);
    $b->startProcess(ClaimFlow::class);
    Processes::send($b->process(ClaimFlow::class)->id(), 'submit');

    expect(Claim::whereProcessIn('draft')->pluck('id')->all())->toBe([$a->id])
        ->and(Claim::whereProcessIn('review', ClaimFlow::class)->pluck('id')->all())->toBe([$b->id])
        ->and(Claim::whereProcessStatus('running')->count())->toBe(2)
        ->and($a->processInstances()->count())->toBe(1)
        ->and(ProcessInstance::query()->count())->toBe(2);
});

it('forwards engine signals as Laravel events', function () {
    Event::fake([ProcessSignal::class]);
    Processes::start(ClaimFlow::class);
    Event::assertDispatched(ProcessSignal::class, fn (ProcessSignal $s) => $s->type === 'instance.started');
});

it('reports status, validates, draws and archives from the console', function () {
    Processes::start(ClaimFlow::class);
    $this->artisan('process-engine:status --json')->assertSuccessful();
    $this->artisan('process-engine:validate', ['classes' => [ClaimFlow::class]])->expectsOutputToContain('claim-flow@1')->assertSuccessful();
    $this->artisan('process-engine:graph', ['process' => ClaimFlow::class])->expectsOutputToContain('stateDiagram-v2')->assertSuccessful();
    $this->artisan('process-engine:graph', ['process' => 'claim-flow', '--format' => 'flow'])->expectsOutputToContain('"version": 1')->assertSuccessful();
    $this->artisan('process-engine:doctor')->assertSuccessful();
    $this->artisan('process-engine:archive')->assertSuccessful();
});

it('registers the tick and archive in the scheduler', function () {
    $events = collect(app(Schedule::class)->events())->map(fn ($e) => $e->command)->implode(' ');
    expect($events)->toContain('process-engine:tick')->toContain('process-engine:archive');
});

it('generates process and effect classes', function () {
    $this->artisan('make:process', ['name' => 'RefundFlow'])->assertSuccessful();
    $this->artisan('make:process-effect', ['name' => 'Refund', '--at-most-once' => true])->assertSuccessful();
    $process = file_get_contents(app_path('Processes/RefundFlow.php'));
    $effect = file_get_contents(app_path('Processes/Effects/Refund.php'));
    expect($process)->toContain('extends Process')->and($effect)->toContain('#[AtMostOnce]')->toContain('implements Reconcilable');
    @unlink(app_path('Processes/RefundFlow.php'));
    @unlink(app_path('Processes/Effects/Refund.php'));
});

it('starts idempotently by key under the database unique index', function () {
    $a = Processes::start(Runtime\Order::class, [], key: 'order-42');
    $b = Processes::start(Runtime\Order::class, [], key: 'order-42');
    expect($a->id())->toBe($b->id())->and(app(Engine::class)->instances())->toHaveCount(1);
});
