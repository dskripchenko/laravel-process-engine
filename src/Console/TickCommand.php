<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Console\Command;

final class TickCommand extends Command
{
    protected $signature = 'process-engine:tick {--limit=500 : Work items per kind}';

    protected $description = 'Fire due timers, recover effects of dead workers, re-ring lost work';

    public function handle(Engine $engine): int
    {
        $report = $engine->tick((int) $this->option('limit'));
        if ($this->getOutput()->isVerbose()) {
            $this->line(json_encode($report, JSON_THROW_ON_ERROR));
        }

        return self::SUCCESS;
    }
}
