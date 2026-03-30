<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Pages;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use App\Filament\Portal\Widgets\ProformaInvoicesListStats;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProformaInvoiceResource extends Resource
{
    protected static ?string $model = ProformaInvoice::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-check';
    protected static ?int $navigationSort = 42;
    protected static ?string $slug = 'proforma-invoices';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-proforma-invoices') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->searchable(query: function ($query, string $search): void {
                        $query->where('reference', 'like', "%{$search}%")
                            ->orWhere('client_reference', 'like', "%{$search}%")
                            ->orWhereHas('items', function ($q) use ($search) {
                                $q->where('description', 'like', "%{$search}%")
                                    ->orWhereHas('product', fn ($pq) => $pq
                                        ->where('name', 'like', "%{$search}%")
                                        ->orWhere('model_number', 'like', "%{$search}%")
                                        ->orWhere('sku', 'like', "%{$search}%")
                                    );
                            });
                    })
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('client_reference')
                    ->label(__('forms.labels.client_reference'))
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('incoterm')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label(__('forms.labels.products_total'))
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                TextColumn::make('grand_total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->grand_total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('shipment_progress')
                    ->label(__('forms.labels.shipped'))
                    ->getStateUsing(fn ($record) => $record->shipment_progress)
                    ->formatStateUsing(fn ($state) => $state . '%')
                    ->alignCenter()
                    ->color(fn ($state) => match (true) {
                        $state >= 100 => 'success',
                        $state > 0 => 'warning',
                        default => 'gray',
                    })
                    ->badge(),
                TextColumn::make('issue_date')
                    ->label(__('forms.labels.issue_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(\App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus::class),
            ])
            ->recordUrl(fn (ProformaInvoice $record) => Pages\ViewProformaInvoice::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (ProformaInvoice $record) => Pages\ViewProformaInvoice::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No proforma invoices')
            ->emptyStateDescription('No proforma invoices found for your company.')
            ->emptyStateIcon('heroicon-o-document-check');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Proforma Invoice Details')
                ->schema([
                    TextEntry::make('reference')
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('client_reference')
                        ->label(__('forms.labels.client_reference'))
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label(__('forms.labels.payment_terms'))
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label(__('forms.labels.issue_date'))
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('grand_total')
                        ->label(__('forms.labels.total'))
                        ->getStateUsing(fn ($record) => $record->grand_total)
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                        ->weight('bold')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Items')
                ->schema([
                    ViewEntry::make('items_table')
                        ->view('portal.infolists.pi-items-table')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make('Additional Costs')
                ->schema([
                    ViewEntry::make('additional_costs_table')
                        ->view('portal.infolists.pi-additional-costs-table')
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->clientBillableCosts->isNotEmpty()
                    && auth()->user()?->can('portal:view-financial-summary'))
                ->columnSpanFull(),

            Section::make('Production Progress')
                ->schema([
                    ViewEntry::make('production_progress')
                        ->view('portal.infolists.pi-production-progress')
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->productionSchedules()->exists())
                ->columnSpanFull(),

            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->collapsible()
                ->collapsed()
                ->columnSpanFull(),
        ]);
    }

    public static function getWidgets(): array
    {
        return [
            PortalProformaInvoiceStats::class,
            PortalShipmentFulfillmentWidget::class,
            ProformaInvoicesListStats::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProformaInvoices::route('/'),
            'view' => Pages\ViewProformaInvoice::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.proforma_invoices');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.proforma_invoice');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.proforma_invoices');
    }
}
