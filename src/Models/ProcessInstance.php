<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Read access to the instances table for host queries and admin screens.
 * Writes go through the engine only.
 *
 * @property string $id
 * @property string $definition_name
 * @property int $definition_version
 * @property string $status
 * @property array<string, mixed> $snapshot
 * @property string|null $business_key
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property bool $has_incidents
 */
final class ProcessInstance extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    /** @var array<string, string> */
    protected $casts = [
        'snapshot' => 'array',
        'parent_link' => 'array',
        'has_incidents' => 'boolean',
    ];

    public function getConnectionName(): ?string
    {
        return config('process-engine.connection') ?? parent::getConnectionName();
    }

    public function getTable(): string
    {
        return config('process-engine.table_prefix', 'pe_').'instances';
    }
}
