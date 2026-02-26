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
        $roleLabel = $role === 'client' ? 'Client' : 'Supplier';

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
                    ->helperText('All products must belong to the same category. The template will include category-specific columns.')
                    ->live(),

                Placeholder::make('instructions')
                    ->label('')
                    ->content(new HtmlString(
                        '<div style="padding: 12px; background: #EFF6FF; border: 1px solid #BFDBFE; border-radius: 8px;">'
                        . '<p style="font-weight: 600; color: #1E40AF; margin-bottom: 4px;">Instructions:</p>'
                        . '<ol style="color: #1E3A5F; font-size: 13px; padding-left: 20px; margin: 0;">'
                        . '<li>Select category above, then <strong>Download Template</strong> below</li>'
                        . '<li>Row 1 = sections, Row 2 = headers, Row 3 = hints. <strong>Data starts at Row 4.</strong></li>'
                        . '<li>Base products: leave <strong>Parent SKU</strong> empty</li>'
                        . '<li>Variants: fill <strong>Parent SKU</strong> with the base product SKU</li>'
                        . '<li>Prices in <strong>decimal format</strong> (12.50), NOT cents</li>'
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

                Toggle::make('link_cross_company')
                    ->label("Also link products to a {$crossLabel}")
                    ->helperText("If enabled, imported products will be linked to both this {$roleLabel} and the selected {$crossLabel}.")
                    ->live()
                    ->default(false),

                Select::make('cross_company_id')
                    ->label("Select {$crossLabel}")
                    ->options(function () use ($crossRole) {
                        $companyRole = $crossRole === 'client' ? CompanyRole::CLIENT : CompanyRole::SUPPLIER;
                        return Company::withRole($companyRole)
                            ->orderBy('name')
                            ->pluck('name', 'id');
                    })
                    ->searchable()
                    ->visible(fn ($get) => $get('link_cross_company'))
                    ->required(fn ($get) => $get('link_cross_company')),

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
                $linkCross = $data['link_cross_company'] ?? false;
                $crossCompanyId = $data['cross_company_id'] ?? null;

                $category = Category::findOrFail($categoryId);

                /** @var Company $company */
                $company = $getCompany();

                $crossCompany = ($linkCross && $crossCompanyId)
                    ? Company::find($crossCompanyId)
                    : null;

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
            ])
            ->action(function (array $data) use ($role) {
                $category = Category::findOrFail($data['category_id']);
                $generator = new GenerateProductImportTemplate();
                $path = $generator->execute($category, $role);

                return response()->download($path)->deleteFileAfterSend();
            });
    }
}
