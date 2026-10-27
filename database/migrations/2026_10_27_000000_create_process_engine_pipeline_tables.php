<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for data pipelines (ADR-014…016): exclusive starts.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        return config('process-engine.connection');
    }

    public function up(): void
    {
        $p = (string) config('process-engine.table_prefix', 'pe_');
        $schema = Schema::connection($this->getConnection());

        // One unfinished instance per lock (Processes::startExclusive).
        $schema->create($p.'exclusive', function (Blueprint $t) {
            $t->string('lock', 191)->primary();
            $t->uuid('instance_id');
            $t->dateTime('created_at', 6);
        });
    }

    public function down(): void
    {
        $p = (string) config('process-engine.table_prefix', 'pe_');
        Schema::connection($this->getConnection())->dropIfExists($p.'exclusive');
    }
};
