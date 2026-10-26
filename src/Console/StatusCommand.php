<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Console\Command;

final class StatusCommand extends Command
{
    protected $signature = 'process-engine:status {--json : Machine-readable output} {--fail-on-problems : Exit 1 on incidents, overdue timers or stuck effects}';

    protected $description = 'Instances by status, open incidents, overdue timers, stuck effects';

    public function handle(Engine $engine): int
    {
        $health = $engine->health();
        if ($this->option('json')) {
            $this->line(json_encode($health, JSON_THROW_ON_ERROR));
        } else {
            $this->table(['metric', 'value'], array_map(fn ($k, $v) => [$k, $v], array_keys($health), $health));
            foreach ($engine->openIncidents(20) as $incident) {
                $this->warn("[{$incident->type}] {$incident->instanceId}: {$incident->message}");
            }
        }
        $problems = $health['open_incidents'] + $health['overdue_timers'] + $health['expired_effect_leases'];

        return $this->option('fail-on-problems') && $problems > 0 ? self::FAILURE : self::SUCCESS;
    }
}
