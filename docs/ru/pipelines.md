# Конвейеры данных

Что мост добавляет для пакетов и выключаемых этапов ядра
([конвейеры](https://github.com/dskripchenko/process-engine/blob/main/docs/ru/pipelines.md)):
хранение в базе данных, очередь для каждого эффекта, эксклюзивные запуски
для расписаний и переключатели, хранящиеся в таблице.

После обновления выполните миграции: добавляются `pe_batches`,
`pe_batch_items`, `pe_switches`, `pe_exclusive`.

## Очередь для каждого эффекта

Медленный внешний сервис за единственным воркером, шестичасовая загрузка
подальше от коротких вызовов:

```php
use Dskripchenko\LaravelProcessEngine\Attributes\OnQueue;

#[Idempotent(retry: new Retry(attemptTimeout: '6 hours'))]
#[OnQueue('gar')]
final class LoadRegion implements BatchHandler { /* … */ }
```

Или в конфиге, который переопределяет атрибут:

```php
'routes' => [
    NspdLookup::class => 'nspd',
    LoadRegion::class => ['queue' => 'gar', 'connection' => 'redis'],
],
```

Задача эффекта (или порции пакета) уходит по своему маршруту; таймаут
задачи — это таймаут попытки из её политики повтора плюс 30 секунд, так что
очередь никогда не убивает попытку, которую движок ещё считает живой.
Держите `retry_after` очереди выше самого долгого из них. Шаги экземпляров
по-прежнему выполняются в очереди движка (`process-engine.queue`).

## Эксклюзивные запуски

```php
Processes::startExclusive(Monitoring::class, 'monitoring');
Processes::startExclusive(RiskSurface::class, "surface:{$profile}", ['profile' => $profile]);
```

Запускает процесс, если блокировку не держит незавершённый экземпляр, — тогда
возвращает его. Для расписаний, запуски которых не должны пересекаться.
Блокировка освобождается сама, когда её экземпляр завершается.

## Переключатели

`FlagProvider` привязан к `Switches`: гарды `flag()`, как и раньше, читают
`process-engine.flags`, а переключатели выключаемых состояний живут в
`pe_switches`. Новый переключатель начинает так, как указано в
`process-engine.switches` (включён, если не указан); после первого изменения
им владеет таблица. Каждая проверка читает таблицу, поэтому долго работающий
воркер сразу видит изменение, сделанное в другом месте.

```php
Processes::switchOff('enrichment.dadata', Actor::user($id, $name), 'no key yet');
Processes::switchOn('enrichment.dadata', Actor::user($id, $name));
app(Switches::class)->all();   // every stored switch with who, when and why
```

Автоматическое выключение приходит как событие `ProcessSignal` с типом
`switch.changed` и `data.by.type = system`: оповещайте людей из слушателя.

## Нагрузка

На PostgreSQL сам движок обрабатывает около 10 000 элементов пакета в
секунду, ровно до миллиона. Чтобы измерить на своём железе:

```bash
PE_LOAD=1000000 PE_DB=pgsql PE_DB_USERNAME=… PE_DB_PASSWORD=… vendor/bin/pest tests/Feature/LoadTest.php
```
