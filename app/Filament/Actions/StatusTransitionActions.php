<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

class StatusTransitionActions
{
    /**
     * Build an ActionGroup containing one action per allowed transition for the given enum class.
     * Designed for use in table ->recordActions([...]).
     *
     * @param  class-string<\BackedEnum>  $enumClass  The status enum (e.g., InquiryStatus::class)
     * @param  array<string, array{icon?: string, color?: string, requiresConfirmation?: bool, requiresNotes?: bool}>  $overrides  Per-status visual/behavior overrides keyed by enum value
     */
    public static function make(string $enumClass, array $overrides = []): ActionGroup
    {
        $allTransitions = self::collectAllTargetStatuses($enumClass);

        $actions = [];
        foreach ($allTransitions as $statusValue) {
            $enum = $enumClass::from($statusValue);
            $override = $overrides[$statusValue] ?? [];

            $label = $enum->getLabel();
            $icon = $override['icon'] ?? (method_exists($enum, 'getIcon') ? $enum->getIcon() : 'heroicon-o-arrow-path');
            $color = $override['color'] ?? (method_exists($enum, 'getColor') ? $enum->getColor() : 'gray');
            $requiresConfirmation = $override['requiresConfirmation'] ?? self::isDestructiveTransition($statusValue);
            $requiresNotes = $override['requiresNotes'] ?? self::isDestructiveTransition($statusValue);

            $action = Action::make("transition_to_{$statusValue}")
                ->label($label)
                ->icon($icon)
                ->color($color)
                ->size('sm')
                ->visible(fn ($record) => $record->canTransitionTo($statusValue))
                ->action(function ($record, ?array $data = null) use ($enumClass, $statusValue) {
                    try {
                        app(TransitionStatusAction::class)->execute(
                            $record,
                            $enumClass::from($statusValue),
                            $data['notes'] ?? null,
                        );

                        $newLabel = $enumClass::from($statusValue)->getLabel();

                        Notification::make()
                            ->title(__('messages.status_changed_to') . ' ' . $newLabel)
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title(__('messages.status_transition_failed'))
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                });

            if ($requiresConfirmation) {
                $action->requiresConfirmation()
                    ->modalHeading(__('forms.labels.change_status'))
                    ->modalDescription(fn ($record) => __('messages.confirm_transition', [
                        'from' => $record->status->getLabel(),
                        'to' => $label,
                    ]));
            }

            if ($requiresNotes) {
                $action->form([
                    Textarea::make('notes')
                        ->label(__('forms.labels.transition_notes'))
                        ->rows(2)
                        ->maxLength(1000),
                ]);
            }

            $actions[] = $action;
        }

        return ActionGroup::make($actions)
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->size('sm')
            ->visible(fn ($record) => ! empty($record->getAllowedNextStatuses()));
    }

    /**
     * Collect all unique target status values from the allowed transitions map.
     */
    protected static function collectAllTargetStatuses(string $enumClass): array
    {
        if (! method_exists($enumClass, 'cases')) {
            return [];
        }

        return collect($enumClass::cases())
            ->map(fn ($case) => $case->value)
            ->unique()
            ->values()
            ->toArray();
    }

    /**
     * Determine if a transition target is considered destructive (cancelled, rejected, lost, expired).
     */
    protected static function isDestructiveTransition(string $statusValue): bool
    {
        return in_array($statusValue, [
            'cancelled',
            'rejected',
            'lost',
            'expired',
        ]);
    }
}
