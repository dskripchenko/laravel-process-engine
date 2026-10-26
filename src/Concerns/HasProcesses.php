<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Concerns;

use BackedEnum;
use Dskripchenko\LaravelProcessEngine\Models\ProcessInstance;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Dskripchenko\ProcessEngine\Store\InstanceQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Binds process instances to an Eloquent model:
 *
 *     $claim->startProcess(ClaimSettlement::class, new ClaimContext(...));
 *     $claim->process(ClaimSettlement::class)->expects();
 *     Claim::whereProcessIn('awaitingDocuments')->paginate();
 *
 * `Processes::start(..., subject: $claim)` works as well.
 */
trait HasProcesses
{
    public function processSubjectType(): string
    {
        return $this->getMorphClass();
    }

    public function processSubjectId(): string
    {
        return (string) $this->getKey();
    }

    /**
     * @param  class-string<Process>|string  $process
     * @param  object|array<string, mixed>  $context
     */
    public function startProcess(string $process, object|array $context = [], ?string $key = null, ?Actor $by = null): InstanceView
    {
        return app(Engine::class)->start($process, $context, $key, $by, subject: $this);
    }

    /**
     * The latest instance of a process bound to this model.
     *
     * @param  class-string<Process>|string  $process
     */
    public function process(string $process): ?InstanceView
    {
        $instances = app(Engine::class)->instances(new InstanceQuery(
            definitionName: self::processNameOf($process),
            subjectType: $this->processSubjectType(),
            subjectId: $this->processSubjectId(),
            limit: 1,
        ));

        return $instances[0] ?? null;
    }

    /**
     * Every instance bound to this model (any process), newest first.
     *
     * @return Builder<ProcessInstance>
     */
    public function processInstances(): Builder
    {
        return ProcessInstance::query()
            ->where('subject_type', $this->processSubjectType())
            ->where('subject_id', $this->processSubjectId())
            ->orderByDesc('id');
    }

    /**
     * Models with a running instance in the given state (indexed lookup).
     *
     * @param  Builder<static>  $query
     * @param  class-string<Process>|string|null  $process
     */
    public function scopeWhereProcessIn(Builder $query, string|BackedEnum $state, ?string $process = null): void
    {
        $id = $state instanceof BackedEnum ? (string) $state->value : $state;
        $prefix = (string) config('process-engine.table_prefix', 'pe_');
        $query->whereExists(function (QueryBuilder $sub) use ($id, $prefix, $process, $query) {
            $sub->from($prefix.'instances as pi')
                ->join($prefix.'active_states as ps', 'ps.instance_id', '=', 'pi.id')
                ->where('pi.subject_type', $this->getMorphClass())
                ->whereRaw('pi.subject_id = '.self::castToString($query, $this->getQualifiedKeyName()))
                ->where('ps.state', $id);
            if ($process !== null) {
                $sub->where('pi.definition_name', self::processNameOf($process));
            }
        });
    }

    /**
     * Models with an instance in the given status (running, completed, …).
     *
     * @param  Builder<static>  $query
     * @param  class-string<Process>|string|null  $process
     */
    public function scopeWhereProcessStatus(Builder $query, string $status, ?string $process = null): void
    {
        $prefix = (string) config('process-engine.table_prefix', 'pe_');
        $query->whereExists(function (QueryBuilder $sub) use ($status, $prefix, $process, $query) {
            $sub->from($prefix.'instances as pi')
                ->where('pi.subject_type', $this->getMorphClass())
                ->whereRaw('pi.subject_id = '.self::castToString($query, $this->getQualifiedKeyName()))
                ->where('pi.status', $status);
            if ($process !== null) {
                $sub->where('pi.definition_name', self::processNameOf($process));
            }
        });
    }

    private static function processNameOf(string $process): string
    {
        return is_subclass_of($process, Process::class) ? $process::processName() : $process;
    }

    /**
     * subject_id is a string column; model keys are often integers. Compare as text on every database.
     *
     * @param  Builder<static>  $query
     */
    private static function castToString(Builder $query, string $column): string
    {
        $wrapped = $query->getQuery()->getGrammar()->wrap($column);

        return match ($query->getConnection()->getDriverName()) {
            'mysql', 'mariadb' => "CAST({$wrapped} AS CHAR)",
            'pgsql' => "CAST({$wrapped} AS VARCHAR)",
            'sqlsrv' => "CAST({$wrapped} AS NVARCHAR(64))",
            default => "CAST({$wrapped} AS TEXT)",
        };
    }
}
