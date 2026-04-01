<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Pdf\PdfGeneratorService;
use App\Domain\Infrastructure\Pdf\PdfRenderer;
use App\Domain\Infrastructure\Pdf\Templates\AbstractPdfTemplate;
use App\Domain\Infrastructure\Services\DocumentService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;

class GeneratePdfAction
{
    public static function make(
        string $templateClass,
        string $label = 'Generate PDF',
        string $icon = 'heroicon-o-document-arrow-down',
        array $formSchema = [],
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
            ->form($formSchema)
            ->action(function ($record, array $data = []) use ($templateClass) {
                try {
                    $template = self::createTemplate($templateClass, $record, $data);
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
                        ->body(__('messages.no_pdf_generated'))
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

    /**
     * Create a template instance, mapping form $data keys to constructor parameters.
     */
    protected static function createTemplate(string $templateClass, $record, array $data): AbstractPdfTemplate
    {
        $extraArgs = [];
        $constructor = new \ReflectionMethod($templateClass, '__construct');

        foreach ($constructor->getParameters() as $i => $param) {
            if ($i < 2) {
                continue; // skip $model and $locale
            }

            $name = $param->getName();
            $snakeName = Str::snake($name);

            if ($name === 'options' && $param->getType()?->getName() === 'array') {
                // Pass all form data as the options array
                $extraArgs[] = $data;
            } elseif (array_key_exists($snakeName, $data)) {
                $extraArgs[] = $data[$snakeName];
            } elseif (array_key_exists($name, $data)) {
                $extraArgs[] = $data[$name];
            } else {
                $extraArgs[] = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
            }
        }

        return new $templateClass($record, 'en', ...$extraArgs);
    }

    public static function preview(
        string $templateClass,
        string $label = 'Preview PDF',
        string $icon = 'heroicon-o-eye',
        array $formSchema = [],
    ): Action {
        return Action::make('previewPdf')
            ->label($label)
            ->icon($icon)
            ->color('gray')
            ->visible(fn () => auth()->user()?->can('generate-documents'))
            ->form($formSchema)
            ->action(function ($record, array $data = []) use ($templateClass) {
                try {
                    $template = self::createTemplate($templateClass, $record, $data);
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
