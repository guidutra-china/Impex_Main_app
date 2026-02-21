<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Quotations\Models\QuotationVersion;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createVersion')
                ->label('Save Version')
                ->icon('heroicon-o-clock')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Save Version Snapshot')
                ->modalDescription('This will create a snapshot of the current quotation state. All items and pricing will be preserved.')
                ->form([
                    Textarea::make('change_notes')
                        ->label('Change Notes')
                        ->placeholder('Describe what changed in this version...')
                        ->rows(3)
                        ->maxLength(2000),
                ])
                ->action(function (array $data) {
                    $quotation = $this->record;

                    $snapshot = [
                        'quotation' => $quotation->toArray(),
                        'items' => $quotation->items->map(function ($item) {
                            return array_merge($item->toArray(), [
                                'suppliers' => $item->suppliers->toArray(),
                            ]);
                        })->toArray(),
                    ];

                    QuotationVersion::create([
                        'quotation_id' => $quotation->id,
                        'version' => $quotation->version,
                        'snapshot' => $snapshot,
                        'change_notes' => $data['change_notes'] ?? null,
                        'created_by' => auth()->id(),
                    ]);

                    $quotation->increment('version');

                    Notification::make()
                        ->title('Version v' . ($quotation->version - 1) . ' saved')
                        ->body('Snapshot created. Now working on v' . $quotation->version . '.')
                        ->success()
                        ->send();

                    $this->refreshFormData(['version']);
                }),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
