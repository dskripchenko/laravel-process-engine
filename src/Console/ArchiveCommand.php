<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Console\Command;

final class ArchiveCommand extends Command
{
    protected $signature = 'process-engine:archive {--limit=500 : Instances per batch} {--batches=20}';

    protected $description = 'Move finished instances into the archive (one compressed row each)';

    public function handle(Engine $engine): int
    {
        $total = 0;
        for ($i = 0; $i < (int) $this->option('batches'); $i++) {
            $n = $engine->archive((int) $this->option('limit'));
            $total += $n;
            if ($n === 0) {
                break;
            }
        }
        $this->info("Archived {$total} instances.");

        return self::SUCCESS;
    }
}
