<?php

declare(strict_types=1);

namespace Dskripchenko\LaravelProcessEngine\Support;

use Dskripchenko\ProcessEngine\Definition\Definition;
use Dskripchenko\ProcessEngine\Runtime\Projector;
use Dskripchenko\ProcessEngine\Store\InstanceRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Writes the current state into a column of the model the instance is bound
 * to, inside the step transaction, as declared by `project()` in the process.
 * The deepest active state with a mapping wins; a finished instance maps its
 * final state.
 */
final class EloquentProjector implements Projector
{
    public function project(InstanceRecord $instance, Definition $definition): void
    {
        if ($instance->subjectType === null || $instance->subjectId === null) {
            return;
        }
        $subjectClass = Relation::getMorphedModel($instance->subjectType) ?? $instance->subjectType;
        foreach ($definition->projections() as $projection) {
            $model = (string) ($projection['model'] ?? '');
            if (! is_a($subjectClass, $model, true) || ! is_subclass_of($model, Model::class)) {
                continue;
            }
            $value = $this->value($instance, (array) ($projection['map'] ?? []));
            if ($value === null) {
                continue;
            }
            /** @var Model $prototype */
            $prototype = new $model;
            $prototype->newQuery()->whereKey($instance->subjectId)->toBase()->update([(string) $projection['column'] => $value]);
        }
    }

    /**
     * @param  array<string, mixed>  $map
     */
    private function value(InstanceRecord $instance, array $map): mixed
    {
        $final = $instance->snapshot['final'] ?? null;
        if ($instance->isFinished() && is_string($final) && array_key_exists($final, $map)) {
            return $map[$final];
        }
        foreach (array_reverse($instance->activeStates()) as $state) {
            if (array_key_exists($state, $map)) {
                return $map[$state];
            }
        }

        return null;
    }
}
