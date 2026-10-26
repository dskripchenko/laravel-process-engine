# Использование в приложении

## Описание процессов и эффектов

Процессы и эффекты — обычные классы; сгенерируйте их:

```bash
php artisan make:process ClaimSettlement                  # app/Processes/ClaimSettlement.php
php artisan make:process-effect RenderApplication         # #[Idempotent]
php artisan make:process-effect PayOut --at-most-once     # #[AtMostOnce] with reconcile()
```

Обработчики эффектов берутся из контейнера, поэтому внедрение через
конструктор работает:

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

Как писать процессы, описано в
[документации ядра](https://github.com/dskripchenko/process-engine#documentation).
Ничего регистрировать не нужно: версия процесса сохраняется при первом старте.

## Фасад

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

Фасад проксирует `Dskripchenko\ProcessEngine\Engine` (синглтон); если
удобнее, внедряйте `Engine` напрямую.

## Привязка процессов к моделям

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

### Проекция состояния в колонку

Объявите в процессе, какая колонка модели отражает какие состояния:

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

Колонка записывается в той же транзакции, что и каждый шаг (побеждает самое
глубокое сопоставленное активное состояние; завершённый экземпляр
отображает своё финальное состояние). Считайте её только для чтения:
источник истины — экземпляр. Существующие индексы, фильтры, списки в админке
и код, читающий `status`, продолжают работать.

## События и слушатели

Сигналы движка приходят как `Dskripchenko\LaravelProcessEngine\Events\ProcessSignal`:

```php
Event::listen(function (ProcessSignal $signal) {
    if ($signal->type === 'incident.opened') {
        Notification::route('slack', config('alerts.slack'))->notify(new ProcessIncident($signal));
    }
});
```

## laravel-delayed-process

Если установлен dskripchenko/laravel-delayed-process, его завершения
публикуются как `delayed-process.completed` / `delayed-process.failed` с
корреляцией по uuid отложенного процесса:

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

| Команда | |
|---|---|
| `process-engine:tick` | таймеры, восстановление эффектов умерших воркеров, повторная диспетчеризация (по расписанию каждую минуту) |
| `process-engine:work [--sync] [--sleep=1]` | цикл с тиком каждую секунду; `--sync` ещё и выполняет работу без очереди |
| `process-engine:status [--json] [--fail-on-problems]` | экземпляры по статусам, инциденты, просроченные таймеры, застрявшие эффекты |
| `process-engine:doctor` | живые версии, у которых пропали методы или классы эффектов |
| `process-engine:migrate {name} [--apply --reason=]` | пробный прогон или применение зарегистрированной миграции |
| `process-engine:archive` | архивировать завершённые экземпляры (по расписанию ежедневно) |
| `process-engine:validate [classes] [--path=]` | собрать и проверить описания (CI) |
| `process-engine:graph {process} [--format=mermaid\|flow] [--path=]` | вывести граф |
| `make:process`, `make:process-effect [--at-most-once]` | генераторы |

## Тестирование

`ProcessTest` из ядра запускает процесс в памяти с виртуальными часами и
фейками (см. руководство по тестированию в ядре). Чтобы запустить его на
вашей базе данных внутри теста Laravel:

```php
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

$t = new ProcessTest(store: app(Store::class));
$t->begin(ClaimSettlement::class, $context)->send(...)->assertIn('review');
```

С очередью `sync` и `travel()` из Laravel фасад выполняет всё сразу:
`Processes::send(...)`, затем `$this->travel(3)->days()` и
`$this->artisan('process-engine:tick')` запускают таймеры.
