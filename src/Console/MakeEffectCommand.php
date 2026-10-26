<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Console;

use Illuminate\Console\GeneratorCommand;
use Symfony\Component\Console\Input\InputOption;

final class MakeEffectCommand extends GeneratorCommand
{
    protected $name = 'make:process-effect';

    protected $description = 'Create an effect handler with explicit retry semantics';

    protected $type = 'Effect';

    protected function getStub(): string
    {
        $name = $this->option('at-most-once') ? 'effect.at-most-once.stub' : 'effect.idempotent.stub';
        $custom = base_path('stubs/process-engine/'.$name);

        return file_exists($custom) ? $custom : __DIR__.'/../../stubs/'.$name;
    }

    protected function getDefaultNamespace($rootNamespace): string
    {
        return $rootNamespace.'\\Processes\\Effects';
    }

    /**
     * @return list<array<int, mixed>>
     */
    protected function getOptions(): array
    {
        return [
            ['at-most-once', null, InputOption::VALUE_NONE, 'The effect must never run twice (with reconciliation)'],
            ['force', 'f', InputOption::VALUE_NONE, 'Overwrite an existing class'],
        ];
    }
}
