<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use DateTimeImmutable;
use DateTimeZone;
use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Interpreter\FlagProvider;
use Dskripchenko\ProcessEngine\Interpreter\SwitchProvider;
use Dskripchenko\ProcessEngine\Support\Json;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;

/**
 * Flags and switches of the engine. `flag('name')` guards read
 * `process-engine.flags.name`, as ConfigFlags does. Switches of switchable
 * states (ADR-015) live in the `switches` table: a switch nobody touched is
 * on, or as `process-engine.switches.name` says for a new one. Read from
 * the table every time — a switch is checked when a stage is entered, and a
 * long-running worker must see a change made elsewhere at once.
 */
final class Switches implements FlagProvider, SwitchProvider
{
    public function __construct(
        private readonly Repository $config,
        private readonly Connection $db,
        private readonly string $prefix = 'pe_',
    ) {}

    public function enabled(string $flag): bool
    {
        // Names may hold dots ("enrichment.dadata"): look them up in the map, not by a dotted config key.
        $flags = (array) $this->config->get('process-engine.flags', []);

        return (bool) ($flags[$flag] ?? $this->config->get('process-engine.flags.'.$flag, false));
    }

    public function isOn(string $switch): bool
    {
        return $this->state($switch)['on'];
    }

    public function set(string $switch, bool $on, Actor $by, ?string $reason = null): void
    {
        $at = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $this->db->table($this->prefix.'switches')->upsert([[
            'name' => $switch,
            'on' => $on,
            'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
            'changed_by' => Json::encode($by->toArray()),
            'changed_at' => $at->format('Y-m-d H:i:s.u'),
        ]], ['name'], ['on', 'reason', 'changed_by', 'changed_at']);
    }

    public function state(string $switch): array
    {
        $row = $this->db->table($this->prefix.'switches')->where('name', $switch)->first();
        if ($row === null) {
            $defaults = (array) $this->config->get('process-engine.switches', []);

            return ['on' => (bool) ($defaults[$switch] ?? true), 'reason' => null, 'by' => null, 'at' => null];
        }
        $by = is_string($row->changed_by) ? json_decode($row->changed_by, true) : null;

        return [
            'on' => (bool) $row->on,
            'reason' => $row->reason !== null ? (string) $row->reason : null,
            'by' => is_array($by) ? $by : null,
            'at' => $row->changed_at !== null ? new DateTimeImmutable((string) $row->changed_at, new DateTimeZone('UTC')) : null,
        ];
    }

    /**
     * Every switch stored in the table, by name.
     *
     * @return array<string, array{on: bool, reason: ?string, by: ?array<string, mixed>, at: ?DateTimeImmutable}>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->db->table($this->prefix.'switches')->orderBy('name')->pluck('name') as $name) {
            $out[(string) $name] = $this->state((string) $name);
        }

        return $out;
    }
}
