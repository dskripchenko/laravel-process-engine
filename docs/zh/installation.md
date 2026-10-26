# 安装与配置

## 安装

```bash
composer require dskripchenko/laravel-process-engine
php artisan migrate
```

服务提供者会被自动发现，并加载其迁移。如需修改迁移或配置，请发布它们：

```bash
php artisan vendor:publish --tag=process-engine-config
php artisan vendor:publish --tag=process-engine-migrations
php artisan vendor:publish --tag=process-engine-stubs      # make:process templates
```

## 需要运行的组件

| 组件 | 方式 |
|---|---|
| 队列 worker | `php artisan queue:work`——推进实例并执行副作用（`RunInstance`、`ExecuteEffect` 任务） |
| 调度器 | 每分钟执行 `schedule:run`——服务提供者会注册 `process-engine:tick`（每分钟）和 `process-engine:archive`（每天） |

不使用队列时：`php artisan process-engine:work --sync` 每秒执行一次 tick，并在进程内
完成工作。不使用调度器时：运行 `process-engine:work`（只执行 tick，由队列完成工作），
这还能提供秒级的定时器精度。

丢失的任务不会造成任何损失：tick 会在数据库中找到待处理事件和到期的副作用，并重新
分派它们。任务在外层事务提交之后才分派。

## 配置（`config/process-engine.php`）

| 键 | 默认值 | |
|---|---|---|
| `connection` | 默认连接 | 引擎表所用的数据库连接 |
| `table_prefix` | `pe_` | |
| `queue.connection`, `queue.name` | 默认, `default` | 任务发往何处 |
| `secret` | `APP_KEY` | 为任务令牌签名 |
| `schedule.enabled`, `.tick`, `.archive` | 开启 | 在调度器中注册 tick 和归档 |
| `options.lease_ttl` | `2 minutes` | worker 推进实例时持有实例的时长 |
| `options.events_per_run` | 100 | 每个任务处理的事件数，达到后让出 |
| `options.max_context_bytes` | 262144 | 上下文大小上限 |
| `options.message_ttl` | `7 days` | 已发布事件等待订阅者的时长 |
| `options.defer_ttl` | `30 days` | 被延迟事件的等待时长 |
| `options.sweep_after` | `30 seconds` | 早于此时长的待处理事件会被 tick 重新分派 |
| `options.archive_after` | `30 days` | 结束超过此时长的实例会被归档 |
| `options.overdue_after` | `5 minutes` | 定时器延迟超过此时长，在 `status` 中计为逾期 |
| `options.poll_backoff_max` | `1 hour` | 失败的轮询在重试耗尽后的间隔 |
| `flags` | `[]` | 供 `flag('name')` 守卫使用的 `name => bool` |
| `allow_redefine_unused` | false | 允许修改尚无实例使用的已存储版本（本地开发） |
| `processes` | `[]` | 由 `process-engine:validate` 检查的类 |
| `migrations` | `[]` | 返回 `Migration` 的可调用类，在启动时注册 |
| `delayed_process.forward` | true | 转发 laravel-delayed-process 的完成事件 |

## 数据库

支持并测试了 PostgreSQL、MySQL 8 / MariaDB 和 SQLite。JSON 列在有原生类型的数据库上
使用原生类型。对于使用多个 worker 的 SQLite，请在连接上设置：

```php
'transaction_mode' => 'IMMEDIATE',
'journal_mode' => 'wal',
'busy_timeout' => 10000,
```

## 替换组件

在服务提供者中绑定你自己的实现：

| 接口 | 默认实现 |
|---|---|
| `Dskripchenko\ProcessEngine\Interpreter\FlagProvider` | 来自配置的开关 |
| `Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar` | 周一至周五，无节假日 |
| `Dskripchenko\ProcessEngine\Runtime\Projector` | 将 `project()` 映射写入模型列 |
| `Dskripchenko\ProcessEngine\Runtime\Dispatcher` | 队列任务 |
| `Dskripchenko\ProcessEngine\Runtime\Clock` | Carbon（遵循 `Carbon::setTestNow()` / `travel()`） |

## 生产环境检查清单

- 队列 worker 和调度器正在运行（或 `process-engine:work`）。
- 在 CI 中运行 `php artisan process-engine:validate --path=app/Processes`。
- 删除副作用类或流程方法之前运行 `php artisan process-engine:doctor`：存活的版本可能
  仍在使用它们。
- 在健康检查中运行 `php artisan process-engine:status --fail-on-problems`（未关闭的
  事故、逾期的定时器、卡住的副作用）。
- 监听 `ProcessSignal` 的 `incident.opened` 并通知相关人员。
