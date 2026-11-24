<?php

namespace App\Filament\Portal\Resources;

use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Financial\Queries\ProformaInvoicePaymentSplit;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Pages;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalProformaInvoiceStats;
use App\Filament\Portal\Resources\ProformaInvoiceResource\Widgets\PortalShipmentFulfillmentWidget;
use App\Filament\Portal\Widgets\ProformaInvoicesListStats;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
            // Pago por grupo vem em subselects; items e additionalCosts
            // carregados de uma vez porque os totais da linha leem os dois.
            ->modifyQueryUsing(fn (Builder $query) => ProformaInvoicePaymentSplit::addSelects($query)
                ->with(['items', 'additionalCosts']))
            ->columns([
                TextColumn::make('reference')
                    ->label(__('forms.labels.reference'))
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
                    ->label(__('forms.labels.status'))
                    ->badge(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('incoterm')
                    ->label(__('forms.labels.incoterm'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('total')
                    ->label(__('forms.labels.products_total'))
                    ->getStateUsing(fn ($record) => $record->total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state, 2))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                static::paidColumn(
                    name: ProformaInvoicePaymentSplit::PRODUCTS_PAID,
                    waivedAttribute: ProformaInvoicePaymentSplit::PRODUCTS_WAIVED,
                    label: __('forms.labels.products_paid'),
                    due: fn (ProformaInvoice $record): int => $record->total,
                ),
                TextColumn::make('other_costs_total')
                    ->label(__('forms.labels.other_costs'))
                    ->getStateUsing(fn (ProformaInvoice $record) => static::otherCostsCharged($record))
                    ->formatStateUsing(fn ($state, $record) => (int) $state === 0
                        ? '—'
                        : ($record->currency_code ?? '').' '.Money::format((int) $state, 2))
                    // Desconto não é custo cobrado: fica fora do valor e
                    // aparece aqui, para a soma com o Total continuar legível.
                    ->description(fn (ProformaInvoice $record) => static::discountsGranted($record) > 0
                        ? __('forms.labels.discount_granted').': '.($record->currency_code ?? '').' '.Money::format(static::discountsGranted($record), 2)
                        : null)
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                static::paidColumn(
                    name: ProformaInvoicePaymentSplit::OTHER_COSTS_PAID,
                    waivedAttribute: ProformaInvoicePaymentSplit::OTHER_COSTS_WAIVED,
                    label: __('forms.labels.other_costs_paid'),
                    due: fn (ProformaInvoice $record): int => static::otherCostsCharged($record),
                    dashWhenEmpty: true,
                ),
                TextColumn::make('grand_total')
                    ->label(__('forms.labels.total'))
                    ->getStateUsing(fn ($record) => $record->grand_total)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state, 2))
                    ->alignRight()
                    ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter(),
                TextColumn::make('shipment_progress')
                    ->label(__('forms.labels.shipped'))
                    ->getStateUsing(fn ($record) => $record->shipment_progress)
                    ->formatStateUsing(fn ($state) => $state.'%')
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
                    ->label(__('forms.labels.created_at'))
                    ->dateTime('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options(\App\Domain\ProformaInvoices\Enums\ProformaInvoiceStatus::class),
            ])
            ->recordUrl(fn (ProformaInvoice $record) => Pages\ViewProformaInvoice::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (ProformaInvoice $record) => Pages\ViewProformaInvoice::getUrl(['record' => $record])),
            ])
            ->persistFiltersInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('widgets.portal.pi.empty_heading'))
            ->emptyStateDescription(__('widgets.portal.pi.empty_description'))
            ->emptyStateIcon('heroicon-o-document-check');
    }

    /**
     * Custos cobrados do cliente além dos produtos (comissão, frete…).
     * Descontos são custos negativos e ficam de fora: ver discountsGranted().
     */
    protected static function otherCostsCharged(ProformaInvoice $record): int
    {
        return (int) $record->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->where('amount_in_document_currency', '>', 0)
            ->sum('amount_in_document_currency');
    }

    /** Descontos dados ao cliente nesta PI, em valor positivo. */
    protected static function discountsGranted(ProformaInvoice $record): int
    {
        return (int) abs($record->additionalCosts
            ->where('billable_to', BillableTo::CLIENT)
            ->where('amount_in_document_currency', '<', 0)
            ->sum('amount_in_document_currency'));
    }

    /**
     * Coluna de "pago" de um grupo (produtos ou outros custos).
     *
     * Verde quando o grupo está quitado, contando o isento como quitado —
     * mesma regra do resumo financeiro da PI no Portal. O valor isento
     * aparece embaixo para o pago menor que o total não parecer dívida.
     *
     * @param  \Closure(ProformaInvoice): int  $due  total devido do grupo
     */
    protected static function paidColumn(
        string $name,
        string $waivedAttribute,
        string $label,
        \Closure $due,
        bool $dashWhenEmpty = false,
    ): TextColumn {
        $waived = fn (ProformaInvoice $record): int => max(0, (int) $record->getAttribute($waivedAttribute));
        $money = fn (ProformaInvoice $record, int $amount): string => ($record->currency_code ?? '').' '.Money::format($amount, 2);

        return TextColumn::make($name)
            ->label($label)
            ->formatStateUsing(fn ($state, ProformaInvoice $record) => $dashWhenEmpty && (int) $state === 0 && $due($record) === 0
                ? '—'
                : $money($record, (int) $state))
            ->description(fn (ProformaInvoice $record) => $waived($record) > 0
                ? __('widgets.document_summary.waived').': '.$money($record, $waived($record))
                : null)
            ->color(function ($state, ProformaInvoice $record) use ($due, $waived) {
                $paid = (int) $state;
                $total = $due($record);
                $tolerance = ProformaInvoice::PAID_ROUNDING_TOLERANCE;

                $settled = $total > 0 && $paid + $waived($record) >= $total - $tolerance;

                return match (true) {
                    $settled => 'success',
                    $paid !== 0 => 'warning',
                    default => null,
                };
            })
            ->sortable(query: fn (Builder $query, string $direction) => $query->orderBy($name, $direction))
            ->alignRight()
            ->visible(fn () => auth()->user()?->can('portal:view-financial-summary'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('widgets.portal.pi.details'))
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference'))
                        ->copyable()
                        ->weight('bold'),
                    TextEntry::make('client_reference')
                        ->label(__('forms.labels.client_reference'))
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('incoterm')
                        ->label(__('forms.labels.incoterm'))
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
                        ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state, 2))
                        ->weight('bold')
                        ->visible(fn () => auth()->user()?->can('portal:view-financial-summary')),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('widgets.portal.pi.items'))
                ->schema([
                    ViewEntry::make('items_table')
                        ->view('portal.infolists.pi-items-table')
                        ->columnSpanFull(),
                ])
                ->columnSpanFull(),

            Section::make(__('widgets.portal.pi.additional_costs'))
                ->schema([
                    ViewEntry::make('additional_costs_table')
                        ->view('portal.infolists.pi-additional-costs-table')
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->clientBillableCosts->isNotEmpty()
                    && auth()->user()?->can('portal:view-financial-summary'))
                ->columnSpanFull(),

            Section::make(__('widgets.portal.pi.production_progress'))
                ->schema([
                    ViewEntry::make('production_progress')
                        ->view('portal.infolists.pi-production-progress')
                        ->columnSpanFull(),
                ])
                ->visible(fn ($record) => $record->productionSchedules()->exists())
                ->columnSpanFull(),

            Section::make(__('widgets.portal.pi.notes'))
                ->schema([
                    TextEntry::make('notes')
                        ->hiddenLabel()
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
