<?php

namespace App\Filament\Resources\ProformaInvoices\Tables;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus;
use App\Filament\Actions\StatusTransitionActions;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ProformaInvoicesTable
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
                    ->copyable(),
                TextColumn::make('inquiry.reference')
                    ->label(__('forms.labels.inquiry'))
                    ->searchable()
                    ->sortable()
                    ->url(fn ($record) => $record->inquiry_id
                        ? route('filament.admin.resources.inquiries.view', $record->inquiry_id)
                        : null
                    )
                    ->color('primary'),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray')
                    ->alignCenter(),
                TextColumn::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->sortable(query: fn ($query, $direction) => $query->orderByRaw(
                        '(SELECT COALESCE(SUM(quantity * unit_price), 0) FROM proforma_invoice_items WHERE proforma_invoice_id = proforma_invoices.id) ' . $direction
                    ))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('issue_date')
                    ->label(__('forms.labels.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('valid_until')
                    ->label(__('forms.labels.valid_until'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->color(fn ($record) => $record->valid_until && $record->valid_until->isPast() ? 'danger' : null),
                TextColumn::make('confirmation_method')
                    ->label(__('forms.labels.confirmed_via'))
                    ->badge()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('confirmed_at')
                    ->label(__('forms.labels.confirmed_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.created'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ProformaInvoiceStatus::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.client'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                StatusTransitionActions::make(ProformaInvoiceStatus::class, [
                    'finalized' => [
                        'icon' => 'heroicon-o-lock-closed',
                        'color' => 'primary',
                        'requiresConfirmation' => true,
                    ],
                    'reopened' => [
                        'icon' => 'heroicon-o-lock-open',
                        'color' => 'warning',
                        'requiresConfirmation' => true,
                        'requiresNotes' => true,
                    ],
                ]),
                ViewAction::make(),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No proforma invoices')
            ->emptyStateDescription('Create your first proforma invoice to formalize a deal.')
            ->emptyStateIcon('heroicon-o-document-check');
    }
}
