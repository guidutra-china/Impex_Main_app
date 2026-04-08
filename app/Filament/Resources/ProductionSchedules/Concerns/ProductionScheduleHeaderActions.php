<?php

namespace App\Filament\Resources\ProductionSchedules\Concerns;

use App\Domain\Planning\Actions\GenerateProductionScheduleTemplate;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use App\Domain\ProformaInvoices\Models\ProformaInvoiceItem;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Concrete slot implementations for ProductionScheduleResource pages.
 * Used by both ViewProductionSchedule and EditProductionSchedule for parity.
 *
 * Production schedules don't generate documents in the same sense as other
 * resources — instead they have data tools (download a template, upload a
 * filled spreadsheet). These live in slot 3 (documents) under a "Tools" label.
 */
trait ProductionScheduleHeaderActions
{
    protected function documentsActionGroup(): ?ActionGroup
    {
        return ActionGroup::make([
            $this->downloadTemplateAction(),
            $this->importSpreadsheetAction(),
        ])
            ->label(__('forms.labels.tools'))
            ->icon('heroicon-o-wrench-screwdriver')
            ->color('info')
            ->button();
    }

    protected function downloadTemplateAction(): Action
    {
        return Action::make('downloadTemplate')
            ->label('Download Template')
            ->icon('heroicon-o-arrow-down-tray')
            ->color('gray')
            ->action(function () {
                $path = app(GenerateProductionScheduleTemplate::class)
                    ->execute($this->record);

                return response()->download($path)->deleteFileAfterSend();
            });
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
                    $filePath = $data['spreadsheet'];

                    if (is_array($filePath)) {
                        $filePath = reset($filePath);
                    }

                    $path = storage_path('app/private/' . $filePath);

                    if (! file_exists($path)) {
                        $path = storage_path('app/' . $filePath);
                    }

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
        $schedule = $this->record;
        $pi = $schedule->proformaInvoice;
        $piItems = $pi->items()->with('product')->get();

        $piItemsByName = $piItems->keyBy(function ($item) {
            return strtolower(trim($item->product?->name ?? $item->description ?? ''));
        });

        $reader = new Reader();
        $reader->open($path);

        $imported = 0;
        $rowIndex = 0;
        $isTemplateFormat = false;
        $isSupplierFormat = false;
        $dateColumns = [];
        $productColumn = null;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rowIndex++;
                $cells = $row->getCells();
                $values = array_map(fn ($cell) => $cell->getValue(), $cells);

                if ($rowIndex === 1) {
                    $firstCell = strtolower(trim((string) ($values[0] ?? '')));
                    if (str_contains($firstCell, 'production schedule')) {
                        $isTemplateFormat = true;
                        continue;
                    }
                }

                if ($rowIndex === 2 && $isTemplateFormat) {
                    continue;
                }

                if ($rowIndex === 3 && $isTemplateFormat) {
                    continue;
                }

                if ($isTemplateFormat) {
                    $productName = trim((string) ($values[0] ?? ''));
                    $dateValue = $values[2] ?? null;
                    $quantity = (int) ($values[3] ?? 0);

                    if (empty($productName) || $quantity <= 0) {
                        continue;
                    }

                    $piItem = $this->matchPiItem($productName, $piItemsByName, $piItems);
                    if (! $piItem) {
                        continue;
                    }

                    $date = $this->parseDateValue($dateValue);
                    if (! $date) {
                        continue;
                    }

                    ProductionScheduleEntry::updateOrCreate(
                        [
                            'production_schedule_id' => $schedule->id,
                            'proforma_invoice_item_id' => $piItem->id,
                            'production_date' => $date,
                        ],
                        [
                            'quantity' => $quantity,
                        ]
                    );

                    $imported++;
                    continue;
                }

                if (! $isSupplierFormat) {
                    foreach ($values as $colIndex => $cellValue) {
                        $cellStr = trim((string) $cellValue);

                        if ($this->looksLikeDate($cellStr, $cellValue)) {
                            $dateColumns[$colIndex] = $this->parseDateValue($cellValue);
                        }

                        if ($this->looksLikeProductHeader($cellStr)) {
                            $productColumn = $colIndex;
                        }
                    }

                    if (! empty($dateColumns)) {
                        $isSupplierFormat = true;
                        continue;
                    }

                    continue;
                }

                $productName = null;
                if ($productColumn !== null) {
                    $productName = trim((string) ($values[$productColumn] ?? ''));
                }

                if (empty($productName)) {
                    continue;
                }

                $piItem = $this->matchPiItem($productName, $piItemsByName, $piItems);
                if (! $piItem) {
                    continue;
                }

                foreach ($dateColumns as $colIndex => $date) {
                    if ($date === null) {
                        continue;
                    }

                    $quantity = (int) ($values[$colIndex] ?? 0);
                    if ($quantity <= 0) {
                        continue;
                    }

                    ProductionScheduleEntry::updateOrCreate(
                        [
                            'production_schedule_id' => $schedule->id,
                            'proforma_invoice_item_id' => $piItem->id,
                            'production_date' => $date,
                        ],
                        [
                            'quantity' => $quantity,
                        ]
                    );

                    $imported++;
                }
            }

            break;
        }

        $reader->close();

        return $imported;
    }

    protected function looksLikeDate(string $stringValue, mixed $rawValue): bool
    {
        if ($rawValue instanceof \DateTimeInterface) {
            return true;
        }

        if (preg_match('/^\d{1,2}[\/-]\d{1,2}([\/-]\d{2,4})?$/', $stringValue)) {
            return true;
        }

        if (is_numeric($rawValue) && (int) $rawValue > 40000 && (int) $rawValue < 50000) {
            return true;
        }

        return false;
    }

    protected function parseDateValue(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_numeric($value) && (int) $value > 40000) {
            $unix = ((int) $value - 25569) * 86400;
            return date('Y-m-d', $unix);
        }

        $stringValue = trim((string) $value);
        if (empty($stringValue)) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($stringValue)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    protected function looksLikeProductHeader(string $value): bool
    {
        $lower = strtolower($value);

        return in_array($lower, [
            'product', 'item', 'description', 'model',
            'produto', 'item description', 'product name',
        ]);
    }

    protected function matchPiItem(string $productName, $piItemsByName, $piItems): ?ProformaInvoiceItem
    {
        $normalized = strtolower(trim($productName));

        if ($piItemsByName->has($normalized)) {
            return $piItemsByName->get($normalized);
        }

        foreach ($piItemsByName as $key => $item) {
            if (str_contains($key, $normalized) || str_contains($normalized, $key)) {
                return $item;
            }
        }

        foreach ($piItems as $item) {
            $sku = strtolower(trim($item->product?->sku ?? ''));
            if ($sku && str_contains($normalized, $sku)) {
                return $item;
            }
        }

        return null;
    }
}
