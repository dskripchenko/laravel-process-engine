<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Engine;
use Dskripchenko\ProcessEngine\Export\FlowExporter;
use Dskripchenko\ProcessEngine\Export\MermaidExporter;
use Dskripchenko\ProcessEngine\Process;
use Illuminate\Console\Command;

final class GraphCommand extends Command
{
    protected $signature = 'process-engine:graph
        {process : A Process class or a stored process name}
        {--definition-version= : A stored version}
        {--format=mermaid : mermaid or flow}
        {--path=* : Drill-down path for the flow format}';

    protected $description = 'Print a process graph as Mermaid or as a @dskripchenko/flow model';

    public function handle(Engine $engine): int
    {
        $process = (string) $this->argument('process');
        $version = $this->option('definition-version') !== null ? (int) $this->option('definition-version') : null;
        if (is_subclass_of($process, Process::class) && $version === null) {
            $definition = Compiler::compile($process);
        } else {
            $records = array_values(array_filter($engine->definitions($process), fn ($d) => $version === null || $d->version === $version));
            if ($records === []) {
                $this->error("Process {$process} is not stored");

                return self::FAILURE;
            }
            $definition = Definition::fromArray($records[count($records) - 1]->schema);
        }

        if ($this->option('format') === 'flow') {
            $this->line(json_encode((new FlowExporter)->graph($definition, array_values((array) $this->option('path'))), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line((new MermaidExporter)->export($definition));
        }

        return self::SUCCESS;
    }
}
