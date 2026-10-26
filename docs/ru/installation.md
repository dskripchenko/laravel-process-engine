# Установка и настройка

## Установка

```bash
composer require dskripchenko/laravel-process-engine
php artisan migrate
```

Сервис-провайдер подключается автоматически и загружает свои миграции.
Чтобы изменить их или конфигурацию, опубликуйте:

```bash
php artisan vendor:publish --tag=process-engine-config
php artisan vendor:publish --tag=process-engine-migrations
php artisan vendor:publish --tag=process-engine-stubs      # make:process templates
```

## Что должно работать

| Компонент | Как |
|---|---|
| воркер очереди | `php artisan queue:work` — выполняет шаги экземпляров и эффекты (задачи `RunInstance`, `ExecuteEffect`) |
| планировщик | `schedule:run` каждую минуту — провайдер регистрирует `process-engine:tick` (каждую минуту) и `process-engine:archive` (ежедневно) |

Без очереди: `php artisan process-engine:work --sync` делает тик каждую
секунду и выполняет работу в том же процессе. Без планировщика: запустите
`process-engine:work` (только тики, работу делает очередь) — это заодно даёт
таймерам точность до секунды.

Потерянные задачи ничего не теряют: тик находит в базе данных ожидающие
события и эффекты, которым пора выполниться, и снова их диспетчеризует.
Задачи отправляются после фиксации окружающей транзакции.

## Конфигурация (`config/process-engine.php`)

| Ключ | По умолчанию | |
|---|---|---|
| `connection` | соединение по умолчанию | соединение с базой данных для таблиц движка |
| `table_prefix` | `pe_` | |
| `queue.connection`, `queue.name` | по умолчанию, `default` | куда уходят задачи |
| `secret` | `APP_KEY` | подписывает токены задач |
| `schedule.enabled`, `.tick`, `.archive` | включено | регистрировать тик и архивацию в планировщике |
| `options.lease_ttl` | `2 minutes` | сколько воркер владеет экземпляром, пока выполняет его шаг |
| `options.events_per_run` | 100 | событий на одну задачу, прежде чем уступить |
| `options.max_context_bytes` | 262144 | лимит размера контекста |
| `options.message_ttl` | `7 days` | сколько опубликованное событие ждёт подписчика |
| `options.defer_ttl` | `30 days` | сколько ждёт отложенное событие |
| `options.sweep_after` | `30 seconds` | ожидающие события старше этого тик отправляет заново |
| `options.archive_after` | `30 days` | завершённые экземпляры старше этого архивируются |
| `options.overdue_after` | `5 minutes` | таймер, опоздавший больше чем на это время, считается просроченным в `status` |
| `options.poll_backoff_max` | `1 hour` | интервал упавшего опроса после исчерпания повторов |
| `flags` | `[]` | `name => bool` для гардов `flag('name')` |
| `allow_redefine_unused` | false | разрешить менять сохранённую версию, которую не использует ни один экземпляр (локальная разработка) |
| `processes` | `[]` | классы, которые проверяет `process-engine:validate` |
| `migrations` | `[]` | invokable-классы, возвращающие `Migration`, регистрируются при загрузке |
| `delayed_process.forward` | true | пробрасывать завершения laravel-delayed-process |

## Базы данных

PostgreSQL, MySQL 8 / MariaDB и SQLite поддерживаются и протестированы.
JSON-колонки используют нативный тип, где он есть. Для SQLite с несколькими
воркерами задайте в соединении:

```php
'journal_mode' => 'wal',
'busy_timeout' => 10000,
```

Хранилище само берёт блокировку на запись в начале транзакции, поэтому
`transaction_mode` не нужен (Laravel всё равно применяет его только с PHP 8.4).

## Замена компонентов

Привяжите свои реализации в сервис-провайдере:

| Интерфейс | По умолчанию |
|---|---|
| `Dskripchenko\ProcessEngine\Interpreter\FlagProvider` | флаги из конфигурации |
| `Dskripchenko\ProcessEngine\Interpreter\BusinessCalendar` | с понедельника по пятницу, без праздников |
| `Dskripchenko\ProcessEngine\Runtime\Projector` | записывает сопоставления `project()` в колонки моделей |
| `Dskripchenko\ProcessEngine\Runtime\Dispatcher` | задачи очереди |
| `Dskripchenko\ProcessEngine\Runtime\Clock` | Carbon (учитывает `Carbon::setTestNow()` / `travel()`) |

## Чек-лист для продакшена

- Работают воркер очереди и планировщик (или `process-engine:work`).
- `php artisan process-engine:validate --path=app/Processes` в CI.
- `php artisan process-engine:doctor` перед удалением классов эффектов или
  методов процессов: живые версии могут ещё их использовать.
- `php artisan process-engine:status --fail-on-problems` в проверках
  здоровья (открытые инциденты, просроченные таймеры, застрявшие эффекты).
- Слушайте `ProcessSignal` на `incident.opened` и оповещайте ответственного.
