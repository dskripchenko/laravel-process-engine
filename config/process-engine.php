<?php

declare(strict_types=1);

return [

    // Database connection for the engine tables (null: the default connection).
    'connection' => env('PROCESS_ENGINE_DB_CONNECTION'),

    // Prefix of the engine tables.
    'table_prefix' => env('PROCESS_ENGINE_TABLE_PREFIX', 'pe_'),

    // Queue for the workers that step instances and run effects.
    'queue' => [
        'connection' => env('PROCESS_ENGINE_QUEUE_CONNECTION'),
        'name' => env('PROCESS_ENGINE_QUEUE', 'default'),
    ],

    // Queues of effects by handler class (overrides #[OnQueue] on the class):
    // EffectClass::class => 'queue' or ['queue' => ..., 'connection' => ...].
    // The job timeout follows the attempt timeout of the effect's retry policy;
    // keep the queue's retry_after above the longest one.
    'routes' => [],

    // Signs task tokens handed to external parties (defaults to the app key).
    'secret' => env('PROCESS_ENGINE_SECRET'),

    // The provider registers these in the scheduler (needs `schedule:run`).
    'schedule' => [
        'enabled' => true,
        'tick' => true,      // every minute: timers, recovery, re-ringing
        'archive' => true,   // daily: finished instances into the archive
    ],

    'options' => [
        'lease_ttl' => '2 minutes',
        'events_per_run' => 100,
        'max_context_bytes' => 262144,
        'message_ttl' => '7 days',
        'defer_ttl' => '30 days',
        'sweep_after' => '30 seconds',
        'archive_after' => '30 days',
        'overdue_after' => '5 minutes',
        'poll_backoff_max' => '1 hour',
    ],

    // Feature switches read by flag('name') guards: name => bool.
    'flags' => [],

    // Allow changing a stored version that no instance uses yet (local development).
    'allow_redefine_unused' => env('PROCESS_ENGINE_ALLOW_REDEFINE', false),

    // Process classes checked by process-engine:validate (optional).
    'processes' => [],

    // Classes returning Migration instances from __invoke(), registered on boot.
    'migrations' => [],

    // Forward laravel-delayed-process completions as published events.
    'delayed_process' => [
        'forward' => true,
    ],
];
