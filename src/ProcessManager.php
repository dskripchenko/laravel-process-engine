<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Process;
use Dskripchenko\ProcessEngine\Query\InstanceView;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The root of the Processes facade: the engine, plus what only the Laravel
 * side can do. Every other call goes to the engine.
 *
 * @mixin Engine
 */
final class ProcessManager
{
    public function __construct(
        private readonly Engine $engine,
        private readonly ConnectionInterface $db,
        private readonly string $prefix = 'pe_',
    ) {}

    public function engine(): Engine
    {
        return $this->engine;
    }

    /**
     * Start unless an unfinished instance holds the lock — then return that
     * one. For schedules that must not overlap: one monitoring run at a time,
     * one recomputation per profile. The lock frees itself when its instance
     * finishes.
     *
     * @param  class-string<Process>|string  $process
     * @param  object|array<string, mixed>  $context
     */
    public function startExclusive(string $process, string $lock, object|array $context = [], ?Actor $by = null, ?object $subject = null): InstanceView
    {
        $table = $this->prefix.'exclusive';

        try {
            return $this->db->transaction(function () use ($table, $process, $lock, $context, $by, $subject): InstanceView {
                $held = $this->db->table($table)->where('lock', $lock)->lockForUpdate()->value('instance_id');
                $holder = $held !== null ? $this->engine->find((string) $held) : null;
                if ($holder !== null && ! $holder->isFinished()) {
                    return $holder;
                }
                $view = $this->engine->start($process, $context, by: $by, subject: $subject);
                $row = ['instance_id' => $view->id(), 'created_at' => now()];
                $held !== null
                    ? $this->db->table($table)->where('lock', $lock)->update($row)
                    : $this->db->table($table)->insert(['lock' => $lock] + $row);

                return $view;
            });
        } catch (UniqueConstraintViolationException) {
            // Another start took the lock at the same moment: that one runs.
            $held = $this->db->table($table)->where('lock', $lock)->value('instance_id');

            return $this->engine->get((string) $held);
        }
    }

    /**
     * @param  array<int, mixed>  $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        return $this->engine->{$method}(...$arguments);
    }
}
