# 数据流水线

桥接包为核心的批次和可关闭阶段
（[流水线](https://github.com/dskripchenko/process-engine/blob/main/docs/zh/pipelines.md)）
增加了：数据库存储、按副作用划分的队列、用于定时任务的独占启动，以及保存在表中的开关。

升级后请运行迁移：会新增 `pe_batches`、`pe_batch_items`、`pe_switches`、`pe_exclusive`。

## 按副作用划分的队列

把一个只有单个 worker 支撑的慢速外部服务、一次长达六小时的加载，与短调用隔离开：

```php
use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;

#[Idempotent(retry: new Retry(attemptTimeout: '6 hours'))]
#[OnQueue('gar')]
final class LoadRegion implements BatchHandler { /* … */ }
```

或者在配置中设置，配置会覆盖属性：

```php
'routes' => [
    NspdLookup::class => 'nspd',
    LoadRegion::class => ['queue' => 'gar', 'connection' => 'redis'],
],
```

副作用（或批次的一个块）的任务会发往其路由；任务的超时等于其重试策略中的尝试超时
再加 30 秒，因此队列永远不会杀掉引擎仍认为存活的尝试。请让队列的 `retry_after` 大于
其中最长的超时。实例的推进仍在引擎的队列（`process-engine.queue`）中进行。

## 独占启动

```php
Processes::startExclusive(Monitoring::class, 'monitoring');
Processes::startExclusive(RiskSurface::class, "surface:{$profile}", ['profile' => $profile]);
```

除非有未结束的实例持有锁，否则启动新实例——若有，则返回那个实例。适用于不能相互
重叠的定时任务。锁在其实例结束时自动释放。

## 开关

`FlagProvider` 绑定到 `Switches`：`flag()` 守卫照旧读取 `process-engine.flags`，
可关闭状态的开关则保存在 `pe_switches` 中。新开关按 `process-engine.switches` 的
设置开始（未列出时为开启）；一旦被修改，就由表来管理。每次检查都会读取表，因此长时间
运行的 worker 能立即看到别处做出的修改。

```php
Processes::switchOff('enrichment.dadata', Actor::user($id, $name), 'no key yet');
Processes::switchOn('enrichment.dadata', Actor::user($id, $name));
app(Switches::class)->all();   // every stored switch with who, when and why
```

自动关闭会以 `ProcessSignal` 事件的形式到达，其类型为 `switch.changed`，且
`data.by.type = system`：在监听器中通知相关人员。

## 负载

在 PostgreSQL 上，引擎自身每秒可处理约 10 000 个批次元素，直到一百万个都保持平稳。
要在你自己的硬件上测量：

```bash
PE_LOAD=1000000 PE_DB=pgsql PE_DB_USERNAME=… PE_DB_PASSWORD=… vendor/bin/pest tests/Feature/LoadTest.php
```
