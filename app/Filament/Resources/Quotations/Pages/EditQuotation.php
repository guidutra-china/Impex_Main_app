<?php

namespace App\Filament\Resources\Quotations\Pages;

use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Pdf\Templates\QuotationPdfTemplate;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationVersion;
use App\Filament\Actions\GeneratePdfAction;
use App\Filament\Resources\Quotations\QuotationResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
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
                ->label(__('forms.labels.save_version'))
                ->icon('heroicon-o-clock')
                ->color('info')
                ->requiresConfirmation()
                ->modalHeading('Save Version Snapshot')
                ->modalDescription('This will create a snapshot of the current quotation state. All items and pricing will be preserved.')
                ->form([
                    Textarea::make('change_notes')
                        ->label(__('forms.labels.change_notes'))
                        ->placeholder(__('forms.placeholders.describe_what_changed_in_this_version'))
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
                            ->title(__('messages.version_saved', ['version' => $savedVersion]))
                            ->body(__('messages.snapshot_created', ['version' => $savedVersion + 1]))
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
            $this->transitionStatusAction(),
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }

    protected function transitionStatusAction(): Action
    {
        return Action::make('transitionStatus')
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrow-path')
            ->color('warning')
            ->visible(fn () => ! empty($this->record->getAllowedNextStatuses()))
            ->form(function () {
                $allowed = $this->record->getAllowedNextStatuses();
                $options = collect($allowed)->mapWithKeys(function ($status) {
                    $enum = QuotationStatus::from($status);
                    return [$status => $enum->getLabel()];
                })->toArray();

                return [
                    Select::make('new_status')
                        ->label(__('forms.labels.new_status'))
                        ->options($options)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('forms.labels.transition_notes'))
                        ->rows(2)
                        ->maxLength(1000),
                ];
            })
            ->action(function (array $data) {
                try {
                    app(TransitionStatusAction::class)->execute(
                        $this->record,
                        QuotationStatus::from($data['new_status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to') . ' ' . QuotationStatus::from($data['new_status'])->getLabel())
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->record]);
    }
}
