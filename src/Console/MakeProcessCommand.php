<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Illuminate\Console\GeneratorCommand;

final class MakeProcessCommand extends GeneratorCommand
{
    protected $name = 'make:process';

    protected $description = 'Create a process class';

    protected $type = 'Process';

    protected function getStub(): string
    {
        $custom = base_path('stubs/process-engine/process.stub');

        return file_exists($custom) ? $custom : __DIR__.'/../../stubs/process.stub';
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Processes';
    }
}
