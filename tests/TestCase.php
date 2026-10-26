<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Tests;

use Dskripchenko\LaravelProcessEngine\Facades\Processes;
use Dskripchenko\LaravelProcessEngine\ProcessEngineServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Base;

/**
 * Database under test: PE_DB=sqlite (default, a temp file), mysql or pgsql
 * with PE_DB_HOST / PE_DB_PORT / PE_DB_DATABASE / PE_DB_USERNAME / PE_DB_PASSWORD.
 */
abstract class TestCase extends Base
{
    protected function getPackageProviders($app): array
    {
        return [ProcessEngineServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Processes' => Processes::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::connection());
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('process-engine.schedule.enabled', true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function connection(): array
    {
        $driver = getenv('PE_DB') ?: 'sqlite';
        if ($driver === 'sqlite') {
            // In memory for ordinary tests; the multi-process test uses its own file.
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false];
        }

        return [
            'driver' => $driver,
            'host' => getenv('PE_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('PE_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('PE_DB_DATABASE') ?: 'process_engine_test',
            'username' => getenv('PE_DB_USERNAME') ?: 'root',
            'password' => getenv('PE_DB_PASSWORD') ?: '',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_bin',
            'prefix' => '',
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->run();
        Schema::create('claims', function (Blueprint $t) {
            $t->id();
            $t->string('title')->default('claim');
            $t->string('status')->nullable();
        });
    }
}
