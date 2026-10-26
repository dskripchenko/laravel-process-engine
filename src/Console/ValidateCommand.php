<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Dskripchenko\ProcessEngine\Builder\Compiler;
use Dskripchenko\ProcessEngine\Definition\DefinitionError;
use Dskripchenko\ProcessEngine\Definition\Validator;
use Dskripchenko\ProcessEngine\Process;
use Illuminate\Console\Command;
use Symfony\Component\Finder\Finder;

final class ValidateCommand extends Command
{
    protected $signature = 'process-engine:validate {classes?* : Process classes} {--path=* : Directories to scan for Process classes}';

    protected $description = 'Build and check process definitions (for CI)';

    public function handle(): int
    {
        $classes = array_values(array_unique([...(array) $this->argument('classes'), ...(array) config('process-engine.processes', []), ...$this->scan((array) $this->option('path'))]));
        if ($classes === []) {
            $this->warn('No process classes given: pass classes, --path or set process-engine.processes');

            return self::SUCCESS;
        }
        $failed = 0;
        foreach ($classes as $class) {
            if (! is_subclass_of($class, Process::class)) {
                $this->error("{$class} is not a Process");
                $failed++;

                continue;
            }
            try {
                $definition = Compiler::compile($class);
                $warnings = array_filter(Validator::validate($definition->toArray()), fn ($i) => ! $i->isError());
                $this->info("✓ {$definition->name()}@{$definition->version()} ({$class})");
                foreach ($warnings as $warning) {
                    $this->line("    warning {$warning}");
                }
            } catch (DefinitionError $e) {
                $failed++;
                $this->error("✗ {$class}\n".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private function scan(array $paths): array
    {
        $classes = [];
        foreach ($paths as $path) {
            $dir = str_starts_with($path, '/') ? $path : base_path($path);
            if (! is_dir($dir)) {
                continue;
            }
            foreach (Finder::create()->files()->name('*.php')->in($dir) as $file) {
                $code = (string) file_get_contents($file->getRealPath());
                if (preg_match('/namespace\s+([^;]+);/', $code, $ns) === 1 && preg_match('/class\s+(\w+)\s+extends\s+\\\\?[\w\\\\]*Process\b/', $code, $cls) === 1) {
                    $classes[] = trim($ns[1]).'\\'.$cls[1];
                }
            }
        }

        return $classes;
    }
}
