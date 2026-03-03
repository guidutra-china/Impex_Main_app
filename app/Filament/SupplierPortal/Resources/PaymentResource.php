<?php

namespace App\Filament\SupplierPortal\Resources;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\SupplierPortal\Resources\PaymentResource\Pages;
use App\Filament\SupplierPortal\Resources\PaymentResource\Widgets\SupplierPaymentAllocations;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;
    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-banknotes';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'payments';
    protected static ?string $recordTitleAttribute = 'reference';
    protected static ?string $tenantOwnershipRelationshipName = 'company';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('supplier-portal:view-payments') ?? false;
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
            ->modifyQueryUsing(function ($query) {
                $query->where('direction', PaymentDirection::OUTBOUND);
            })
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
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignRight(),
                TextColumn::make('unallocated_amount')
                    ->label('Unallocated')
                    ->getStateUsing(fn ($record) => $record->unallocated_amount)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignRight()
                    ->color(fn ($record) => $record->unallocated_amount > 0 ? 'warning' : 'success'),
                TextColumn::make('payment_date')
                    ->label('Payment Date')
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentStatus::class),
            ])
            ->recordUrl(fn (Payment $record) => Pages\ViewPayment::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (Payment $record) => Pages\ViewPayment::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('payment_date', 'desc')
            ->emptyStateHeading('No payments')
            ->emptyStateDescription('No payments found for your company.')
            ->emptyStateIcon('heroicon-o-banknotes');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Payment Details')
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
                    TextEntry::make('amount')
                        ->label('Amount')
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                        ->weight('bold'),
                    TextEntry::make('payment_date')
                        ->label('Payment Date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                    TextEntry::make('paymentMethod.name')
                        ->label('Payment Method')
                        ->placeholder('—'),
                    TextEntry::make('notes')
                        ->placeholder('—')
                        ->columnSpanFull(),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public static function getWidgets(): array
    {
        return [
            SupplierPaymentAllocations::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPayments::route('/'),
            'view' => Pages\ViewPayment::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.payments');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.payment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.payments');
    }
}
