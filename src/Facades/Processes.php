<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Facades;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Dskripchenko\ProcessEngine\Query\InstanceView start(string $process, object|array<string, mixed> $context = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null, ?string $subjectType = null, string|int|null $subjectId = null, ?int $version = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt send(string $instanceId, object|string $event, array<string, mixed> $payload = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt sendStrict(string $instanceId, object|string $event, array<string, mixed> $payload = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt publish(object|string $event, string $correlate, array<string, mixed> $payload = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt complete(string $token, object|string $event, array<string, mixed> $payload = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null)
 * @method static string taskToken(string $instanceId, string $state)
 * @method static \Dskripchenko\ProcessEngine\Query\InstanceView adopt(string $process, list<string|\BackedEnum> $in, object|array<string, mixed> $context = [], ?string $key = null, ?\Dskripchenko\ProcessEngine\Actor $by = null, ?string $reason = null, ?string $subjectType = null, string|int|null $subjectId = null, ?int $version = null)
 * @method static \Dskripchenko\ProcessEngine\Query\InstanceView|null find(string $instanceId)
 * @method static \Dskripchenko\ProcessEngine\Query\InstanceView get(string $instanceId)
 * @method static list<\Dskripchenko\ProcessEngine\Query\InstanceView> instances(?\Dskripchenko\ProcessEngine\Store\InstanceQuery $query = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt transitionTo(string $instanceId, list<string|\BackedEnum> $states, \Dskripchenko\ProcessEngine\Actor $by, string $reason, bool $runExit = true, bool $runEntry = true)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt patchContext(string $instanceId, list<array<string, mixed>> $patch, \Dskripchenko\ProcessEngine\Actor $by, string $reason)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt suspend(string $instanceId, \Dskripchenko\ProcessEngine\Actor $by, string $reason)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt resume(string $instanceId, \Dskripchenko\ProcessEngine\Actor $by, string $reason)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt cancel(string $instanceId, \Dskripchenko\ProcessEngine\Actor $by, string $reason, bool $compensate = false)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt retryEffect(string $effectId, \Dskripchenko\ProcessEngine\Actor $by, string $reason, bool $confirmUnsafe = false)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt resolveEffect(string $effectId, \Dskripchenko\ProcessEngine\Actor $by, string $reason, bool $succeeded = true, mixed $result = null, ?string $code = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt skipEffect(string $effectId, \Dskripchenko\ProcessEngine\Actor $by, string $reason, mixed $result = null)
 * @method static \Dskripchenko\ProcessEngine\Runtime\Receipt resolveIncident(string $incidentId, \Dskripchenko\ProcessEngine\Actor $by, string $reason, string $resolution = 'resolved')
 * @method static \Dskripchenko\ProcessEngine\Versioning\MigrationReport migrate(string|\Dskripchenko\ProcessEngine\Versioning\Migration $migration, \Dskripchenko\ProcessEngine\Actor $by, string $reason, bool $dryRun = true, int $limit = 1000)
 * @method static array<string, int> health()
 * @method static array<string, int> stateCounts(string $process, ?int $version = null)
 *
 * @see Engine
 */
final class Processes extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return Engine::class;
    }
}
