<?php

namespace App\Domain\Infrastructure\Traits;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Models\StateTransition;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasStateMachine
{
    public function stateTransitions(): MorphMany
    {
        return $this->morphMany(StateTransition::class, 'model')->orderByDesc('created_at');
    }

    /**
     * Returns the map of allowed transitions.
     * Format: ['from_status_value' => ['to_status_value_1', 'to_status_value_2']]
     * Each model using this trait MUST implement this method.
     */
    abstract public static function allowedTransitions(): array;

    /**
     * Returns the database column name that holds the status.
     * Override in the model if the column is not 'status'.
     */
    public function getStatusColumn(): string
    {
        return 'status';
    }

    /**
     * Returns the current status as a raw string value.
     */
    public function getCurrentStatus(): string
    {
        $column = $this->getStatusColumn();
        $raw = $this->getRawOriginal($column) ?? $this->getAttributes()[$column] ?? null;

        if ($raw instanceof \BackedEnum) {
            return $raw->value;
        }

        return (string) $raw;
    }

    public function canTransitionTo(string $toStatus): bool
    {
        $currentStatus = $this->getCurrentStatus();
        $allowed = static::allowedTransitions();

        return isset($allowed[$currentStatus]) && in_array($toStatus, $allowed[$currentStatus], true);
    }

    public function getAllowedNextStatuses(): array
    {
        $currentStatus = $this->getCurrentStatus();
        $allowed = static::allowedTransitions();

        return $allowed[$currentStatus] ?? [];
    }

    /**
     * Convenience method to transition the model's status.
     * Delegates to TransitionStatusAction for validation, persistence, and audit logging.
     */
    public function transitionTo(string|\BackedEnum $toStatus, ?string $notes = null, array $metadata = []): static
    {
        app(TransitionStatusAction::class)->execute($this, $toStatus, $notes, $metadata);

        return $this;
    }
}
