<?php

namespace App\Filament\Actions;

use App\Domain\Infrastructure\Excel\Templates\AbstractExcelTemplate;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

class GenerateExcelAction
{
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
