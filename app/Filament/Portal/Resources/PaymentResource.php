<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\Payment;
use App\Domain\Infrastructure\Support\Money;
use App\Filament\Portal\Resources\PaymentResource\Pages;
use App\Filament\Portal\Resources\PaymentResource\Widgets\PortalPaymentAllocations;
use App\Filament\Portal\Widgets\PaymentsListStats;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use BackedEnum;
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
        return auth()->user()?->can('portal:view-payments') ?? false;
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
                TextColumn::make('direction')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('amount')
                    ->label('Amount')
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                    ->alignRight(),
                TextColumn::make('paymentMethod.name')
                    ->label('Method')
                    ->placeholder('—'),
                TextColumn::make('payment_date')
                    ->label('Payment Date')
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
                    ->options(PaymentStatus::class),
                SelectFilter::make('direction')
                    ->options(PaymentDirection::class),
            ])
            ->recordUrl(fn (Payment $record) => Pages\ViewPayment::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (Payment $record) => Pages\ViewPayment::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
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
                    TextEntry::make('direction')
                        ->badge(),
                    TextEntry::make('status')
                        ->badge(),
                    TextEntry::make('amount')
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state))
                        ->weight('bold'),
                    TextEntry::make('paymentMethod.name')
                        ->label('Payment Method')
                        ->placeholder('—'),
                    TextEntry::make('bankAccount.bank_name')
                        ->label('Bank')
                        ->placeholder('—'),
                    TextEntry::make('payment_date')
                        ->date('d/m/Y')
                        ->placeholder('—'),
                ])
                ->columns(3)
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
            PortalPaymentAllocations::class,
            PaymentsListStats::class,
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
