<?php

namespace App\Filament\Actions;

use App\Domain\Catalog\Actions\Import\PreImportAnalysis;
use App\Domain\Catalog\Actions\Import\ProductImportService;
use App\Domain\Catalog\Models\Category;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Services\ClaudeAnalysisService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;

class SmartImportProductsAction
{
    public static function make(string $role, \Closure $getCompany): Action
    {
        $crossRole = $role === 'client' ? 'supplier' : 'client';
        $crossLabel = $role === 'client' ? 'Supplier' : 'Client';

        return Action::make('smartImportProducts')
            ->label(__('forms.labels.smart_import') ?: 'Smart Import (AI)')
            ->icon('heroicon-o-sparkles')
            ->color('warning')
            ->modalHeading('Smart Import — AI-Assisted Product Import')
            ->modalWidth('5xl')
            ->steps([
                // ─── STEP 1: Upload & Configure ───
                \Filament\Schemas\Components\Wizard\Step::make('Upload')
                    ->label('Upload & Configure')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->schema([
                        Select::make('category_id')
                            ->label(__('forms.labels.product_category'))
                            ->options(fn () => Category::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),

                        FileUpload::make('import_file')
                            ->label(__('forms.labels.excel_file_xlsx'))
                            ->acceptedFileTypes([
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->maxSize(51200)
                            ->required()
                            ->disk('local')
                            ->directory('temp/imports'),

                        Select::make('cross_company_id')
                            ->label("Link to {$crossLabel} (optional)")
                            ->options(function () use ($crossRole) {
                                $companyRole = $crossRole === 'client' ? CompanyRole::CLIENT : CompanyRole::SUPPLIER;

                                return Company::withRole($companyRole)->orderBy('name')->pluck('name', 'id');
                            })
                            ->searchable(),

                        Toggle::make('enable_ai_analysis')
                            ->label('Enable AI Analysis')
                            ->helperText('Use Claude AI to detect fuzzy product code matches and validate data quality before importing.')
                            ->default(true),
                    ]),

                // ─── STEP 2: AI Analysis Results ───
                \Filament\Schemas\Components\Wizard\Step::make('Analysis')
                    ->label('AI Analysis')
                    ->icon('heroicon-o-sparkles')
                    ->afterValidation(function ($state, $get, $set) use ($role, $getCompany) {
                        $categoryId = $get('category_id');
                        $filePath = $get('import_file');
                        $enableAi = $get('enable_ai_analysis');

                        if (! $categoryId || ! $filePath) {
                            return;
                        }

                        $category = Category::findOrFail($categoryId);
                        $company = $getCompany();
                        $fullPath = Storage::disk('local')->path($filePath);

                        if (! file_exists($fullPath)) {
                            $set('analysis_html', '<div class="text-danger-600">File not found.</div>');

                            return;
                        }

                        $analyzer = new PreImportAnalysis();
                        $result = $analyzer->analyze($fullPath, $category, $company, $role);

                        // Cache for the import step
                        $sessionKey = Str::random(32);
                        $analyzer->cacheResult($result, $sessionKey);
                        $set('analysis_session_key', $sessionKey);

                        // Build the analysis HTML
                        $html = self::buildAnalysisHtml($result, $enableAi);
                        $set('analysis_html', $html);
                    })
                    ->schema([
                        \Filament\Forms\Components\Hidden::make('analysis_session_key'),

                        Placeholder::make('analysis_html')
                            ->label('')
                            ->content(fn ($get) => new HtmlString(
                                $get('analysis_html') ?? '<div style="padding: 20px; text-align: center; color: #6B7280;">Click "Next" on the previous step to run the analysis.</div>'
                            )),
                    ]),

                // ─── STEP 3: Confirm & Import ───
                \Filament\Schemas\Components\Wizard\Step::make('Import')
                    ->label('Confirm & Import')
                    ->icon('heroicon-o-check-circle')
                    ->schema([
                        Radio::make('conflict_strategy')
                            ->label('When a product already exists (exact or AI-detected match):')
                            ->options([
                                'skip' => 'Skip — keep existing, just link to this company',
                                'update' => 'Update — overwrite existing with Excel values',
                                'create' => 'Create new — always create new products',
                            ])
                            ->default('skip')
                            ->required(),

                        Toggle::make('apply_ai_matches')
                            ->label('Apply AI-detected matches')
                            ->helperText('Treat high-confidence AI fuzzy matches the same as exact matches (apply your conflict strategy to them).')
                            ->default(true),

                        Placeholder::make('import_note')
                            ->label('')
                            ->content(new HtmlString(
                                '<div style="padding: 12px; background: #FEF3C7; border: 1px solid #F59E0B; border-radius: 8px; color: #92400E;">'
                                . '<strong>Note:</strong> Review the analysis results in the previous step before proceeding. '
                                . 'AI warnings are suggestions — the import will proceed regardless.'
                                . '</div>'
                            )),
                    ]),
            ])
            ->action(function (array $data) use ($role, $getCompany, $crossRole) {
                $sessionKey = $data['analysis_session_key'] ?? null;
                $conflictStrategy = $data['conflict_strategy'] ?? 'skip';
                $applyAiMatches = $data['apply_ai_matches'] ?? true;
                $crossCompanyId = $data['cross_company_id'] ?? null;

                if (! $sessionKey) {
                    Notification::make()->title('Analysis required')->body('Please run the analysis step first.')->danger()->send();

                    return;
                }

                $analyzer = new PreImportAnalysis();
                $cached = $analyzer->getCachedResult($sessionKey);

                if (! $cached || empty($cached['rows'])) {
                    Notification::make()->title('Session expired')->body('Analysis data expired. Please re-upload.')->danger()->send();

                    return;
                }

                $category = Category::findOrFail($cached['category_id']);
                $company = $getCompany();
                $rows = $cached['rows'];
                $hasCross = ! empty($rows[0]['_has_cross']);

                // Build conflict resolutions
                $resolutions = [];

                // Exact conflicts
                foreach ($cached['conflicts'] as $conflict) {
                    $resolutions[$conflict['row']] = $conflictStrategy;
                }

                // AI fuzzy matches (treat as conflicts if user opted in)
                if ($applyAiMatches && ! empty($cached['ai_matches'])) {
                    foreach ($cached['ai_matches'] as $match) {
                        if (($match['confidence'] ?? '') === 'high') {
                            $resolutions[$match['row']] = $conflictStrategy;
                        }
                    }
                }

                // Handle cross-company
                $crossCompany = null;
                if ($hasCross && empty($crossCompanyId)) {
                    Notification::make()
                        ->title('Cross-company required')
                        ->body("Template has dual columns. Please select the {$crossRole} company.")
                        ->danger()
                        ->send();

                    return;
                }
                if ($crossCompanyId) {
                    $crossCompany = Company::find($crossCompanyId);
                }

                $service = new ProductImportService();
                $stats = $service->import(
                    $rows,
                    $category,
                    $company,
                    $role,
                    $resolutions,
                    $crossCompany,
                    $crossRole,
                );

                // Clean up
                Cache::forget("pre_import_analysis:{$sessionKey}");
                if (! empty($data['import_file'])) {
                    Storage::disk('local')->delete($data['import_file']);
                }

                // Build result notification
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
                if (($stats['images'] ?? 0) > 0) {
                    $parts[] = "{$stats['images']} images";
                }

                $body = implode(', ', $parts) ?: 'No products processed.';

                $aiInfo = '';
                if (! empty($cached['ai_matches'])) {
                    $matchCount = count($cached['ai_matches']);
                    $aiInfo = "\n🤖 AI detected {$matchCount} fuzzy match(es).";
                }

                if (! empty($stats['errors'])) {
                    $body .= "\n\nErrors:\n" . collect($stats['errors'])->take(5)->implode("\n");
                }

                Notification::make()
                    ->title('Smart Import Complete — ' . count($rows) . ' rows')
                    ->body($body . $aiInfo)
                    ->success()
                    ->persistent()
                    ->send();
            });
    }

    /**
     * Build HTML report from analysis results.
     */
    private static function buildAnalysisHtml(array $result, bool $aiEnabled): string
    {
        $html = '<div style="display: flex; flex-direction: column; gap: 16px;">';

        // Summary bar
        $rowCount = $result['row_count'];
        $errorCount = count($result['standard_errors']);
        $conflictCount = count($result['conflicts']);
        $aiMatchCount = count($result['ai_matches']);
        $aiWarningCount = count($result['ai_warnings']);

        $html .= '<div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">';
        $html .= self::statCard('Rows Found', $rowCount, '#3B82F6');
        $html .= self::statCard('Exact Conflicts', $conflictCount, $conflictCount > 0 ? '#F59E0B' : '#10B981');
        $html .= self::statCard('AI Fuzzy Matches', $aiMatchCount, $aiMatchCount > 0 ? '#8B5CF6' : '#6B7280');
        $html .= self::statCard('AI Warnings', $aiWarningCount, $aiWarningCount > 0 ? '#F59E0B' : '#10B981');
        $html .= '</div>';

        // Standard validation errors
        if (! empty($result['standard_errors'])) {
            $html .= self::section('Validation Errors', '#EF4444', collect($result['standard_errors'])->map(fn ($e) => "<li>{$e}</li>")->implode(''));
        }

        // Exact conflicts
        if (! empty($result['conflicts'])) {
            $items = collect($result['conflicts'])->map(fn ($c) => "<li>Row {$c['row']}: <strong>{$c['name']}</strong> — matches existing SKU <code>{$c['existing_sku']}</code>"
                . ($c['already_linked'] ? ' (already linked)' : '') . '</li>')->implode('');
            $html .= self::section('Exact Product Matches', '#F59E0B', $items);
        }

        // AI fuzzy matches
        if (! empty($result['ai_matches'])) {
            $items = collect($result['ai_matches'])->map(function ($m) {
                $badge = ($m['confidence'] ?? 'medium') === 'high'
                    ? '<span style="background:#10B981;color:white;padding:2px 6px;border-radius:4px;font-size:11px;">HIGH</span>'
                    : '<span style="background:#F59E0B;color:white;padding:2px 6px;border-radius:4px;font-size:11px;">MEDIUM</span>';

                return "<li>Row {$m['row']}: <code>" . htmlspecialchars($m['imported_code'] ?? '') . '</code> → <code>'
                    . htmlspecialchars($m['matched_code'] ?? '') . '</code> '
                    . '(' . htmlspecialchars($m['matched_name'] ?? '') . ') '
                    . $badge . '<br><em style="color:#6B7280;">' . htmlspecialchars($m['reason'] ?? '') . '</em></li>';
            })->implode('');
            $html .= self::section('🤖 AI Fuzzy Matches', '#8B5CF6', $items);
        } elseif ($aiEnabled && $result['ai_available']) {
            $html .= '<div style="padding:12px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:8px;color:#166534;">✅ AI analysis found no fuzzy matches — all product codes are unique or already detected as exact conflicts.</div>';
        }

        // AI warnings
        if (! empty($result['ai_warnings'])) {
            $items = collect($result['ai_warnings'])->map(function ($w) {
                $icon = ($w['severity'] ?? 'warning') === 'error' ? '🔴' : '🟡';
                $suggestion = ! empty($w['suggestion']) ? "<br><em style='color:#059669;'>Sugestão: {$w['suggestion']}</em>" : '';

                return "<li>{$icon} Row {$w['row']} — <strong>" . htmlspecialchars($w['field'] ?? '') . '</strong>: '
                    . htmlspecialchars($w['message'] ?? '') . $suggestion . '</li>';
            })->implode('');
            $html .= self::section('🤖 AI Data Quality Warnings', '#F59E0B', $items);
        } elseif ($aiEnabled && $result['ai_available']) {
            $html .= '<div style="padding:12px;background:#F0FDF4;border:1px solid #86EFAC;border-radius:8px;color:#166534;">✅ AI validation passed — no data quality issues detected.</div>';
        }

        // AI not configured notice
        if ($aiEnabled && ! $result['ai_available']) {
            $html .= '<div style="padding:12px;background:#FEF3C7;border:1px solid #F59E0B;border-radius:8px;color:#92400E;">'
                . '⚠️ AI analysis is not available. Set <code>ANTHROPIC_API_KEY</code> in your <code>.env</code> file to enable fuzzy matching and smart validation.'
                . '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    private static function statCard(string $label, int $value, string $color): string
    {
        return "<div style='padding:12px;background:white;border:1px solid #E5E7EB;border-radius:8px;text-align:center;'>"
            . "<div style='font-size:24px;font-weight:700;color:{$color};'>{$value}</div>"
            . "<div style='font-size:12px;color:#6B7280;'>{$label}</div>"
            . '</div>';
    }

    private static function section(string $title, string $color, string $listItems): string
    {
        return "<div style='padding:12px;background:white;border:1px solid #E5E7EB;border-left:4px solid {$color};border-radius:8px;'>"
            . "<div style='font-weight:600;margin-bottom:8px;color:{$color};'>{$title}</div>"
            . "<ul style='margin:0;padding-left:20px;font-size:13px;list-style:disc;'>{$listItems}</ul>"
            . '</div>';
    }
}
