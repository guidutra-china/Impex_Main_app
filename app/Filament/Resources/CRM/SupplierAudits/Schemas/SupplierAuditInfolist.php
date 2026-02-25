<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Schemas;

use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Domain\SupplierAudits\Services\AuditScoringService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class SupplierAuditInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Audit Information')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Reference')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->copyable(),
                        TextEntry::make('company.name')
                            ->label('Supplier')
                            ->weight(FontWeight::Bold)
                            ->url(fn (SupplierAudit $record) => route('filament.admin.resources.crm/companies.view', $record->company_id)),
                        TextEntry::make('audit_type')
                            ->label('Type')
                            ->badge(),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2]),

                Section::make('Score & Result')
                    ->schema([
                        TextEntry::make('total_score')
                            ->label('Total Score')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::ExtraLarge)
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 2) . ' / 5.00' : '—')
                            ->color(fn ($state) => match (true) {
                                $state === null => 'gray',
                                $state >= 4.0 => 'success',
                                $state >= 3.0 => 'warning',
                                default => 'danger',
                            }),
                        TextEntry::make('result')
                            ->label('Result')
                            ->badge()
                            ->placeholder('Pending'),
                    ])
                    ->columnSpan(['lg' => 1]),

                Section::make('Schedule & Assignment')
                    ->schema([
                        TextEntry::make('scheduled_date')
                            ->label('Scheduled Date')
                            ->date('Y-m-d'),
                        TextEntry::make('conducted_date')
                            ->label('Conducted Date')
                            ->date('Y-m-d')
                            ->placeholder('—'),
                        TextEntry::make('conductor.name')
                            ->label('Auditor')
                            ->placeholder('Not assigned'),
                        TextEntry::make('location')
                            ->label('Location')
                            ->placeholder('—'),
                        TextEntry::make('reviewer.name')
                            ->label('Reviewed By')
                            ->placeholder('—'),
                        TextEntry::make('reviewed_at')
                            ->label('Reviewed At')
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make('Summary & Actions')
                    ->schema([
                        TextEntry::make('summary')
                            ->label('Summary')
                            ->placeholder('No summary provided')
                            ->columnSpanFull(),
                        TextEntry::make('corrective_actions')
                            ->label('Corrective Actions Required')
                            ->placeholder('None')
                            ->columnSpanFull(),
                        TextEntry::make('next_audit_date')
                            ->label('Next Audit Date')
                            ->date('Y-m-d')
                            ->placeholder('Not scheduled'),
                    ]),

                Section::make('Category Scores')
                    ->schema(fn (SupplierAudit $record) => static::buildCategoryScoreEntries($record))
                    ->columns(3)
                    ->visible(fn (SupplierAudit $record) => $record->status !== AuditStatus::SCHEDULED),
            ]);
    }

    protected static function buildCategoryScoreEntries(SupplierAudit $record): array
    {
        $scoring = app(AuditScoringService::class)->calculate($record);
        $entries = [];

        foreach ($scoring['category_scores'] as $categoryScore) {
            $entries[] = TextEntry::make('category_score_' . $categoryScore['name'])
                ->label($categoryScore['name'] . ' (' . $categoryScore['weight'] . '%)')
                ->state(fn () => $categoryScore['average'] !== null
                    ? number_format($categoryScore['average'], 2) . ' / 5.00'
                    : '—')
                ->badge()
                ->color(fn () => match (true) {
                    $categoryScore['average'] === null => 'gray',
                    $categoryScore['average'] >= 4.0 => 'success',
                    $categoryScore['average'] >= 3.0 => 'warning',
                    default => 'danger',
                })
                ->helperText($categoryScore['answered_count'] . '/' . $categoryScore['criteria_count'] . ' criteria evaluated');
        }

        return $entries;
    }
}
