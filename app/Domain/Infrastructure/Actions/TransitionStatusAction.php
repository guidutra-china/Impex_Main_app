<?php

namespace App\Domain\Infrastructure\Actions;

use App\Domain\Infrastructure\Exceptions\TransitionBlockedException;
use App\Domain\Infrastructure\Models\StateTransition;
use App\Domain\Infrastructure\Traits\HasStateMachine;
use App\Domain\Operations\OperationsPipeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class TransitionStatusAction
{
    /**
     * Transition a model's status within a DB transaction.
     * Validates the transition, updates the status, logs the change, and executes side-effects.
     *
     * @param  Model&HasStateMachine  $model
     * @param  callable|null  $sideEffects  Closure executed inside the transaction after status change
     *
     * @throws \InvalidArgumentException if the transition is not allowed
     * @throws \App\Domain\Infrastructure\Exceptions\TransitionBlockedException if a business-rule blocker is present
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
                .'Allowed: ['.implode(', ', $model->getAllowedNextStatuses()).']'
            );
        }

        $blockers = $model->getTransitionBlockers($toStatusValue);
        if (! empty($blockers)) {
            throw new TransitionBlockedException($blockers);
        }

        $result = DB::transaction(function () use ($model, $toStatus, $toStatusValue, $fromStatusValue, $notes, $metadata, $sideEffects) {
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

        $this->runAutoAdvances($model, $toStatusValue);

        return $result;
    }

    /**
     * Apply declarative pipeline auto-advances after a successful transition.
     * Best-effort: each is guarded by canTransitionTo and wrapped in try/catch
     * so a downstream failure can never break the originating transition.
     */
    private function runAutoAdvances(Model $model, string $toStatusValue): void
    {
        foreach (OperationsPipeline::autoAdvancesFor($model, $toStatusValue) as $advance) {
            $target = null;

            try {
                $target = ($advance->resolveTarget)($model);

                if ($target !== null && $target->canTransitionTo($advance->targetStatus)) {
                    $this->execute(
                        $target,
                        $advance->targetStatus,
                        notes: 'Auto-advance triggered by '.class_basename($model).' #'.$model->getKey().' reaching '.$toStatusValue,
                    );
                }
            } catch (\Throwable $e) {
                // Best-effort: never break the originating transition, but keep enough
                // context to diagnose a missed auto-advance from the logs.
                report(new \RuntimeException(
                    'Auto-advance failed: '.class_basename($model).' #'.$model->getKey()
                    .' → '.($target ? class_basename($target).' #'.$target->getKey() : 'null target')
                    .' status='.$advance->targetStatus,
                    previous: $e,
                ));
            }
        }
    }
}
