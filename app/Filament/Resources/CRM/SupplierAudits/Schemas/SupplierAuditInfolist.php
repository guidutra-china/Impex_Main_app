<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Schemas;

use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Models\AuditCategory;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use App\Filament\Resources\CRM\Companies\CompanyResource;
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
                Section::make(__('forms.sections.audit_information'))
                    ->schema([
                        TextEntry::make('reference')
                            ->label(__('forms.labels.reference'))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->copyable(),
                        TextEntry::make('company.name')
                            ->label(__('forms.labels.supplier'))
                            ->weight(FontWeight::Bold)
                            ->url(fn (SupplierAudit $record) => CompanyResource::getUrl('view', ['record' => $record->company_id])),
                        TextEntry::make('audit_type')
                            ->label(__('forms.labels.type'))
                            ->badge(),
                        TextEntry::make('status')
                            ->label(__('forms.labels.status'))
                            ->badge(),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2]),

                Section::make(__('forms.sections.score_result'))
                    ->schema([
                        TextEntry::make('total_score')
                            ->label(__('forms.labels.total_score'))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large)
                            ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 2) . ' / 5.00' : '—')
                            ->color(fn ($state) => match (true) {
                                $state === null => 'gray',
                                $state >= 4.0 => 'success',
                                $state >= 3.0 => 'warning',
                                default => 'danger',
                            }),
                        TextEntry::make('result')
                            ->label(__('forms.labels.result'))
                            ->badge()
                            ->placeholder(__('forms.placeholders.pending')),
                    ])
                    ->columnSpan(['lg' => 1]),

                Section::make(__('forms.sections.schedule_assignment'))
                    ->schema([
                        TextEntry::make('scheduled_date')
                            ->label(__('forms.labels.scheduled_date'))
                            ->date('Y-m-d'),
                        TextEntry::make('conducted_date')
                            ->label(__('forms.labels.conducted_date'))
                            ->date('Y-m-d')
                            ->placeholder('—'),
                        TextEntry::make('conductor.name')
                            ->label(__('forms.labels.auditor'))
                            ->placeholder(__('forms.placeholders.not_assigned')),
                        TextEntry::make('location')
                            ->label(__('forms.labels.location'))
                            ->placeholder('—'),
                        TextEntry::make('reviewer.name')
                            ->label(__('forms.labels.reviewed_by'))
                            ->placeholder('—'),
                        TextEntry::make('reviewed_at')
                            ->label(__('forms.labels.reviewed_at'))
                            ->dateTime('Y-m-d H:i')
                            ->placeholder('—'),
                    ])
                    ->columns(3),

                Section::make(__('forms.sections.summary_actions'))
                    ->schema([
                        TextEntry::make('summary')
                            ->label(__('forms.labels.summary'))
                            ->placeholder(__('forms.placeholders.no_summary_provided'))
                            ->columnSpanFull(),
                        TextEntry::make('corrective_actions')
                            ->label(__('forms.labels.corrective_actions_required'))
                            ->placeholder(__('forms.placeholders.none'))
                            ->columnSpanFull(),
                        TextEntry::make('next_audit_date')
                            ->label(__('forms.labels.next_audit_date'))
                            ->date('Y-m-d')
                            ->placeholder(__('forms.placeholders.not_scheduled')),
                    ]),

                Section::make(__('forms.sections.category_scores'))
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
