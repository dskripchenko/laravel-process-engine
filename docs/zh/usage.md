# 在应用中使用

## 定义流程和副作用

流程和副作用都是普通类；可以用命令生成：

```bash
php artisan make:process ClaimSettlement                  # app/Processes/ClaimSettlement.php
php artisan make:process-effect RenderApplication         # #[Idempotent]
php artisan make:process-effect PayOut --at-most-once     # #[AtMostOnce] with reconcile()
```

副作用处理器来自容器，因此可以使用构造函数注入：

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

如何编写流程，请参阅
[核心文档](https://github.com/dskripchenko/process-engine#documentation)。
无需注册任何东西：流程版本在首次启动时被存储。

## Facade

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

该 Facade 代理 `Dskripchenko\ProcessEngine\Engine`（单例）；如果你更喜欢，也可以直接
注入 `Engine`。

## 将流程绑定到模型

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

### 将状态投影到列

在流程中声明模型的哪一列对应哪些状态：

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

该列与每一步在同一事务中写入（以最深的已映射活动状态为准；已结束的实例映射其终态）。
请将其视为只读：实例才是事实来源。现有的索引、过滤器、后台列表以及读取 `status` 的
代码都能继续正常工作。

## 事件与监听器

引擎信号以 `Dskripchenko\LaravelProcessEngine\Events\ProcessSignal` 的形式到达：

```php
Event::listen(function (ProcessSignal $signal) {
    if ($signal->type === 'incident.opened') {
        Notification::route('slack', config('alerts.slack'))->notify(new ProcessIncident($signal));
    }
});
```

## laravel-delayed-process

安装了 dskripchenko/laravel-delayed-process 后，它的完成事件会以
`delayed-process.completed` / `delayed-process.failed` 的形式发布，并按延迟流程的
uuid 进行关联：

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

| 命令 | |
|---|---|
| `process-engine:tick` | 定时器、恢复已崩溃 worker 的副作用、重新分派（每分钟调度一次） |
| `process-engine:work [--sync] [--sleep=1]` | 每秒执行一次 tick 的循环；`--sync` 还会在没有队列的情况下完成工作 |
| `process-engine:status [--json] [--fail-on-problems]` | 按状态统计实例、事故、逾期定时器、卡住的副作用 |
| `process-engine:doctor` | 方法或副作用类已不存在的存活版本 |
| `process-engine:migrate {name} [--apply --reason=]` | 试运行或应用已注册的迁移 |
| `process-engine:archive` | 归档已结束的实例（每天调度一次） |
| `process-engine:validate [classes] [--path=]` | 构建并检查定义（CI） |
| `process-engine:graph {process} [--format=mermaid\|flow] [--path=]` | 输出图 |
| `make:process`, `make:process-effect [--at-most-once]` | 生成器 |

## 测试

核心包中的 `ProcessTest` 在内存中运行流程，提供虚拟时钟和伪造对象（参见核心包的测试
指南）。如需在 Laravel 测试中针对你的数据库运行：

```php
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Testing\ProcessTest;

$t = new ProcessTest(store: app(Store::class));
$t->begin(ClaimSettlement::class, $context)->send(...)->assertIn('review');
```

使用 `sync` 队列和 Laravel 的 `travel()` 时，Facade 会内联执行所有工作：先调用
`Processes::send(...)`，然后 `$this->travel(3)->days()` 和
`$this->artisan('process-engine:tick')` 会触发定时器。
