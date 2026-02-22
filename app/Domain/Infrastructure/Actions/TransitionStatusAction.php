<?php

namespace App\Domain\Infrastructure\Actions;

use App\Domain\Infrastructure\Models\StateTransition;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransitionStatusAction
{
    /**
     * Transition a model's status within a DB transaction.
     * Validates the transition, updates the status, logs the change, and executes side-effects.
     *
     * @param  Model&HasStateMachine  $model
     * @param  string|\BackedEnum  $toStatus
     * @param  string|null  $notes
     * @param  array  $metadata
     * @param  callable|null  $sideEffects  Closure executed inside the transaction after status change
     * @return Model
     *
     * @throws \InvalidArgumentException if the transition is not allowed
     */
    public function execute(
        Model $model,
        string|\BackedEnum $toStatus,
        ?string $notes = null,
        array $metadata = [],
        ?callable $sideEffects = null,
    ): Model {
        $toStatusValue = $toStatus instanceof \BackedEnum ? $toStatus->value : $toStatus;
        $fromStatusValue = $model->getCurrentStatus();

        if ($fromStatusValue === $toStatusValue) {
            return $model;
        }

        if (! $model->canTransitionTo($toStatusValue)) {
            $modelClass = class_basename($model);
            throw new \InvalidArgumentException(
                "Invalid status transition for {$modelClass}: [{$fromStatusValue}] → [{$toStatusValue}]. "
                . 'Allowed: [' . implode(', ', $model->getAllowedNextStatuses()) . ']'
            );
        }

        return DB::transaction(function () use ($model, $toStatus, $toStatusValue, $fromStatusValue, $notes, $metadata, $sideEffects) {
            $column = $model->getStatusColumn();
            $model->{$column} = $toStatus;
            $model->save();

            StateTransition::create([
                'model_type' => $model->getMorphClass(),
                'model_id' => $model->getKey(),
                'from_status' => $fromStatusValue,
                'to_status' => $toStatusValue,
                'notes' => $notes,
                'metadata' => ! empty($metadata) ? $metadata : null,
                'user_id' => auth()->id(),
                'created_at' => now(),
            ]);

            if ($sideEffects) {
                $sideEffects($model);
            }

            return $model;
        });
    }
}
