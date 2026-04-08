<?php

namespace App\Filament\Pages;

use BackedEnum;
use Filament\Pages\Page;

class Messaging extends Page
{
    protected string $view = 'filament.pages.messaging';

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?int $navigationSort = 90;

    protected static ?string $slug = 'messaging';

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.crm');
    }

    public static function getNavigationLabel(): string
    {
        return __('messaging.nav_label');
    }

    public function getTitle(): string
    {
        return __('messaging.page_title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-messaging') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = auth()->user()?->unreadMessagesCount() ?? 0;

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'primary';
    }
}
