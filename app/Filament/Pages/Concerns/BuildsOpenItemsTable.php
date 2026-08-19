<?php

namespace App\Filament\Pages\Concerns;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Financial\Queries\OpenScheduleItemsQuery;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use App\Domain\Settings\Models\Currency;
use App\Filament\Resources\Finance\AccountsPayable\AccountsPayableResource;
use App\Filament\Resources\Finance\AccountsReceivable\AccountsReceivableResource;
use App\Filament\Support\PayableResourceUrl;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Shared table for the admin AR/AP open-item worklists. INBOUND renders the
 * receivable side (clients owe Impex), OUTBOUND the payable side (Impex owes
 * suppliers). Aging is computed per row from due_date vs. today.
 */
trait BuildsOpenItemsTable
{
    protected function openItemsTable(Table $table, PaymentDirection $direction): Table
    {
        $query = $direction === PaymentDirection::INBOUND
            ? OpenScheduleItemsQuery::receivables()
            : OpenScheduleItemsQuery::payables();

        return $table
            ->query($query)
            ->columns([
                TextColumn::make('counterparty')
                    ->label(__('forms.labels.client_supplier'))
                    ->state(fn (PaymentScheduleItem $record) => $record->counterpartyName($direction) ?? '—')
                    // O parâmetro PRECISA se chamar $query: o Filament injeta por
                    // nome, e um nome diferente faz o container montar um Builder
                    // sem model (erro newQueryWithoutRelationships() on null).
                    ->searchable(query: function (Builder $query, string $search) {
                        $query->where(function (Builder $inner) use ($search) {
                            $inner->whereHasMorph('payable', [ProformaInvoice::class, Shipment::class, DebitNote::class], function ($pq) use ($search) {
                                $pq->whereHas('company', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                            })->orWhereHasMorph('payable', [PurchaseOrder::class], function ($pq) use ($search) {
                                $pq->whereHas('supplierCompany', fn ($c) => $c->where('name', 'like', "%{$search}%"));
                            });
                        });
                    }),
                TextColumn::make('payable_ref')
                    ->label(__('forms.labels.document'))
                    ->state(fn (PaymentScheduleItem $record) => $record->payable?->reference ?? '—')
                    ->url(fn (PaymentScheduleItem $record) => PayableResourceUrl::for($record->payable))
                    ->color('primary')
                    ->weight('bold')
                    ->openUrlInNewTab(),
                TextColumn::make('label')
                    ->label(__('forms.labels.schedule_stage'))
                    ->formatStateUsing(fn (PaymentScheduleItem $record) => preg_replace('/\s*\x{2014}\s*\[.*\]\s*$/u', '', $record->label ?? ''))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('aging')
                    ->label(__('open_items.aging.label'))
                    ->state(fn (PaymentScheduleItem $record) => __('open_items.aging.'.static::agingBucket($record)))
                    ->badge()
                    ->color(fn (PaymentScheduleItem $record) => match (static::agingBucket($record)) {
                        'overdue' => 'danger',
                        'd7' => 'warning',
                        'none' => 'gray',
                        default => 'info',
                    }),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state) => Money::formatDisplay($state))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('paid_amount')
                    ->label(__('forms.labels.paid_amount'))
                    ->state(fn (PaymentScheduleItem $record) => $record->paid_amount)
                    ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('remaining_amount')
                    ->label(__('forms.labels.remaining_amount'))
                    ->state(fn (PaymentScheduleItem $record) => $record->remaining_amount)
                    ->formatStateUsing(fn ($state) => Money::formatDisplay((int) $state))
                    ->color(fn ($state) => ((int) $state) > 0 ? 'warning' : 'success')
                    ->alignEnd(),
                TextColumn::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('due_date', 'asc')
            ->filters([
                SelectFilter::make('currency_code')
                    ->label(__('forms.labels.currency'))
                    ->options(fn () => Currency::pluck('code', 'code')),
                SelectFilter::make('status')
                    ->label(__('forms.labels.status'))
                    ->options([
                        PaymentScheduleStatus::PENDING->value => PaymentScheduleStatus::PENDING->getLabel(),
                        PaymentScheduleStatus::DUE->value => PaymentScheduleStatus::DUE->getLabel(),
                        PaymentScheduleStatus::OVERDUE->value => PaymentScheduleStatus::OVERDUE->getLabel(),
                    ]),
                SelectFilter::make('aging')
                    ->label(__('open_items.aging.label'))
                    ->options([
                        'overdue' => __('open_items.aging.overdue'),
                        'd7' => __('open_items.aging.d7'),
                        'd30' => __('open_items.aging.d30'),
                        'd60' => __('open_items.aging.d60'),
                        'd90' => __('open_items.aging.d90'),
                        'd90plus' => __('open_items.aging.d90plus'),
                        'none' => __('open_items.aging.none'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        $value = $data['value'] ?? null;
                        if (! $value) {
                            return;
                        }

                        $today = CarbonImmutable::now()->startOfDay();

                        match ($value) {
                            'overdue' => $query->whereNotNull('due_date')->whereDate('due_date', '<', $today),
                            'd7' => $query->whereNotNull('due_date')->whereDate('due_date', '>=', $today)->whereDate('due_date', '<=', $today->addDays(7)),
                            'd30' => $query->whereNotNull('due_date')->whereDate('due_date', '>', $today->addDays(7))->whereDate('due_date', '<=', $today->addDays(30)),
                            'd60' => $query->whereNotNull('due_date')->whereDate('due_date', '>', $today->addDays(30))->whereDate('due_date', '<=', $today->addDays(60)),
                            'd90' => $query->whereNotNull('due_date')->whereDate('due_date', '>', $today->addDays(60))->whereDate('due_date', '<=', $today->addDays(90)),
                            'd90plus' => $query->whereNotNull('due_date')->whereDate('due_date', '>', $today->addDays(90)),
                            'none' => $query->whereNull('due_date'),
                            default => null,
                        };
                    }),
            ])
            ->toolbarActions([
                BulkAction::make('registrarPagamento')
                    ->label(__('open_items.register_payment'))
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->action(function (Collection $records) use ($direction) {
                        $companyIds = $records
                            ->map(fn (PaymentScheduleItem $r) => $r->counterpartyCompanyId($direction))
                            ->filter()
                            ->unique()
                            ->values();

                        if ($companyIds->count() !== 1) {
                            Notification::make()
                                ->title(__('open_items.mixed_company'))
                                ->danger()
                                ->send();

                            return;
                        }

                        $params = [
                            'company_id' => (int) $companyIds->first(),
                            'schedule_item_ids' => $records->pluck('id')->implode(','),
                        ];

                        $url = $direction === PaymentDirection::INBOUND
                            ? AccountsReceivableResource::getUrl('create', $params)
                            : AccountsPayableResource::getUrl('create', $params);

                        return redirect()->to($url);
                    }),
            ]);
    }

    /**
     * Aging bucket key from due_date vs today.
     */
    protected static function agingBucket(PaymentScheduleItem $record): string
    {
        if ($record->due_date === null) {
            return 'none';
        }

        $today = CarbonImmutable::now()->startOfDay();
        $due = CarbonImmutable::parse($record->due_date)->startOfDay();

        if ($due->lt($today)) {
            return 'overdue';
        }

        $days = $today->diffInDays($due);

        return match (true) {
            $days <= 7 => 'd7',
            $days <= 30 => 'd30',
            $days <= 60 => 'd60',
            $days <= 90 => 'd90',
            default => 'd90plus',
        };
    }
}
