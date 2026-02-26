<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Tables;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\AuditType;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Actions\Action;

class SupplierAuditsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.supplier'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('audit_type')
                    ->label(__('forms.labels.type'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('total_score')
                    ->label(__('forms.labels.score'))
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 2) : '—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 4.0 => 'success',
                        $state >= 3.0 => 'warning',
                        default => 'danger',
                    })
                    ->badge()
                    ->sortable()
                    ->alignCenter(),
                TextColumn::make('result')
                    ->label(__('forms.labels.result'))
                    ->badge()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('scheduled_date')
                    ->label(__('forms.labels.scheduled'))
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('conductor.name')
                    ->label(__('forms.labels.auditor'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('conducted_date')
                    ->label(__('forms.labels.conducted'))
                    ->date('Y-m-d')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(AuditStatus::class),
                SelectFilter::make('audit_type')
                    ->label(__('forms.labels.type'))
                    ->options(AuditType::class),
                SelectFilter::make('result')
                    ->label(__('forms.labels.result'))
                    ->options(AuditResult::class),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('conduct')
                    ->label(__('forms.labels.conduct'))
                    ->icon('heroicon-o-pencil-square')
                    ->color('success')
                    ->url(fn ($record) => SupplierAuditResource::getUrl('conduct', ['record' => $record]))
                    ->visible(fn ($record) => in_array($record->status, [AuditStatus::SCHEDULED, AuditStatus::IN_PROGRESS])),
                EditAction::make(),
                DeleteAction::make()
                    ->visible(fn ($record) => $record->status === AuditStatus::SCHEDULED),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('scheduled_date', 'desc')
            ->emptyStateHeading('No supplier audits')
            ->emptyStateDescription('Schedule your first supplier audit to start evaluating your supply chain.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }
}
