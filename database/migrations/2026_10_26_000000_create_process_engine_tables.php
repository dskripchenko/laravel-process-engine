<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

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

        $schema->create($p.'definitions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->string('name', 191);
            $t->unsignedInteger('version');
            $t->char('hash', 64);
            $t->json('schema');
            $t->dateTime('created_at', 6);
            $t->unique(['name', 'version']);
        });

        $schema->create($p.'instances', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('definition_id')->index();
            $t->string('definition_name', 191);
            $t->unsignedInteger('definition_version');
            $t->string('status', 16);
            $t->json('snapshot');
            $t->unsignedBigInteger('seq');
            $t->uuid('parent_id')->nullable()->index();
            $t->string('parent_key', 512)->nullable();
            $t->json('parent_link')->nullable();
            $t->string('business_key', 191)->nullable();
            $t->string('subject_type', 191)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->string('locked_by', 191)->nullable();
            $t->dateTime('lease_until', 6)->nullable()->index();
            $t->boolean('has_incidents')->default(false)->index();
            $t->dateTime('created_at', 6);
            $t->dateTime('updated_at', 6);
            $t->dateTime('completed_at', 6)->nullable();
            $t->index(['status']);
            $t->index(['definition_name', 'definition_version', 'status']);
            $t->unique(['definition_name', 'business_key']);
            $t->index(['subject_type', 'subject_id']);
            $t->index(['status', 'completed_at']);
        });

        $schema->create($p.'active_states', function (Blueprint $t) {
            $t->uuid('instance_id');
            $t->string('definition_name', 191);
            $t->string('state', 191);
            $t->primary(['instance_id', 'state']);
            $t->index(['definition_name', 'state']);
        });

        $schema->create($p.'inbox', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('instance_id');
            $t->string('name', 191);
            $t->json('payload');
            $t->string('key', 512);
            $t->char('key_hash', 64);
            $t->json('actor')->nullable();
            $t->string('status', 16);
            $t->string('reason', 1000)->nullable();
            $t->dateTime('received_at', 6);
            $t->dateTime('processed_at', 6)->nullable();
            $t->unique(['instance_id', 'key_hash']);
            $t->index(['instance_id', 'status', 'id']);
            $t->index(['status', 'received_at']);
        });

        $schema->create($p.'effects', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('instance_id')->index();
            $t->string('key', 512);
            $t->string('kind', 16);
            $t->string('class', 255);
            $t->string('semantics', 16);
            $t->json('retry');
            $t->string('status', 16);
            $t->json('input');
            $t->unsignedInteger('attempt')->default(0);
            $t->dateTime('next_attempt_at', 6)->nullable();
            $t->string('locked_by', 191)->nullable();
            $t->dateTime('lease_until', 6)->nullable();
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('finished_at', 6)->nullable();
            $t->json('result')->nullable();
            $t->json('error')->nullable();
            $t->boolean('compensable')->default(false);
            $t->uuid('compensation_of')->nullable();
            $t->unsignedInteger('reconcile_attempt')->default(0);
            $t->unsignedInteger('runs')->default(0);
            $t->dateTime('created_at', 6);
            $t->char('key_hash', 64)->unique();
            $t->index(['status', 'next_attempt_at']);
            $t->index(['status', 'lease_until']);
        });

        $schema->create($p.'timers', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('instance_id')->index();
            $t->string('key', 512);
            $t->char('key_hash', 64)->unique();
            $t->string('event', 191);
            $t->dateTime('fire_at', 6);
            $t->string('activation', 191);
            $t->string('status', 16);
            $t->index(['status', 'fire_at']);
        });

        $schema->create($p.'subscriptions', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('instance_id')->index();
            $t->string('key', 512);
            $t->string('event', 191);
            $t->string('correlation_key', 191);
            $t->string('activation', 191);
            $t->index(['event', 'correlation_key']);
        });

        $schema->create($p.'messages', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('event', 191);
            $t->string('correlation_key', 191);
            $t->json('payload');
            $t->string('key', 191);
            $t->json('actor')->nullable();
            $t->dateTime('expires_at', 6)->index();
            $t->dateTime('created_at', 6);
            $t->unique(['event', 'correlation_key', 'key']);
        });

        $schema->create($p.'incidents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('instance_id')->index();
            $t->string('type', 64);
            $t->string('message', 2000);
            $t->json('details');
            $t->uuid('effect_id')->nullable();
            $t->string('status', 16)->index();
            $t->string('resolution', 191)->nullable();
            $t->json('resolved_by')->nullable();
            $t->dateTime('created_at', 6);
            $t->dateTime('resolved_at', 6)->nullable();
        });

        $schema->create($p.'journal', function (Blueprint $t) {
            $t->bigIncrements('seq');
            $t->uuid('instance_id');
            $t->string('type', 64);
            $t->json('data');
            $t->json('actor')->nullable();
            $t->string('reason', 1000)->nullable();
            $t->dateTime('at', 6);
            $t->index(['instance_id', 'seq']);
        });

        $schema->create($p.'archive', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->unsignedBigInteger('definition_id');
            $t->string('definition_name', 191);
            $t->unsignedInteger('definition_version');
            $t->string('status', 16);
            $t->string('business_key', 191)->nullable();
            $t->string('subject_type', 191)->nullable();
            $t->string('subject_id', 64)->nullable();
            $t->uuid('parent_id')->nullable();
            $t->dateTime('started_at', 6)->nullable();
            $t->dateTime('completed_at', 6)->nullable()->index();
            $t->longText('document');
            $t->index(['definition_name', 'definition_version']);
            $t->index(['subject_type', 'subject_id']);
            $t->index(['business_key']);
        });
    }

    public function down(): void
    {
        $p = (string) config('process-engine.table_prefix', 'pe_');
        $schema = Schema::connection($this->getConnection());
        foreach (['archive', 'journal', 'incidents', 'messages', 'subscriptions', 'timers', 'effects', 'inbox', 'active_states', 'instances', 'definitions'] as $table) {
            $schema->dropIfExists($p.$table);
        }
    }
};
