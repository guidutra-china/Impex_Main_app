<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->visible(fn () => auth()->user()?->can('delete-users') && $this->getRecord()->id !== auth()->id()),
        ];
    }

    protected function beforeSave(): void
    {
        $record = $this->getRecord();

        if ($record->id === auth()->id() && ($this->data['status'] ?? null) === 'inactive') {
            Notification::make()
                ->title(__('messages.cannot_deactivate_own'))
                ->danger()
                ->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->getRecord()]);
    }
}
