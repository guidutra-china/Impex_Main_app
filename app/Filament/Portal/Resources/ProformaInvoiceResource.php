<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Pages;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use App\Filament\Portal\Widgets\ProformaInvoicesListStats;
use Filament\Infolists\Components\RepeatableEntry;
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
    protected static ?int $navigationSort = 3;
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
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label('Currency')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('incoterm')
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('issue_date')
                    ->label('Issue Date')
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
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->badge()
                        ->placeholder('—'),
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
                    TextEntry::make('issue_date')
                        ->label('Issue Date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('total')
                        ->label('Total Value')
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make('Items')
                ->schema([
                    RepeatableEntry::make('items')
                        ->schema([
                            TextEntry::make('product.name')
                                ->label('Product'),
                            TextEntry::make('description')
                                ->placeholder('—'),
                            TextEntry::make('quantity')
                                ->alignCenter(),
                            TextEntry::make('unit')
                                ->placeholder('pcs'),
                            TextEntry::make('unit_price')
                                ->formatStateUsing(fn ($state) => Money::format($state))
                                ->alignRight()
                                ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                            TextEntry::make('line_total')
                                ->formatStateUsing(fn ($state) => Money::format($state))
                                ->alignRight()
                                ->weight('bold')
                                ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                        ])
                        ->columns(6)
                        ->columnSpanFull(),
                ])
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
