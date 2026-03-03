<?php

namespace App\Filament\Resources\ProductionSchedules\Pages;

use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use App\Filament\Resources\ProductionSchedules\ProductionScheduleResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ViewProductionSchedule extends ViewRecord
{
    protected static string $resource = ProductionScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            $this->importSpreadsheetAction(),
            EditAction::make(),
        ];
    }

    protected function importSpreadsheetAction(): Action
    {
        return Action::make('importSpreadsheet')
            ->label(__('forms.labels.import_spreadsheet'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('success')
            ->form([
                FileUpload::make('spreadsheet')
                    ->label(__('forms.labels.spreadsheet_file'))
                    ->acceptedFileTypes([
                        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                        'application/vnd.ms-excel',
                    ])
                    ->required()
                    ->disk('local')
                    ->directory('temp-imports'),
            ])
            ->action(function (array $data) {
                try {
                    $path = storage_path('app/' . $data['spreadsheet']);

                    $imported = $this->processSpreadsheet($path);

                    @unlink($path);

                    Notification::make()
                        ->title(__('messages.import_successful'))
                        ->body("{$imported} entries imported.")
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.import_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    protected function processSpreadsheet(string $path): int
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        $schedule = $this->record;
        $pi = $schedule->proformaInvoice;
        $piItems = $pi->items->keyBy('id');

        $piItemsByDescription = $pi->items->keyBy(function ($item) {
            return strtolower(trim($item->description ?? $item->product?->name ?? ''));
        });

        $headerRow = null;
        $dateColumns = [];
        $productColumn = null;
        $imported = 0;

        foreach ($rows as $rowIndex => $row) {
            $values = array_values($row);

            if ($headerRow === null) {
                foreach ($row as $colKey => $cell) {
                    $cellValue = trim((string) $cell);

                    if ($this->looksLikeDate($cellValue)) {
                        $dateColumns[$colKey] = $this->parseDate($cellValue, $sheet, $colKey, $rowIndex);
                    }

                    if ($this->looksLikeProductHeader($cellValue)) {
                        $productColumn = $colKey;
                    }
                }

                if (! empty($dateColumns)) {
                    $headerRow = $rowIndex;
                    continue;
                }

                continue;
            }

            $productName = null;
            if ($productColumn !== null) {
                $productName = trim((string) ($row[$productColumn] ?? ''));
            }

            if (empty($productName)) {
                continue;
            }

            $piItem = $this->matchPiItem($productName, $piItems, $piItemsByDescription);

            if (! $piItem) {
                continue;
            }

            foreach ($dateColumns as $colKey => $date) {
                if ($date === null) {
                    continue;
                }

                $quantity = (int) ($row[$colKey] ?? 0);

                if ($quantity <= 0) {
                    continue;
                }

                ProductionScheduleEntry::updateOrCreate(
                    [
                        'production_schedule_id' => $schedule->id,
                        'proforma_invoice_item_id' => $piItem->id,
                        'date' => $date,
                    ],
                    [
                        'quantity_produced' => $quantity,
                    ]
                );

                $imported++;
            }
        }

        return $imported;
    }

    protected function looksLikeDate(string $value): bool
    {
        if (preg_match('/^\d{1,2}[\/-]\d{1,2}([\/-]\d{2,4})?$/', $value)) {
            return true;
        }

        if (is_numeric($value) && (int) $value > 40000 && (int) $value < 50000) {
            return true;
        }

        return false;
    }

    protected function parseDate(string $value, $sheet, string $colKey, int $rowIndex): ?string
    {
        if (is_numeric($value) && (int) $value > 40000) {
            $timestamp = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((int) $value);
            return $timestamp->format('Y-m-d');
        }

        try {
            return \Carbon\Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function looksLikeProductHeader(string $value): bool
    {
        $lower = strtolower($value);
        return in_array($lower, ['product', 'item', 'description', 'model', 'produto', 'item description']);
    }

    protected function matchPiItem(string $productName, $piItems, $piItemsByDescription): ?ProformaInvoiceItem
    {
        $normalized = strtolower(trim($productName));

        if ($piItemsByDescription->has($normalized)) {
            return $piItemsByDescription->get($normalized);
        }

        foreach ($piItemsByDescription as $key => $item) {
            if (str_contains($key, $normalized) || str_contains($normalized, $key)) {
                return $item;
            }
        }

        foreach ($piItems as $item) {
            $itemProductName = strtolower(trim($item->product?->name ?? ''));
            $itemSku = strtolower(trim($item->product?->sku ?? ''));

            if ($itemProductName && str_contains($normalized, $itemProductName)) {
                return $item;
            }

            if ($itemSku && str_contains($normalized, $itemSku)) {
                return $item;
            }
        }

        return null;
    }
}
