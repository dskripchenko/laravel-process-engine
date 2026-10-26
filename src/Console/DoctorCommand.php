<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Console\Command;

final class DoctorCommand extends Command
{
    protected $signature = 'process-engine:doctor';

    protected $description = 'Find stored versions with live instances whose methods or effect classes are gone';

    public function handle(Engine $engine): int
    {
        $rows = [];
        foreach ($engine->definitions() as $definition) {
            $rows[] = [$definition->name, $definition->version, substr($definition->hash, 0, 12)];
        }
        $this->table(['process', 'version', 'hash'], $rows);
        $problems = $engine->doctor();
        foreach ($problems as $problem) {
            $this->error("{$problem['process']}@{$problem['version']} ({$problem['instances']} live): {$problem['problem']}");
        }
        if ($problems === []) {
            $this->info('No problems found.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
