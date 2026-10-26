<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Actor;
use Dskripchenko\ProcessEngine\Engine;
use Illuminate\Console\Command;

final class MigrateCommand extends Command
{
    protected $signature = 'process-engine:migrate
        {migration : The registered migration name, e.g. claim-settlement@1->2}
        {--apply : Apply (otherwise a dry run)}
        {--reason= : Why (required with --apply)}
        {--limit=1000}';

    protected $description = 'Check or apply a migration of instances to another version';

    public function handle(Engine $engine): int
    {
        $apply = (bool) $this->option('apply');
        $reason = (string) ($this->option('reason') ?? '');
        if ($apply && trim($reason) === '') {
            $this->error('--reason is required with --apply');

            return self::FAILURE;
        }
        $report = $engine->migrate((string) $this->argument('migration'), Actor::operator(get_current_user(), 'console'), $reason !== '' ? $reason : 'dry run', ! $apply, (int) $this->option('limit'));
        $this->info(($apply ? 'Queued' : 'Eligible').': '.count($report->eligible));
        foreach ($report->skipped as $id => $why) {
            $this->line("  skipped {$id}: {$why}");
        }

        return self::SUCCESS;
    }
}
