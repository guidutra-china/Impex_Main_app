<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\Import\GenerateProductImportTemplate;
use App\Domain\Catalog\Actions\Import\ProductImportService;
use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

class ImportProductsFromExcelAction
{
    public static function make(string $role, \Closure $getCompany): Action
    {
        $crossRole = $role === 'client' ? 'supplier' : 'client';
        $crossLabel = $role === 'client' ? 'Supplier' : 'Client';

        return Action::make('importProducts')
            ->label('Import from Excel')
            ->icon('heroicon-o-arrow-up-tray')
            ->color('info')
            ->modalHeading('Import Products from Excel')
            ->modalWidth('3xl')
            ->form([
                Select::make('category_id')
                    ->label('Product Category')
                    ->options(fn () => Category::active()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('Must match the category used when downloading the template.'),

                Placeholder::make('instructions')
                    ->label('')
                    ->content(new HtmlString(
                        '<div style="padding: 12px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px;">'
                        . '<p style="font-weight: 600; color: #1E40AF; margin-bottom: 4px;">Instructions:</p>'
                        . '<ol style="color: #1E3A5F; font-size: 13px; padding-left: 20px; margin: 0;">'
                        . '<li>Use the <strong>Download Template</strong> button to get the correct template</li>'
                        . '<li>Row 1 = sections, Row 2 = headers, Row 3 = hints. <strong>Data starts at Row 4.</strong></li>'
                        . '<li>Base products: fill <strong>Reference Code</strong> with your identifier, leave <strong>Parent Reference</strong> empty</li>'
                        . '<li>Variants: fill <strong>Parent Reference</strong> with the <strong>Reference Code</strong> of the base product</li>'
                        . '<li>Prices in <strong>decimal format</strong> (12.50), NOT cents</li>'
                        . '<li>If template has dual company columns, fill both sections with respective data</li>'
                        . '</ol>'
                        . '</div>'
                    )),

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

                Select::make('cross_company_id')
                    ->label("Link to {$crossLabel} (if template has dual columns)")
                    ->options(function () use ($crossRole) {
                        $companyRole = $crossRole === 'client' ? CompanyRole::CLIENT : CompanyRole::SUPPLIER;

                        return Company::withRole($companyRole)
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->helperText("Select the {$crossLabel} if your template includes dual company link columns. Leave empty if single-company template."),

                Radio::make('conflict_strategy')
                    ->label('If a product with the same name already exists:')
                    ->options([
                        'skip' => 'Skip — keep existing, just link to this company',
                        'update' => 'Update — overwrite existing data with Excel values',
                        'create' => 'Create new — create a new product regardless',
                    ])
                    ->default('skip')
                    ->required(),
            ])
            ->action(function (array $data) use ($role, $getCompany, $crossRole) {
                $categoryId = $data['category_id'];
                $filePath = $data['import_file'];
                $conflictStrategy = $data['conflict_strategy'] ?? 'skip';
                $crossCompanyId = $data['cross_company_id'] ?? null;

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

                $rows = $service->parseFile($fullPath, $category, $role);

                if (empty($rows)) {
                    Notification::make()
                        ->title('Empty file')
                        ->body('No data rows found. Make sure data starts from row 4.')
                        ->warning()
                        ->send();

                    return;
                }

                $hasCross = ! empty($rows[0]['_has_cross']);

                // Validate cross-company selection when template has dual columns
                $crossCompany = null;
                if ($hasCross) {
                    if (empty($crossCompanyId)) {
                        Notification::make()
                            ->title('Cross-company required')
                            ->body("This template has dual company link columns ({$role} + {$crossRole}). Please select the {$crossRole} company.")
                            ->danger()
                            ->send();

                        return;
                    }
                    $crossCompany = Company::find($crossCompanyId);
                } elseif ($crossCompanyId) {
                    // Template is single but user selected a cross-company — link with same data
                    $crossCompany = Company::find($crossCompanyId);
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

                $stats = $service->import(
                    $rows,
                    $category,
                    $company,
                    $role,
                    $resolutions,
                    $crossCompany,
                    $crossRole,
                );

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

                if ($crossCompany) {
                    $body .= "\nAlso linked to {$crossCompany->name} as {$crossRole}.";
                    if ($hasCross) {
                        $body .= ' (with separate company link data)';
                    }
                }

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

    public static function makeDownloadTemplate(string $role): Action
    {
        $crossRole = $role === 'client' ? 'supplier' : 'client';
        $crossLabel = $role === 'client' ? 'Supplier' : 'Client';
        $roleLabel = $role === 'client' ? 'Client' : 'Supplier';

        return Action::make('downloadProductTemplate')
            ->label('Download Template')
            ->icon('heroicon-o-document-arrow-down')
            ->color('gray')
            ->modalHeading('Download Product Import Template')
            ->modalWidth('md')
            ->form([
                Select::make('category_id')
                    ->label('Product Category')
                    ->options(fn () => Category::active()->pluck('name', 'id'))
                    ->searchable()
                    ->required()
                    ->helperText('Select the category to generate the template with the correct attribute columns.'),

                Toggle::make('include_cross_company')
                    ->label("Include {$crossLabel} columns")
                    ->helperText("If enabled, the template will have two sets of company link columns: one for {$roleLabel} data and one for {$crossLabel} data (external codes, prices, etc.).")
                    ->default(false),
            ])
            ->action(function (array $data) use ($role) {
                $category = Category::findOrFail($data['category_id']);
                $includeCross = $data['include_cross_company'] ?? false;
                $generator = new GenerateProductImportTemplate();
                $path = $generator->execute($category, $role, $includeCross);

                return response()->download($path)->deleteFileAfterSend();
            });
    }
}
