<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationVersion;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditQuotation extends EditRecord
{
    protected static string $resource = QuotationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GeneratePdfAction::make(
                templateClass: QuotationPdfTemplate::class,
                label: 'Generate PDF',
            ),
            GeneratePdfAction::download(
                documentType: 'quotation_pdf',
                label: 'Download PDF',
            ),
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
                    try {
                        $savedVersion = DB::transaction(function () use ($data) {
                            $quotation = Quotation::query()
                                ->lockForUpdate()
                                ->findOrFail($this->record->id);

                            $currentVersion = $quotation->version;

                            $snapshot = [
                                'quotation' => $quotation->toArray(),
                                'items' => $quotation->items()
                                    ->with('suppliers')
                                    ->get()
                                    ->map(fn ($item) => array_merge($item->toArray(), [
                                        'suppliers' => $item->suppliers->toArray(),
                                    ]))
                                    ->toArray(),
                            ];

                            QuotationVersion::create([
                                'quotation_id' => $quotation->id,
                                'version' => $currentVersion,
                                'snapshot' => $snapshot,
                                'change_notes' => $data['change_notes'] ?? null,
                                'created_by' => auth()->id(),
                            ]);

                            $quotation->increment('version');

                            return $currentVersion;
                        });

                        Notification::make()
                            ->title('Version v' . $savedVersion . ' saved')
                            ->body('Snapshot created. Now working on v' . ($savedVersion + 1) . '.')
                            ->success()
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->title('Error creating version')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }

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
