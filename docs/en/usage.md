# Using it in an application

## Define processes and effects

Processes and effects are plain classes; generate them:

```bash
php artisan make:process ClaimSettlement                  # app/Processes/ClaimSettlement.php
php artisan make:process-effect RenderApplication         # #[Idempotent]
php artisan make:process-effect PayOut --at-most-once     # #[AtMostOnce] with reconcile()
```

Effect handlers come from the container, so constructor injection works:

```php
#[Idempotent]
final class RenderApplication
{
    public function __construct(private readonly DocumentRenderer $renderer) {}

    public function __invoke(ClaimContext $claim): string
    {
        return $this->renderer->render('claim-application', $claim->application)->id;
    }
}
```

How to write processes is described in the
[core documentation](https://github.com/dskripchenko/process-engine#documentation).
Nothing has to be registered: a process version is stored on its first start.

## The facade

```php
use Dskripchenko\LaravelProcessEngine\Facades\Processes;
use Dskripchenko\ProcessEngine\Actor;

$view = Processes::start(ClaimSettlement::class, new ClaimContext(policyId: $policy->id), key: "claim:{$claim->id}");

// a controller: the form is validated by Laravel as usual
Processes::send($claim->process_id, new FormSubmitted($request->validated()),
    key: $request->header('Idempotency-Key'), by: Actor::user($request->user()->id));

// a webhook: routed by correlation, duplicates dropped by the message id
Processes::publish(new PartyDecision($payload['approved'], $payload['reason'] ?? null),
    correlate: $payload['application_id'], key: $payload['message_id'], by: Actor::party('insurer'));

$view = Processes::get($id);
$view->label();     // the client-facing status
$view->expects();   // the buttons to show
```

The facade proxies `Dskripchenko\ProcessEngine\Engine` (a singleton); inject
`Engine` instead where you prefer.

## Binding processes to models

```php
use Dskripchenko\LaravelProcessEngine\Concerns\HasProcesses;

final class Claim extends Model
{
    use HasProcesses;
}
```

```php
$claim->startProcess(ClaimSettlement::class, $context);         // or Processes::start(..., subject: $claim)
$claim->process(ClaimSettlement::class);                        // the latest instance for this model
$claim->processInstances()->get();                              // every instance (Eloquent, read-only)

Claim::whereProcessIn('awaitingDocuments')->paginate();          // indexed: pe_active_states
Claim::whereProcessIn('review', ClaimSettlement::class)->count();
Claim::whereProcessStatus('running')->get();
```

### Projecting the state into a column

Declare in the process which column of the model mirrors which states:

```php
use function Dskripchenko\ProcessEngine\project;

public function projections(): array
{
    return [project(Claim::class, 'status', [
        'filling'           => ClaimStatus::Draft,
        'review'            => ClaimStatus::InReview,
        'awaitingDocuments' => ClaimStatus::WaitingForDocuments,
        'closed'            => ClaimStatus::Settled,
    ])];
}
```

The column is written in the same transaction as each step (the deepest
mapped active state wins; a finished instance maps its final state). Treat it
as read-only: the instance is the source of truth. Existing indexes,
filters, admin lists and code that reads `status` keep working.

## Events and listeners

Engine signals arrive as `Dskripchenko\LaravelProcessEngine\Events\ProcessSignal`:

```php
Event::listen(function (ProcessSignal $signal) {
    if ($signal->type === 'incident.opened') {
        Notification::route('slack', config('alerts.slack'))->notify(new ProcessIncident($signal));
    }
});
```

## laravel-delayed-process

When dskripchenko/laravel-delayed-process is installed, its completions are
published as `delayed-process.completed` / `delayed-process.failed`,
correlated by the delayed process uuid:

```php
use Dskripchenko\DelayedProcess\Contracts\ProcessFactoryInterface;

#[Idempotent]
final class StartReport
{
    public function __construct(private readonly ProcessFactoryInterface $processes) {}

    public function __invoke(array $context): string
    {
        return $this->processes->make(ReportBuilder::class, 'build', $context['period'])->uuid;
    }
}

$p->state('building', fn (Builder $s) => $s
    ->invoke(StartReport::class, into: 'reportUuid', onDone: 'waitingForReport'));
$p->state('waitingForReport', fn (Builder $s) => $s
    ->correlate(['delayed-process.completed', 'delayed-process.failed'], by: 'reportUuid')
    ->on('delayed-process.completed', to: 'ready')
    ->on('delayed-process.failed', to: 'failed'));
```

## Artisan

| Command | |
|---|---|
| `process-engine:tick` | timers, recovery of dead workers' effects, re-dispatch (scheduled every minute) |
| `process-engine:work [--sync] [--sleep=1]` | a loop ticking every second; `--sync` also does the work without a queue |
| `process-engine:status [--json] [--fail-on-problems]` | instances by status, incidents, overdue timers, stuck effects |
| `process-engine:doctor` | live versions whose methods or effect classes are gone |
| `process-engine:migrate {name} [--apply --reason=]` | dry run or apply a registered migration |
| `process-engine:archive` | archive finished instances (scheduled daily) |
| `process-engine:validate [classes] [--path=]` | build and check definitions (CI) |
| `process-engine:graph {process} [--format=mermaid\|flow] [--path=]` | print a graph |
| `make:process`, `make:process-effect [--at-most-once]` | generators |

## Testing

`ProcessTest` from the core runs a process in memory with a virtual clock and
fakes (see the core's testing guide). To run against your database inside a
Laravel test:

```php
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

$t = new ProcessTest(store: app(Store::class));
$t->begin(ClaimSettlement::class, $context)->send(...)->assertIn('review');
```

With the `sync` queue and Laravel's `travel()`, the facade runs everything
inline: `Processes::send(...)`, then `$this->travel(3)->days()` and
`$this->artisan('process-engine:tick')` fire the timers.
