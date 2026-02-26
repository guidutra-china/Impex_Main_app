<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\Import\GenerateProductImportTemplate;
use App\Domain\Catalog\Actions\Import\ProductImportService;
use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Wizard;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ImportProductsFromExcelAction
{
    public static function make(string $role, \Closure $getCompany): Action
    {
        return Action::make('importProducts')
            ->label('Import from Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->modalHeading('Import Products from Excel')
            ->modalWidth('4xl')
            ->form([
                Wizard::make([
                    Wizard\Step::make('Category & Template')
                        ->icon('heroicon-o-document-arrow-down')
                        ->description('Select category and download template')
                        ->schema([
                            Select::make('category_id')
                                ->label('Product Category')
                                ->options(fn () => Category::active()->pluck('name', 'id'))
                                ->searchable()
                                ->required()
                                ->helperText('All products in this import must belong to the same category.')
                                ->live(),

                            Placeholder::make('template_instructions')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div style="padding: 12px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px; margin-top: 8px;">'
                                    . '<p style="font-weight: 600; color: #1E40AF; margin-bottom: 4px;">How to use:</p>'
                                    . '<ol style="color: #1E3A5F; font-size: 13px; padding-left: 20px; margin: 0;">'
                                    . '<li>Select the category above</li>'
                                    . '<li>Click <strong>Next</strong>, then use the <strong>Download Template</strong> button</li>'
                                    . '<li>Row 1 = sections, Row 2 = headers, Row 3 = hints. Data starts at Row 4.</li>'
                                    . '<li>Base products: leave <strong>Parent SKU</strong> empty</li>'
                                    . '<li>Variants: fill <strong>Parent SKU</strong> with the SKU of the base product</li>'
                                    . '<li>Prices in <strong>decimal format</strong> (e.g. 12.50), NOT in cents</li>'
                                    . '</ol>'
                                    . '</div>'
                                )),
                        ]),

                    Wizard\Step::make('Upload File')
                        ->icon('heroicon-o-arrow-up-tray')
                        ->description('Download template and upload filled file')
                        ->schema([
                            SchemaActions::make([
                                Action::make('downloadTemplate')
                                    ->label('Download Template')
                                    ->icon('heroicon-o-document-arrow-down')
                                    ->color('success')
                                    ->action(function (Action $action, $livewire) use ($role) {
                                        $categoryId = data_get($livewire->mountedActionsData, '0.category_id')
                                            ?? data_get($livewire->mountedActionsData, 'category_id');

                                        if (! $categoryId) {
                                            Notification::make()
                                                ->title('Select a category first')
                                                ->danger()
                                                ->send();
                                            return;
                                        }

                                        $category = Category::findOrFail($categoryId);
                                        $generator = new GenerateProductImportTemplate();
                                        $path = $generator->execute($category, $role);

                                        return response()->download($path)->deleteFileAfterSend();
                                    }),
                            ]),

                            FileUpload::make('import_file')
                                ->label('Excel File (.xlsx)')
                                ->acceptedFileTypes([
                                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                ])
                                ->maxSize(10240)
                                ->required()
                                ->disk('local')
                                ->directory('temp/imports')
                                ->helperText('Upload the filled template. Max 10MB.'),
                        ]),

                    Wizard\Step::make('Review & Import')
                        ->icon('heroicon-o-check-circle')
                        ->description('Review and confirm import')
                        ->schema([
                            Placeholder::make('review_info')
                                ->label('')
                                ->content(new HtmlString(
                                    '<div style="padding: 12px; background: #FEF3C7; border: 1px solid #FCD34D; border-radius: 8px;">'
                                    . '<p style="font-weight: 600; color: #92400E;">Before importing:</p>'
                                    . '<p style="color: #78350F; font-size: 13px;">Choose how to handle products that already exist in the system, then click <strong>Import</strong>.</p>'
                                    . '</div>'
                                )),

                            Radio::make('conflict_strategy')
                                ->label('If a product with the same name already exists:')
                                ->options([
                                    'skip' => 'Skip — keep existing, just link to this company',
                                    'update' => 'Update — overwrite existing data with Excel values',
                                    'create' => 'Create new — create a new product regardless',
                                ])
                                ->default('skip')
                                ->required(),
                        ]),
                ])
                    ->skippable(false)
                    ->columnSpanFull(),
            ])
            ->action(function (array $data) use ($role, $getCompany) {
                $categoryId = $data['category_id'];
                $filePath = $data['import_file'];
                $conflictStrategy = $data['conflict_strategy'] ?? 'skip';

                $category = Category::findOrFail($categoryId);

                /** @var Company $company */
                $company = $getCompany();

                $fullPath = Storage::disk('local')->path($filePath);

                if (! file_exists($fullPath)) {
                    Notification::make()
                        ->title('File not found')
                        ->body('The uploaded file could not be located. Please try again.')
                        ->danger()
                        ->send();
                    return;
                }

                $service = new ProductImportService();

                $rows = $service->parseFile($fullPath, $category);

                if (empty($rows)) {
                    Notification::make()
                        ->title('Empty file')
                        ->body('No data rows found. Make sure data starts from row 4.')
                        ->warning()
                        ->send();
                    return;
                }

                $errors = $service->validate($rows, $category, $company, $role);

                if (! empty($errors)) {
                    $errorList = collect($errors)->take(10)->implode("\n");
                    $remaining = count($errors) - 10;

                    Notification::make()
                        ->title('Validation Errors (' . count($errors) . ')')
                        ->body($errorList . ($remaining > 0 ? "\n...and {$remaining} more." : ''))
                        ->danger()
                        ->persistent()
                        ->send();
                    return;
                }

                $conflicts = $service->detectConflicts($rows, $company, $role);
                $resolutions = [];
                foreach ($conflicts as $conflict) {
                    $resolutions[$conflict['row']] = $conflictStrategy;
                }

                $stats = $service->import($rows, $category, $company, $role, $resolutions);

                Storage::disk('local')->delete($filePath);

                $parts = [];
                if ($stats['created'] > 0) {
                    $parts[] = "{$stats['created']} created";
                }
                if ($stats['updated'] > 0) {
                    $parts[] = "{$stats['updated']} updated";
                }
                if ($stats['skipped'] > 0) {
                    $parts[] = "{$stats['skipped']} skipped (linked)";
                }

                $body = implode(', ', $parts) ?: 'No products processed.';

                if (! empty($stats['errors'])) {
                    $body .= "\n\nErrors:\n" . collect($stats['errors'])->take(5)->implode("\n");
                }

                Notification::make()
                    ->title('Import Complete — ' . count($rows) . ' rows processed')
                    ->body($body)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }
}
