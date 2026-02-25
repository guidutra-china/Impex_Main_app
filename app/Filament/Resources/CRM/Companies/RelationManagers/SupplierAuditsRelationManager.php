<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\SupplierAudits\Enums\AuditResult;
use App\Domain\SupplierAudits\Enums\AuditStatus;
use App\Domain\SupplierAudits\Enums\AuditType;
use App\Filament\Resources\CRM\SupplierAudits\SupplierAuditResource;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupplierAuditsRelationManager extends RelationManager
{
    protected static string $relationship = 'supplierAudits';

    protected static ?string $title = 'Supplier Audits';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clipboard-document-check';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->color('primary'),
                TextColumn::make('audit_type')
                    ->label('Type')
                    ->badge(),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge(),
                TextColumn::make('total_score')
                    ->label('Score')
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state, 2) . '/5.00' : '—')
                    ->color(fn ($state) => match (true) {
                        $state === null => 'gray',
                        $state >= 4.0 => 'success',
                        $state >= 3.0 => 'warning',
                        default => 'danger',
                    })
                    ->badge(),
                TextColumn::make('result')
                    ->label('Result')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('scheduled_date')
                    ->label('Scheduled')
                    ->date('Y-m-d')
                    ->sortable(),
                TextColumn::make('conductor.name')
                    ->label('Auditor')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(AuditStatus::class),
                SelectFilter::make('result')
                    ->options(AuditResult::class),
            ])
            ->headerActions([
                CreateAction::make()
                    ->url(fn () => SupplierAuditResource::getUrl('create', [
                        'company_id' => $this->getOwnerRecord()->id,
                    ])),
            ])
            ->recordActions([
                ViewAction::make()
                    ->url(fn ($record) => SupplierAuditResource::getUrl('view', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('scheduled_date', 'desc')
            ->emptyStateHeading('No audits yet')
            ->emptyStateDescription('Schedule an audit to evaluate this supplier.')
            ->emptyStateIcon('heroicon-o-clipboard-document-check');
    }
}
