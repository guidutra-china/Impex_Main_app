<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\AbstractPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class GeneratePdfAction
{
    public static function make(
        string $templateClass,
        string $label = 'Generate PDF',
        string $icon = 'heroicon-o-document-arrow-down',
    ): Action {
        return Action::make('generatePdf')
            ->label($label)
            ->icon($icon)
            ->color('success')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->requiresConfirmation()
            ->modalHeading('Generate PDF Document')
            ->modalDescription('This will generate a new PDF version. If a previous version exists, it will be archived.')
            ->modalSubmitActionLabel('Generate')
            ->action(function ($record) use ($templateClass) {
                try {
                    $template = new $templateClass($record);
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
                    );

                    $document = $service->generate($template);

                    Notification::make()
                        ->title('PDF Generated')
                        ->body("Version {$document->version} created: {$document->name}")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('PDF Generation Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    public static function download(
        string $documentType,
        string $label = 'Download PDF',
        string $icon = 'heroicon-o-arrow-down-tray',
    ): Action {
        return Action::make('downloadPdf')
            ->label($label)
            ->icon($icon)
            ->color('info')
            ->visible(fn ($record) => $record->getLatestDocument($documentType) !== null
                && auth()->user()?->can('download-documents'))
            ->action(function ($record) use ($documentType) {
                $document = $record->getLatestDocument($documentType);

                if (! $document || ! $document->exists()) {
                    Notification::make()
                        ->title('PDF Not Found')
                        ->body('No PDF has been generated yet. Please generate one first.')
                        ->warning()
                        ->send();

                    return;
                }

                return response()->streamDownload(
                    function () use ($document) {
                        echo file_get_contents($document->getFullPath());
                    },
                    $document->name,
                    ['Content-Type' => 'application/pdf'],
                );
            });
    }

    public static function preview(
        string $templateClass,
        string $label = 'Preview PDF',
        string $icon = 'heroicon-o-eye',
    ): Action {
        return Action::make('previewPdf')
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->action(function ($record) use ($templateClass) {
                try {
                    $template = new $templateClass($record);
                    $service = new PdfGeneratorService(
                        new PdfRenderer(),
                        new DocumentService(),
                    );

                    $content = $service->preview($template);

                    return response()->streamDownload(
                        function () use ($content) {
                            echo $content;
                        },
                        $template->getFilename(),
                        [
                            'Content-Type' => 'application/pdf',
                            'Content-Disposition' => 'inline; filename="' . $template->getFilename() . '"',
                        ],
                    );
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title('Preview Failed')
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
