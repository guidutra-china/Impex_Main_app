<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Excel\Templates\AbstractExcelTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class GenerateExcelAction
{
    /**
     * Generate Excel and store as Document (versioned, available for email).
     */
    public static function make(
        string $templateClass,
        string $label = 'Generate Excel',
        string $icon = 'heroicon-o-table-cells',
        array $formSchema = [],
    ): Action {
        return Action::make('generateExcel')
            ->label($label)
            ->icon($icon)
            ->color('success')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->requiresConfirmation()
            ->modalHeading('Generate Excel Document')
            ->modalDescription('This will generate a new Excel version. If a previous version exists, it will be archived.')
            ->modalSubmitActionLabel('Generate')
            ->form($formSchema)
            ->action(function ($record, array $data = []) use ($templateClass) {
                try {
                    /** @var AbstractExcelTemplate $template */
                    $template = new $templateClass($record, $data);
                    $path = $template->generate();
                    $content = file_get_contents($path);
                    @unlink($path);

                    $documentService = new DocumentService();
                    $document = $documentService->storeGenerated(
                        documentable: $template->getModel(),
                        content: $content,
                        type: $template->getDocumentType(),
                        name: $template->getFilename(),
                        extension: 'xlsx',
                    );

                    Notification::make()
                        ->title('Excel Generated')
                        ->body("Version {$document->version} created: {$document->name}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Excel Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Download the latest stored Excel document.
     */
    public static function downloadStored(
        string $documentType,
        string $label = 'Download Excel',
        string $icon = 'heroicon-o-arrow-down-tray',
    ): Action {
        return Action::make('downloadExcelStored')
            ->label($label)
            ->icon($icon)
            ->color('info')
            ->visible(fn ($record) => $record->getLatestDocument($documentType) !== null
                && auth()->user()?->can('download-documents'))
            ->action(function ($record) use ($documentType) {
                $document = $record->getLatestDocument($documentType);

                if (! $document || ! $document->exists()) {
                    Notification::make()
                        ->title('Excel Not Found')
                        ->body('Generate the Excel first.')
                        ->warning()
                        ->send();

                    return;
                }

                return response()->streamDownload(
                    function () use ($document) {
                        echo file_get_contents($document->getFullPath());
                    },
                    $document->name,
                    ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
                );
            });
    }

    /**
     * Download Excel directly (on-the-fly, not stored).
     */
    public static function download(
        string $templateClass,
        string $label = 'Download Excel',
        string $icon = 'heroicon-o-table-cells',
        array $formSchema = [],
    ): Action {
        return Action::make('downloadExcel')
            ->label($label)
            ->icon($icon)
            ->color('success')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->form($formSchema)
            ->action(function ($record, array $data = []) use ($templateClass) {
                try {
                    /** @var AbstractExcelTemplate $template */
                    $template = new $templateClass($record, $data);
                    $path = $template->generate();

                    return response()->download($path, $template->getFilename(), [
                        'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    ])->deleteFileAfterSend();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Excel Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
