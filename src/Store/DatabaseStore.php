<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Store;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Dskripchenko\ProcessEngine\Store\DefinitionRecord;
use Dskripchenko\ProcessEngine\Store\EffectRecord;
use Dskripchenko\ProcessEngine\Store\InboxRecord;
use Dskripchenko\ProcessEngine\Store\IncidentRecord;
use Dskripchenko\ProcessEngine\Store\InstanceQuery;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Dskripchenko\ProcessEngine\Store\JournalRecord;
use Dskripchenko\ProcessEngine\Store\MessageRecord;
use Dskripchenko\ProcessEngine\Store\Store;
use Dskripchenko\ProcessEngine\Store\SubscriptionRecord;
use Dskripchenko\ProcessEngine\Store\TimerRecord;
use Dskripchenko\ProcessEngine\Support\Json;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;

/**
 * The engine store on a Laravel database connection (PostgreSQL, MySQL 8 /
 * MariaDB, SQLite). Compare-and-set operations are conditional UPDATEs,
 * idempotent inserts are insertOrIgnore on unique keys: no database-specific
 * locking is needed for correctness.
 */
final class DatabaseStore implements Store
{
    private const FORMAT = 'Y-m-d H:i:s.u';

    public function __construct(
        private readonly Connection $db,
        private readonly string $prefix = 'pe_',
    ) {}

    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction(function () use ($callback) {
            if ($this->db->getDriverName() === 'sqlite' && $this->db->transactionLevel() === 1) {
                // A deferred SQLite transaction that read first cannot upgrade
                // to a write after another connection committed: it fails with
                // "database is locked" whatever the busy timeout. Laravel
                // honours `transaction_mode` only on PHP 8.4+, so take the
                // write lock now, while the busy timeout still applies.
                $this->table('instances')->whereRaw('0 = 1')->update(['seq' => $this->db->raw('seq')]);
            }

            return $callback();
        });
    }

    private function table(string $name): Builder
    {
        return $this->db->table($this->prefix.$name);
    }

    // Definitions -------------------------------------------------------------

    public function findDefinition(string $name, int $version): ?DefinitionRecord
    {
        $row = $this->table('definitions')->where('name', $name)->where('version', $version)->first();

        return $row !== null ? $this->definition($row) : null;
    }

    public function findDefinitionById(string $id): ?DefinitionRecord
    {
        $row = $this->table('definitions')->where('id', (int) $id)->first();

        return $row !== null ? $this->definition($row) : null;
    }

    public function latestDefinition(string $name): ?DefinitionRecord
    {
        $row = $this->table('definitions')->where('name', $name)->orderByDesc('version')->first();

        return $row !== null ? $this->definition($row) : null;
    }

    public function insertDefinition(DefinitionRecord $record): DefinitionRecord
    {
        $id = $this->table('definitions')->insertGetId([
            'name' => $record->name,
            'version' => $record->version,
            'hash' => $record->hash,
            'schema' => Json::encode($record->schema),
            'created_at' => self::date($record->createdAt),
        ]);
        $record->id = (string) $id;

        return $record;
    }

    public function definitions(?string $name = null): array
    {
        $query = $this->table('definitions')->orderBy('name')->orderBy('version');
        if ($name !== null) {
            $query->where('name', $name);
        }

        return array_values($query->get()->map(fn ($row) => $this->definition($row))->all());
    }

    public function deleteDefinition(string $id): void
    {
        $this->table('definitions')->where('id', (int) $id)->delete();
    }

    // Instances ---------------------------------------------------------------

    public function insertInstance(InstanceRecord $record): bool
    {
        $inserted = $this->table('instances')->insertOrIgnore($this->instanceRow($record) + ['id' => $record->id]) > 0;
        if ($inserted) {
            $this->writeActiveStates($record);
        }

        return $inserted;
    }

    public function findInstance(string $id): ?InstanceRecord
    {
        $row = $this->table('instances')->where('id', $id)->first();

        return $row !== null ? $this->instance($row) : null;
    }

    public function findInstanceByKey(string $definitionName, string $businessKey): ?InstanceRecord
    {
        $row = $this->table('instances')->where('definition_name', $definitionName)->where('business_key', $businessKey)->first();

        return $row !== null ? $this->instance($row) : null;
    }

    public function findChild(string $parentId, string $parentKey): ?InstanceRecord
    {
        $row = $this->table('instances')->where('parent_id', $parentId)->where('parent_key', $parentKey)->first();

        return $row !== null ? $this->instance($row) : null;
    }

    public function children(string $parentId): array
    {
        return array_values($this->table('instances')->where('parent_id', $parentId)->orderBy('id')->get()->map(fn ($r) => $this->instance($r))->all());
    }

    public function updateInstance(InstanceRecord $record, int $expectedSeq): bool
    {
        $affected = $this->table('instances')
            ->where('id', $record->id)
            ->where('seq', $expectedSeq)
            ->update($this->instanceRow($record));
        if ($affected === 0) {
            return false;
        }
        $this->writeActiveStates($record);

        return true;
    }

    public function setHasIncidents(string $id, bool $value): void
    {
        $this->table('instances')->where('id', $id)->update(['has_incidents' => $value]);
    }

    public function acquireLease(string $id, string $worker, DateTimeImmutable $until, DateTimeImmutable $now): bool
    {
        return $this->table('instances')
            ->where('id', $id)
            ->where(fn (Builder $q) => $q->whereNull('lease_until')->orWhere('lease_until', '<', self::date($now))->orWhere('locked_by', $worker))
            ->update(['locked_by' => $worker, 'lease_until' => self::date($until)]) > 0;
    }

    public function releaseLease(string $id, string $worker): void
    {
        $this->table('instances')->where('id', $id)->where('locked_by', $worker)->update(['locked_by' => null, 'lease_until' => null]);
    }

    public function queryInstances(InstanceQuery $query): array
    {
        $rows = $this->filtered($query)->orderByDesc('id')->offset($query->offset)->limit($query->limit)->get();

        return array_values($rows->map(fn ($r) => $this->instance($r))->all());
    }

    public function countInstances(InstanceQuery $query): int
    {
        return $this->filtered($query)->count();
    }

    public function stateCounts(string $definitionId): array
    {
        $rows = $this->table('active_states as s')
            ->join($this->prefix.'instances as i', 'i.id', '=', 's.instance_id')
            ->where('i.definition_id', (int) $definitionId)
            ->whereIn('i.status', ['running', 'suspended'])
            ->groupBy('s.state')
            ->selectRaw('s.state as state, count(*) as total')
            ->get();
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->state] = (int) $row->total;
        }

        return $out;
    }

    private function filtered(InstanceQuery $q): Builder
    {
        $query = $this->table('instances');
        if ($q->statuses !== null) {
            $query->whereIn('status', $q->statuses);
        }
        foreach (['definition_name' => $q->definitionName, 'definition_version' => $q->definitionVersion, 'business_key' => $q->businessKey, 'subject_type' => $q->subjectType, 'subject_id' => $q->subjectId, 'parent_id' => $q->parentId] as $column => $value) {
            if ($value !== null) {
                $query->where($column, $value);
            }
        }
        if ($q->hasIncidents !== null) {
            $query->where('has_incidents', $q->hasIncidents);
        }
        if ($q->state !== null) {
            $query->whereExists(fn (Builder $sub) => $sub->from($this->prefix.'active_states')
                ->whereColumn('instance_id', $this->prefix.'instances.id')
                ->where('state', $q->state));
        }

        return $query;
    }

    private function writeActiveStates(InstanceRecord $record): void
    {
        $this->table('active_states')->where('instance_id', $record->id)->delete();
        if ($record->isFinished()) {
            return;
        }
        $rows = array_map(fn (string $state) => ['instance_id' => $record->id, 'definition_name' => $record->definitionName, 'state' => $state], array_values(array_unique($record->activeStates())));
        if ($rows !== []) {
            $this->table('active_states')->insert($rows);
        }
    }

    // Inbox -------------------------------------------------------------------

    public function insertInbox(InboxRecord $record): bool
    {
        $inserted = $this->table('inbox')->insertOrIgnore([
            'instance_id' => $record->instanceId,
            'name' => $record->name,
            'payload' => Json::encode($record->payload),
            'key' => $record->key,
            'key_hash' => hash('sha256', $record->key),
            'actor' => $record->actor !== null ? Json::encode($record->actor) : null,
            'status' => $record->status,
            'reason' => $record->reason,
            'received_at' => self::date($record->receivedAt),
        ]) > 0;
        if ($inserted) {
            $row = $this->table('inbox')->where('instance_id', $record->instanceId)->where('key_hash', hash('sha256', $record->key))->first(['id']);
            $record->id = (int) $row->id;
            $record->seq = (int) $row->id;
        }

        return $inserted;
    }

    public function findInboxByKey(string $instanceId, string $key): ?InboxRecord
    {
        $row = $this->table('inbox')->where('instance_id', $instanceId)->where('key_hash', hash('sha256', $key))->first();

        return $row !== null ? $this->inboxRecord($row) : null;
    }

    public function nextInbox(string $instanceId): ?InboxRecord
    {
        $row = $this->table('inbox')->where('instance_id', $instanceId)->where('status', InboxRecord::PENDING)->orderBy('id')->first();

        return $row !== null ? $this->inboxRecord($row) : null;
    }

    public function inbox(string $instanceId, ?string $status = null): array
    {
        $query = $this->table('inbox')->where('instance_id', $instanceId)->orderBy('id');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn ($r) => $this->inboxRecord($r))->all());
    }

    public function updateInbox(InboxRecord $record): void
    {
        $this->table('inbox')->where('id', $record->id)->update([
            'status' => $record->status,
            'reason' => $record->reason !== null ? mb_substr($record->reason, 0, 1000) : null,
            'processed_at' => self::date($record->processedAt),
        ]);
    }

    public function instancesWithPendingInbox(DateTimeImmutable $before, int $limit): array
    {
        return array_values(array_map('strval', $this->table('inbox')
            ->where('status', InboxRecord::PENDING)
            ->where('received_at', '<=', self::date($before))
            ->distinct()
            ->limit($limit)
            ->pluck('instance_id')
            ->all()));
    }

    // Effects -----------------------------------------------------------------

    public function insertEffect(EffectRecord $record): bool
    {
        return $this->table('effects')->insertOrIgnore($this->effectRow($record) + [
            'id' => $record->id,
            'instance_id' => $record->instanceId,
            'key' => $record->key,
            'key_hash' => hash('sha256', $record->key),
            'kind' => $record->kind,
            'class' => $record->class,
            'semantics' => $record->semantics,
            'retry' => Json::encode($record->retry),
            'compensable' => $record->compensable,
            'compensation_of' => $record->compensationOf,
            'created_at' => self::date($record->createdAt ?? new DateTimeImmutable),
        ]) > 0;
    }

    public function findEffect(string $id): ?EffectRecord
    {
        $row = $this->table('effects')->where('id', $id)->first();

        return $row !== null ? $this->effect($row) : null;
    }

    public function findEffectByKey(string $key): ?EffectRecord
    {
        $row = $this->table('effects')->where('key_hash', hash('sha256', $key))->first();

        return $row !== null ? $this->effect($row) : null;
    }

    public function claimEffect(string $id, string $from, string $to, string $worker, DateTimeImmutable $leaseUntil, DateTimeImmutable $now, bool $countAttempt): ?EffectRecord
    {
        $update = [
            'status' => $to,
            'locked_by' => $worker,
            'lease_until' => self::date($leaseUntil),
            'started_at' => self::date($now),
        ];
        if ($countAttempt) {
            $update['attempt'] = $this->db->raw('attempt + 1');
        }
        $affected = $this->table('effects')
            ->where('id', $id)
            ->where('status', $from)
            ->where(fn (Builder $q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', self::date($now)))
            ->update($update);

        return $affected > 0 ? $this->findEffect($id) : null;
    }

    public function updateEffect(EffectRecord $record): void
    {
        $this->table('effects')->where('id', $record->id)->update($this->effectRow($record));
    }

    public function dueEffects(array $statuses, DateTimeImmutable $now, int $limit): array
    {
        return array_values(array_map('strval', $this->table('effects')
            ->whereIn('status', $statuses)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', self::date($now))
            ->orderBy('next_attempt_at')
            ->limit($limit)
            ->pluck('id')
            ->all()));
    }

    public function expiredEffects(DateTimeImmutable $now, int $limit): array
    {
        return array_values($this->table('effects')
            ->whereIn('status', [EffectRecord::RUNNING, EffectRecord::RECONCILING])
            ->whereNotNull('lease_until')
            ->where('lease_until', '<', self::date($now))
            ->limit($limit)
            ->get()
            ->map(fn ($r) => $this->effect($r))
            ->all());
    }

    public function effects(string $instanceId, ?array $statuses = null): array
    {
        $query = $this->table('effects')->where('instance_id', $instanceId)->orderBy('id');
        if ($statuses !== null) {
            $query->whereIn('status', $statuses);
        }

        return array_values($query->get()->map(fn ($r) => $this->effect($r))->all());
    }

    // Timers ------------------------------------------------------------------

    public function insertTimer(TimerRecord $record): bool
    {
        return $this->table('timers')->insertOrIgnore([
            'id' => $record->id,
            'instance_id' => $record->instanceId,
            'key' => $record->key,
            'key_hash' => hash('sha256', $record->key),
            'event' => $record->event,
            'fire_at' => self::date($record->fireAt),
            'activation' => $record->activation,
            'status' => $record->status,
        ]) > 0;
    }

    public function cancelTimers(string $instanceId, string $prefix): int
    {
        return $this->table('timers')
            ->where('instance_id', $instanceId)
            ->where('status', TimerRecord::PENDING)
            ->whereRaw("{$this->db->getQueryGrammar()->wrap('key')} like ? escape '!'", [self::like($prefix).'%'])
            ->update(['status' => TimerRecord::CANCELLED]);
    }

    public function dueTimers(DateTimeImmutable $now, int $limit): array
    {
        return array_values($this->table('timers')
            ->where('status', TimerRecord::PENDING)
            ->where('fire_at', '<=', self::date($now))
            ->orderBy('fire_at')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => $this->timer($r))
            ->all());
    }

    public function fireTimer(string $id): bool
    {
        return $this->table('timers')->where('id', $id)->where('status', TimerRecord::PENDING)->update(['status' => TimerRecord::FIRED]) > 0;
    }

    public function timers(string $instanceId, ?string $status = null): array
    {
        $query = $this->table('timers')->where('instance_id', $instanceId)->orderBy('fire_at');
        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn ($r) => $this->timer($r))->all());
    }

    public function overdueTimers(DateTimeImmutable $before, int $limit): array
    {
        return $this->dueTimers($before, $limit);
    }

    // Subscriptions -----------------------------------------------------------

    public function insertSubscription(SubscriptionRecord $record): void
    {
        $this->table('subscriptions')->insert([
            'instance_id' => $record->instanceId,
            'key' => $record->key,
            'event' => $record->event,
            'correlation_key' => $record->correlationKey,
            'activation' => $record->activation,
        ]);
    }

    public function deleteSubscriptions(string $instanceId, string $prefix): void
    {
        $this->table('subscriptions')->where('instance_id', $instanceId)
            ->whereRaw("{$this->db->getQueryGrammar()->wrap('key')} like ? escape '!'", [self::like($prefix).'%'])
            ->delete();
    }

    public function subscribers(string $event, string $correlationKey): array
    {
        return array_values($this->table('subscriptions')->where('event', $event)->where('correlation_key', $correlationKey)->orderBy('id')->get()
            ->map(fn ($r) => new SubscriptionRecord((string) $r->instance_id, (string) $r->key, (string) $r->event, (string) $r->correlation_key, (string) $r->activation))
            ->all());
    }

    public function subscriptions(string $instanceId): array
    {
        return array_values($this->table('subscriptions')->where('instance_id', $instanceId)->orderBy('id')->get()
            ->map(fn ($r) => new SubscriptionRecord((string) $r->instance_id, (string) $r->key, (string) $r->event, (string) $r->correlation_key, (string) $r->activation))
            ->all());
    }

    public function insertMessage(MessageRecord $record): bool
    {
        return $this->table('messages')->insertOrIgnore([
            'id' => $record->id,
            'event' => $record->event,
            'correlation_key' => $record->correlationKey,
            'payload' => Json::encode($record->payload),
            'key' => mb_substr($record->key, 0, 191),
            'actor' => $record->actor !== null ? Json::encode($record->actor) : null,
            'expires_at' => self::date($record->expiresAt),
            'created_at' => self::date($record->createdAt),
        ]) > 0;
    }

    public function takeMessages(string $event, string $correlationKey, DateTimeImmutable $now): array
    {
        $rows = $this->table('messages')->where('event', $event)->where('correlation_key', $correlationKey)->where('expires_at', '>', self::date($now))->orderBy('created_at')->get();
        if ($rows->isEmpty()) {
            return [];
        }
        $this->table('messages')->whereIn('id', $rows->pluck('id')->all())->delete();

        return array_values($rows->map(fn ($r) => new MessageRecord(
            (string) $r->id,
            (string) $r->event,
            (string) $r->correlation_key,
            self::json($r->payload),
            (string) $r->key,
            $r->actor !== null ? self::json($r->actor) : null,
            self::read((string) $r->expires_at),
            self::read((string) $r->created_at),
        ))->all());
    }

    public function purgeMessages(DateTimeImmutable $now): int
    {
        return $this->table('messages')->where('expires_at', '<=', self::date($now))->delete();
    }

    // Incidents ---------------------------------------------------------------

    public function insertIncident(IncidentRecord $record): void
    {
        $this->table('incidents')->insert([
            'id' => $record->id,
            'instance_id' => $record->instanceId,
            'type' => $record->type,
            'message' => mb_substr($record->message, 0, 2000),
            'details' => Json::encode($record->details),
            'effect_id' => $record->effectId,
            'status' => $record->status,
            'created_at' => self::date($record->createdAt),
        ]);
    }

    public function updateIncident(IncidentRecord $record): void
    {
        $this->table('incidents')->where('id', $record->id)->update([
            'status' => $record->status,
            'resolution' => $record->resolution,
            'resolved_by' => $record->resolvedBy !== null ? Json::encode($record->resolvedBy) : null,
            'resolved_at' => self::date($record->resolvedAt),
        ]);
    }

    public function findIncident(string $id): ?IncidentRecord
    {
        $row = $this->table('incidents')->where('id', $id)->first();

        return $row !== null ? $this->incident($row) : null;
    }

    public function incidents(?string $instanceId = null, ?string $status = null, int $limit = 100): array
    {
        $query = $this->table('incidents')->orderBy('created_at')->limit($limit);
        if ($instanceId !== null) {
            $query->where('instance_id', $instanceId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        return array_values($query->get()->map(fn ($r) => $this->incident($r))->all());
    }

    // Journal -----------------------------------------------------------------

    public function appendJournal(JournalRecord $record): void
    {
        $record->seq = (int) $this->table('journal')->insertGetId([
            'instance_id' => $record->instanceId,
            'type' => $record->type,
            'data' => Json::encode($record->data),
            'actor' => $record->actor !== null ? Json::encode($record->actor) : null,
            'reason' => $record->reason !== null ? mb_substr($record->reason, 0, 1000) : null,
            'at' => self::date($record->at),
        ], 'seq');
    }

    public function journal(string $instanceId, int $afterSeq = 0, int $limit = 1000): array
    {
        return array_values($this->table('journal')
            ->where('instance_id', $instanceId)
            ->where('seq', '>', $afterSeq)
            ->orderBy('seq')
            ->limit(min($limit, 100000))
            ->get()
            ->map(fn ($r) => new JournalRecord(
                (string) $r->instance_id,
                (string) $r->type,
                self::json($r->data),
                self::read((string) $r->at),
                $r->actor !== null ? self::json($r->actor) : null,
                $r->reason !== null ? (string) $r->reason : null,
                (int) $r->seq,
            ))
            ->all());
    }

    // Archive -----------------------------------------------------------------

    public function archivable(DateTimeImmutable $completedBefore, int $limit): array
    {
        $p = $this->prefix;

        return array_values(array_map('strval', $this->table('instances as i')
            ->whereIn('i.status', ['completed', 'failed', 'cancelled'])
            ->whereNotNull('i.completed_at')
            ->where('i.completed_at', '<=', self::date($completedBefore))
            ->whereNotExists(fn (Builder $q) => $q->from($p.'incidents')->whereColumn('instance_id', 'i.id')->where('status', IncidentRecord::OPEN))
            ->whereNotExists(fn (Builder $q) => $q->from($p.'instances as c')->whereColumn('c.parent_id', 'i.id')->whereNotIn('c.status', ['completed', 'failed', 'cancelled']))
            ->orderBy('i.completed_at')
            ->limit($limit)
            ->pluck('i.id')
            ->all()));
    }

    public function archive(InstanceRecord $instance, array $document): void
    {
        $id = $instance->id;
        $this->table('archive')->insertOrIgnore([
            'id' => $id,
            'definition_id' => (int) $instance->definitionId,
            'definition_name' => $instance->definitionName,
            'definition_version' => $instance->definitionVersion,
            'status' => $instance->status,
            'business_key' => $instance->businessKey,
            'subject_type' => $instance->subjectType,
            'subject_id' => $instance->subjectId,
            'parent_id' => $instance->parentId,
            'started_at' => self::date($instance->createdAt),
            'completed_at' => self::date($instance->completedAt),
            'document' => base64_encode((string) gzencode(Json::encode($document), 6)),
        ]);
        foreach (['inbox', 'effects', 'timers', 'subscriptions', 'incidents', 'journal', 'active_states'] as $table) {
            $this->table($table)->where('instance_id', $id)->delete();
        }
        $this->table('instances')->where('id', $id)->delete();
    }

    public function findArchived(string $id): ?array
    {
        $row = $this->table('archive')->where('id', $id)->first(['document']);
        if ($row === null) {
            return null;
        }
        $json = gzdecode((string) base64_decode((string) $row->document));

        return is_string($json) ? Json::decodeArray($json) : null;
    }

    // Mapping -----------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function instanceRow(InstanceRecord $r): array
    {
        return [
            'definition_id' => (int) $r->definitionId,
            'definition_name' => $r->definitionName,
            'definition_version' => $r->definitionVersion,
            'status' => $r->status,
            'snapshot' => Json::encode($r->snapshot),
            'seq' => $r->seq,
            'parent_id' => $r->parentId,
            'parent_key' => $r->parentKey,
            'parent_link' => $r->parentLink !== [] ? Json::encode($r->parentLink) : null,
            'business_key' => $r->businessKey,
            'subject_type' => $r->subjectType,
            'subject_id' => $r->subjectId,
            'has_incidents' => $r->hasIncidents,
            'created_at' => self::date($r->createdAt ?? new DateTimeImmutable),
            'updated_at' => self::date($r->updatedAt ?? new DateTimeImmutable),
            'completed_at' => self::date($r->completedAt),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function effectRow(EffectRecord $r): array
    {
        return [
            'status' => $r->status,
            'input' => Json::encode($r->input),
            'attempt' => $r->attempt,
            'next_attempt_at' => self::date($r->nextAttemptAt),
            'locked_by' => $r->lockedBy,
            'lease_until' => self::date($r->leaseUntil),
            'started_at' => self::date($r->startedAt),
            'finished_at' => self::date($r->finishedAt),
            'result' => Json::encode($r->result),
            'error' => $r->error !== null ? Json::encode($r->error) : null,
            'reconcile_attempt' => $r->reconcileAttempt,
            'runs' => $r->runs,
        ];
    }

    private function definition(object $row): DefinitionRecord
    {
        return new DefinitionRecord((string) $row->id, (string) $row->name, (int) $row->version, (string) $row->hash, self::json($row->schema), self::read((string) $row->created_at));
    }

    private function instance(object $r): InstanceRecord
    {
        return new InstanceRecord(
            id: (string) $r->id,
            definitionId: (string) $r->definition_id,
            definitionName: (string) $r->definition_name,
            definitionVersion: (int) $r->definition_version,
            status: (string) $r->status,
            snapshot: self::json($r->snapshot),
            seq: (int) $r->seq,
            parentId: $r->parent_id !== null ? (string) $r->parent_id : null,
            parentKey: $r->parent_key !== null ? (string) $r->parent_key : null,
            parentLink: $r->parent_link !== null ? self::json($r->parent_link) : [],
            businessKey: $r->business_key !== null ? (string) $r->business_key : null,
            subjectType: $r->subject_type !== null ? (string) $r->subject_type : null,
            subjectId: $r->subject_id !== null ? (string) $r->subject_id : null,
            lockedBy: $r->locked_by !== null ? (string) $r->locked_by : null,
            leaseUntil: self::readNullable($r->lease_until),
            hasIncidents: (bool) $r->has_incidents,
            createdAt: self::readNullable($r->created_at),
            updatedAt: self::readNullable($r->updated_at),
            completedAt: self::readNullable($r->completed_at),
        );
    }

    private function inboxRecord(object $r): InboxRecord
    {
        return new InboxRecord(
            id: (int) $r->id,
            instanceId: (string) $r->instance_id,
            name: (string) $r->name,
            payload: self::json($r->payload),
            key: (string) $r->key,
            actor: $r->actor !== null ? self::json($r->actor) : null,
            status: (string) $r->status,
            receivedAt: self::read((string) $r->received_at),
            processedAt: self::readNullable($r->processed_at),
            reason: $r->reason !== null ? (string) $r->reason : null,
            seq: (int) $r->id,
        );
    }

    private function effect(object $r): EffectRecord
    {
        $result = $r->result !== null ? Json::decode((string) $r->result) : null;

        return new EffectRecord(
            id: (string) $r->id,
            instanceId: (string) $r->instance_id,
            key: (string) $r->key,
            kind: (string) $r->kind,
            class: (string) $r->class,
            semantics: (string) $r->semantics,
            retry: self::json($r->retry),
            status: (string) $r->status,
            input: self::json($r->input),
            attempt: (int) $r->attempt,
            nextAttemptAt: self::readNullable($r->next_attempt_at),
            lockedBy: $r->locked_by !== null ? (string) $r->locked_by : null,
            leaseUntil: self::readNullable($r->lease_until),
            startedAt: self::readNullable($r->started_at),
            finishedAt: self::readNullable($r->finished_at),
            result: $result,
            error: $r->error !== null ? self::json($r->error) : null,
            compensable: (bool) $r->compensable,
            compensationOf: $r->compensation_of !== null ? (string) $r->compensation_of : null,
            reconcileAttempt: (int) $r->reconcile_attempt,
            runs: (int) $r->runs,
            createdAt: self::readNullable($r->created_at),
        );
    }

    private function timer(object $r): TimerRecord
    {
        return new TimerRecord((string) $r->id, (string) $r->instance_id, (string) $r->key, (string) $r->event, self::read((string) $r->fire_at), (string) $r->activation, (string) $r->status);
    }

    private function incident(object $r): IncidentRecord
    {
        return new IncidentRecord(
            id: (string) $r->id,
            instanceId: (string) $r->instance_id,
            type: (string) $r->type,
            message: (string) $r->message,
            details: self::json($r->details),
            effectId: $r->effect_id !== null ? (string) $r->effect_id : null,
            status: (string) $r->status,
            createdAt: self::read((string) $r->created_at),
            resolution: $r->resolution !== null ? (string) $r->resolution : null,
            resolvedBy: $r->resolved_by !== null ? self::json($r->resolved_by) : null,
            resolvedAt: self::readNullable($r->resolved_at),
        );
    }

    private static function date(?DateTimeInterface $date): ?string
    {
        return $date !== null
            ? DateTimeImmutable::createFromInterface($date)->setTimezone(new DateTimeZone('UTC'))->format(self::FORMAT)
            : null;
    }

    private static function read(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }

    private static function readNullable(mixed $value): ?DateTimeImmutable
    {
        return $value !== null && $value !== '' ? self::read((string) $value) : null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if ($value === null || $value === '') {
            return [];
        }

        return Json::decodeArray((string) $value);
    }

    /**
     * A LIKE prefix with `!` as the escape character (the same on every database).
     */
    private static function like(string $prefix): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix);
    }
}
