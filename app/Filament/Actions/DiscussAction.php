<?php

namespace App\Filament\Actions;

use App\Filament\Pages\Messaging as AdminMessaging;
use App\Filament\Portal\Pages\Messaging as PortalMessaging;
use App\Filament\SupplierPortal\Pages\Messaging as SupplierMessaging;
use Filament\Actions\Action;

/**
 * Header action that opens a messaging thread anchored to the current record.
 *
 * When clicked, it navigates the user to the Messaging page of the current
 * panel with `discuss_entity_type` and `discuss_entity_id` query params.
 * The MessagingCenter component inspects those params in mount(): if a
 * conversation already exists for that entity + current user, it selects it;
 * otherwise it opens the New Conversation modal pre-populated with the entity
 * as subject_entity and with smart-suggested participants.
 */
class DiscussAction
{
    public static function make(string $name = 'discuss'): Action
    {
        return Action::make($name)
            ->label(__('messaging.discuss'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('info')
            ->visible(fn () => auth()->user()?->can('view-messaging') ?? false)
            ->url(function ($record) {
                $pageClass = static::messagingPageForCurrentPanel();
                if (! $pageClass) {
                    return null;
                }

                return $pageClass::getUrl([
                    'discuss_entity_type' => $record::class,
                    'discuss_entity_id'   => $record->getKey(),
                ]);
            });
    }

    /**
     * Resolve which Messaging page class belongs to the current Filament panel.
     */
    private static function messagingPageForCurrentPanel(): ?string
    {
        $panelId = filament()->getCurrentPanel()?->getId();

        return match ($panelId) {
            'admin'           => AdminMessaging::class,
            'portal'          => PortalMessaging::class,
            'supplier-portal' => SupplierMessaging::class,
            default           => null,
        };
    }
}
