<?php

namespace App\Filament\Resources\Finance\Concerns;

use App\Domain\Financial\Enums\PaymentDirection;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Domain\PurchaseOrders\Models\PurchaseOrder;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

trait HasPaymentColumns
{
    public static function tableColumns(PaymentDirection $direction): array
    {
        $companyLabel = $direction === PaymentDirection::INBOUND
            ? __('forms.labels.client')
            : __('forms.labels.supplier');

        return [
            TextColumn::make('payment_date')
                ->label(__('forms.labels.date'))
                ->date('d/m/Y')
                ->sortable(),
            TextColumn::make('company.name')
                ->label($companyLabel)
                ->searchable()
                ->sortable(),
            TextColumn::make('amount')
                ->label(__('forms.labels.amount'))
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->sortable()
                ->searchable(query: fn (Builder $query, string $search) => static::applyAmountSearch($query, $search, 'amount')),
            TextColumn::make('currency_code')
                ->label(__('forms.labels.currency')),
            TextColumn::make('allocated_total')
                ->label(__('forms.labels.allocated'))
                ->getStateUsing(fn ($record) => $record->allocated_total)
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->color('success'),
            TextColumn::make('unallocated_amount')
                ->label(__('forms.labels.unallocated'))
                ->getStateUsing(fn ($record) => $record->unallocated_amount)
                ->formatStateUsing(fn ($state) => Money::format($state))
                ->alignEnd()
                ->color(fn ($state) => $state > 0 ? 'warning' : 'gray'),
            TextColumn::make('allocated_to')
                ->label(__('forms.labels.allocated_to'))
                ->getStateUsing(fn ($record) => static::resolveAllocatedToHtml($record))
                ->html()
                ->placeholder('—')
                ->wrap()
                ->searchable(query: fn (Builder $query, string $search) => static::applyAllocatedToSearch($query, $search))
                ->tooltip(fn ($record) => static::resolveAllocatedToLabels($record) ?: null)
                ->toggleable(),
            TextColumn::make('paymentMethod.name')
                ->label(__('forms.labels.method'))
                ->placeholder('—'),
            TextColumn::make('reference')
                ->label(__('forms.labels.reference'))
                ->placeholder('—')
                ->limit(20)
                ->tooltip(fn ($record) => $record->reference)
                ->searchable(),
            TextColumn::make('status')
                ->label(__('forms.labels.status'))
                ->badge()
                ->sortable(),
            TextColumn::make('creator.name')
                ->label(__('forms.labels.created_by'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
            TextColumn::make('approvedByUser.name')
                ->label(__('forms.labels.approved_by'))
                ->placeholder('—')
                ->toggleable(isToggledHiddenByDefault: true),
        ];
    }

    public static function tableFilters(PaymentDirection $direction): array
    {
        return [
            SelectFilter::make('status')
                ->options(PaymentStatus::class),
            SelectFilter::make('company_id')
                ->label($direction === PaymentDirection::INBOUND
                    ? __('forms.labels.client')
                    : __('forms.labels.supplier'))
                ->relationship('company', 'name')
                ->searchable()
                ->preload(),
        ];
    }

    /**
     * Payables distintos (PI/PO/Shipment) onde este pagamento foi alocado,
     * cada um com seu documento auxiliar: invoice number do fornecedor (PO)
     * ou B/L number (Shipment).
     *
     * @return \Illuminate\Support\Collection<int, array{reference: string, extra: string|null}>
     */
    protected static function resolveAllocatedToEntries($record): \Illuminate\Support\Collection
    {
        $record->loadMissing(['allocations.scheduleItem.payable']);

        return $record->allocations
            ->map(fn ($allocation) => $allocation->scheduleItem?->payable)
            ->filter(fn ($payable) => filled($payable?->reference))
            ->unique(fn ($payable) => $payable->getMorphClass().':'.$payable->getKey())
            ->map(fn ($payable) => [
                'reference' => $payable->reference,
                'extra' => match (true) {
                    $payable instanceof PurchaseOrder => $payable->supplier_invoice_number,
                    $payable instanceof Shipment => $payable->bl_number,
                    default => null,
                },
            ])
            ->values();
    }

    /**
     * Versão em texto puro — "PO-2026-00007 (F0225-12027), SH-2026-00028 (BL123)".
     * Usada no tooltip e na coluna dos relatórios.
     */
    protected static function resolveAllocatedToLabels($record): string
    {
        return static::resolveAllocatedToEntries($record)
            ->map(fn (array $entry) => filled($entry['extra'])
                ? "{$entry['reference']} ({$entry['extra']})"
                : $entry['reference'])
            ->implode(', ');
    }

    /**
     * Versão HTML da célula: invoice/B/L em letra menor e esmaecida logo após
     * a referência. Estilos inline porque o CSS pré-compilado do painel não
     * cobre utilitários arbitrários.
     */
    protected static function resolveAllocatedToHtml($record): ?string
    {
        $html = static::resolveAllocatedToEntries($record)
            ->map(function (array $entry) {
                $label = e($entry['reference']);

                if (filled($entry['extra'])) {
                    $label .= ' <span style="font-size:0.75rem;opacity:0.65;">('.e($entry['extra']).')</span>';
                }

                return $label;
            })
            ->implode(', ');

        return $html !== '' ? $html : null;
    }

    /**
     * Aplica busca por valor monetário em uma coluna armazenada em minor units.
     * Aceita tanto a forma "1500" quanto "1500.50".
     */
    protected static function applyAmountSearch(Builder $query, string $search, string $column): Builder
    {
        $normalized = str_replace(',', '.', trim($search));

        if (! is_numeric($normalized)) {
            return $query->whereRaw('1 = 0');
        }

        $minor = Money::toMinor($normalized);

        return $query->where($column, $minor);
    }

    /**
     * Busca por alocações: aceita valor monetário (em document currency ou
     * payment currency) ou referência do payable (PI/PO/Shipment).
     */
    protected static function applyAllocatedToSearch(Builder $query, string $search): Builder
    {
        $normalized = str_replace(',', '.', trim($search));
        $isNumeric = is_numeric($normalized);
        $minor = $isNumeric ? Money::toMinor($normalized) : null;

        return $query->whereHas('allocations', function (Builder $q) use ($search, $isNumeric, $minor) {
            if ($isNumeric) {
                $q->where('allocated_amount', $minor)
                    ->orWhere('allocated_amount_in_document_currency', $minor);

                return;
            }

            $like = "%{$search}%";

            $q->whereHas('scheduleItem', function (Builder $sq) use ($like) {
                $sq->whereHasMorph(
                    'payable',
                    [ProformaInvoice::class, PurchaseOrder::class, Shipment::class],
                    fn (Builder $pq) => $pq->where('reference', 'like', $like),
                );
            });
        });
    }
}
