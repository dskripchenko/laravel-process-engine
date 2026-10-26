# Verwendung in einer Anwendung

## Prozesse und Effekte definieren

Prozesse und Effekte sind gewöhnliche Klassen; generieren Sie sie:

```bash
php artisan make:process ClaimSettlement                  # app/Processes/ClaimSettlement.php
php artisan make:process-effect RenderApplication         # #[Idempotent]
php artisan make:process-effect PayOut --at-most-once     # #[AtMostOnce] with reconcile()
```

Effekt-Handler kommen aus dem Container, daher funktioniert
Constructor Injection:

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

Wie Prozesse geschrieben werden, beschreibt die
[Kerndokumentation](https://github.com/dskripchenko/process-engine#documentation).
Nichts muss registriert werden: Eine Prozessversion wird bei ihrem ersten Start
gespeichert.

## Die Facade

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

Die Facade leitet an `Dskripchenko\ProcessEngine\Engine` (ein Singleton) weiter;
injizieren Sie stattdessen `Engine`, wo Ihnen das lieber ist.

## Prozesse an Modelle binden

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

### Den Zustand in eine Spalte projizieren

Deklarieren Sie im Prozess, welche Spalte des Modells welche Zustände
widerspiegelt:

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

Die Spalte wird in derselben Transaktion wie jeder Schritt geschrieben (der
tiefste zugeordnete aktive Zustand gewinnt; eine beendete Instanz bildet ihren
Endzustand ab). Behandeln Sie sie als schreibgeschützt: Die Instanz ist die
maßgebliche Quelle. Bestehende Indizes, Filter, Admin-Listen und Code, der
`status` liest, funktionieren weiter.

## Ereignisse und Listener

Engine-Signale kommen als `Dskripchenko\LaravelProcessEngine\Events\ProcessSignal` an:

```php
Event::listen(function (ProcessSignal $signal) {
    if ($signal->type === 'incident.opened') {
        Notification::route('slack', config('alerts.slack'))->notify(new ProcessIncident($signal));
    }
});
```

## laravel-delayed-process

Ist dskripchenko/laravel-delayed-process installiert, werden seine Abschlüsse
als `delayed-process.completed` / `delayed-process.failed` veröffentlicht,
korreliert über die UUID des verzögerten Prozesses:

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

| Befehl | |
|---|---|
| `process-engine:tick` | Timer, Wiederherstellung von Effekten toter Worker, erneutes Dispatchen (jede Minute geplant) |
| `process-engine:work [--sync] [--sleep=1]` | eine Schleife, die jede Sekunde tickt; `--sync` erledigt zusätzlich die Arbeit ohne Queue |
| `process-engine:status [--json] [--fail-on-problems]` | Instanzen nach Status, Incidents, überfällige Timer, hängende Effekte |
| `process-engine:doctor` | lebende Versionen, deren Methoden oder Effektklassen verschwunden sind |
| `process-engine:migrate {name} [--apply --reason=]` | Probelauf oder Anwendung einer registrierten Migration |
| `process-engine:archive` | abgeschlossene Instanzen archivieren (täglich geplant) |
| `process-engine:validate [classes] [--path=]` | Definitionen bauen und prüfen (CI) |
| `process-engine:graph {process} [--format=mermaid\|flow] [--path=]` | einen Graphen ausgeben |
| `make:process`, `make:process-effect [--at-most-once]` | Generatoren |

## Testen

`ProcessTest` aus dem Kern führt einen Prozess im Speicher mit virtueller Uhr
und Fakes aus (siehe die Testanleitung des Kerns). Um innerhalb eines
Laravel-Tests gegen Ihre Datenbank zu laufen:

```php
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

$t = new ProcessTest(store: app(Store::class));
$t->begin(ClaimSettlement::class, $context)->send(...)->assertIn('review');
```

Mit der `sync`-Queue und Laravels `travel()` führt die Facade alles inline aus:
`Processes::send(...)`, dann lösen `$this->travel(3)->days()` und
`$this->artisan('process-engine:tick')` die Timer aus.
