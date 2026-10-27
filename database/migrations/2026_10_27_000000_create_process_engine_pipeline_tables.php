<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tables for data pipelines (ADR-014…016): batches and their elements,
 * switches of switchable states, exclusive starts.
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

        $schema->create($p.'batches', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('instance_id')->index();
            $t->string('key', 512);
            $t->string('invoke', 191);
            $t->string('state', 191);
            $t->string('activation', 64);
            $t->string('handler', 255);
            $t->string('source', 255);
            $t->unsignedInteger('chunk');
            $t->unsignedInteger('concurrency');
            $t->unsignedInteger('item_attempts');
            $t->json('retry');
            $t->json('context');
            $t->string('context_class', 255)->nullable();
            $t->string('status', 16);
            $t->string('cursor', 191)->nullable();
            $t->boolean('exhausted')->default(false);
            $t->unsignedInteger('running')->default(0);
            $t->unsignedInteger('chunks')->default(0);
            $t->unsignedBigInteger('seen')->default(0);
            $t->unsignedBigInteger('total')->nullable();
            $t->json('counters');
            $t->json('error')->nullable();
            $t->unsignedBigInteger('seq')->default(0);
            $t->dateTime('created_at', 6);
            $t->dateTime('finished_at', 6)->nullable();
        });

        $schema->create($p.'batch_items', function (Blueprint $t) {
            $t->uuid('batch_id');
            $t->string('key', 191);
            $t->unsignedBigInteger('position');
            $t->string('status', 16);
            $t->unsignedInteger('attempts')->default(0);
            $t->string('reason', 1000)->nullable();
            $t->uuid('effect_id')->nullable();
            $t->dateTime('updated_at', 6);
            $t->primary(['batch_id', 'key']);
            $t->index(['batch_id', 'status', 'position']);
        });

        $schema->create($p.'switches', function (Blueprint $t) {
            $t->string('name', 191)->primary();
            $t->boolean('on');
            $t->string('reason', 1000)->nullable();
            $t->json('changed_by')->nullable();
            $t->dateTime('changed_at', 6)->nullable();
        });

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
        $schema = Schema::connection($this->getConnection());
        foreach (['exclusive', 'switches', 'batch_items', 'batches'] as $table) {
            $schema->dropIfExists($p.$table);
        }
    }
};
