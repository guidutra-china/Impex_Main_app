<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Quotations\Models\Quotation;
use App\Filament\Portal\Resources\QuotationResource\Pages;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class QuotationResource extends Resource
{
    protected static ?string $model = Quotation::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-text';
    protected static ?int $navigationSort = 2;
    protected static ?string $slug = 'quotations';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('portal:view-quotations') ?? false;
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
                TextColumn::make('total_value')
                    ->label('Total')
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                TextColumn::make('items_count')
                    ->label('Items')
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(\App\Domain\Quotations\Enums\QuotationStatus::class),
            ])
            ->recordActions([
                \Filament\Actions\ViewAction::make(),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No quotations')
            ->emptyStateDescription('No quotations found for your company.')
            ->emptyStateIcon('heroicon-o-document-text');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Quotation Details')
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
                    TextEntry::make('paymentTerm.name')
                        ->label('Payment Terms')
                        ->placeholder('—'),
                    TextEntry::make('validity_date')
                        ->label('Valid Until')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('total_value')
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
                        ->columns(5)
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotations::route('/'),
            'view' => Pages\ViewQuotation::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Operations';
    }

    public static function getNavigationLabel(): string
    {
        return 'Quotations';
    }

    public static function getModelLabel(): string
    {
        return 'Quotation';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Quotations';
    }
}
