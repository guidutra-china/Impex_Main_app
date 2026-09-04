<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\CRM\Models\Contact;
use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

/**
 * Renders a QuotationVersion snapshot as a structured infolist.
 *
 * Reads from $record->snapshot (cast to array). Quotation::saveVersion()
 * denormalizes names into the snapshot for new versions, but legacy
 * snapshots (saved before the refactor) only carry IDs — for those we
 * fall back to a live FK lookup so the historical view stays readable.
 */
class QuotationVersionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.version_quotation_header'))
                ->schema(static::headerEntries())
                ->columns(3),

            Section::make(__('forms.sections.version_items'))
                ->schema([static::itemsRepeater()])
                ->columnSpanFull(),

            Section::make(__('forms.sections.version_meta'))
                ->schema(static::metaEntries())
                ->columns(3),
        ]);
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function headerEntries(): array
    {
        return [
            TextEntry::make('version')
                ->label(__('forms.labels.version'))
                ->prefix('v')
                ->weight(FontWeight::Bold),
            TextEntry::make('v_reference')
                ->label(__('forms.labels.reference'))
                ->state(fn ($record) => static::q($record, 'reference', '—'))
                ->copyable(),
            TextEntry::make('v_status')
                ->label(__('forms.labels.status'))
                ->state(fn ($record) => static::q($record, 'status', '—'))
                ->badge(),
            TextEntry::make('v_company')
                ->label(__('forms.labels.client'))
                ->state(function ($record) {
                    $q = $record->snapshot['quotation'] ?? [];
                    if (! empty($q['company']['name'])) {
                        return $q['company']['name'];
                    }
                    if (! empty($q['company_id'])) {
                        return optional(Company::find($q['company_id']))->name
                            ?? '#'.$q['company_id'];
                    }

                    return '—';
                })
                ->icon('heroicon-o-building-office-2'),
            TextEntry::make('v_contact')
                ->label(__('forms.labels.contact'))
                ->state(function ($record) {
                    $q = $record->snapshot['quotation'] ?? [];
                    if (! empty($q['contact']['name'])) {
                        return $q['contact']['name'];
                    }
                    if (! empty($q['contact_id'])) {
                        return optional(Contact::find($q['contact_id']))->name ?? '—';
                    }

                    return '—';
                })
                ->icon('heroicon-o-user'),
            TextEntry::make('v_valid_until')
                ->label(__('forms.labels.valid_until'))
                ->state(fn ($record) => static::formatDate(static::q($record, 'valid_until'))),
            TextEntry::make('v_currency')
                ->label(__('forms.labels.currency'))
                ->state(fn ($record) => static::q($record, 'currency_code', '—'))
                ->badge()
                ->color('gray'),
            TextEntry::make('v_commission_type')
                ->label(__('forms.labels.commission_model'))
                ->state(fn ($record) => static::q($record, 'commission_type', '—'))
                ->badge(),
            TextEntry::make('v_commission_rate')
                ->label(__('forms.labels.commission_rate'))
                ->state(function ($record) {
                    $rate = static::q($record, 'commission_rate');
                    if ($rate === null || $rate === '') {
                        return '—';
                    }

                    return number_format((float) $rate, 2).'%';
                }),
            TextEntry::make('v_subtotal')
                ->label(__('forms.labels.subtotal'))
                ->state(fn ($record) => static::money(static::computedSubtotal($record))),
            TextEntry::make('v_commission_amount')
                ->label(__('forms.labels.commission_separate'))
                ->state(fn ($record) => static::money(static::computedCommission($record))),
            TextEntry::make('v_total')
                ->label(__('forms.labels.total'))
                ->state(fn ($record) => static::money(static::computedTotal($record)))
                ->weight(FontWeight::Bold)
                ->color('success'),
            TextEntry::make('v_notes')
                ->label(__('forms.labels.client_notes'))
                ->state(fn ($record) => (string) (static::q($record, 'notes') ?? '—'))
                ->columnSpanFull(),
            TextEntry::make('v_internal_notes')
                ->label(__('forms.labels.internal_notes'))
                ->state(fn ($record) => (string) (static::q($record, 'internal_notes') ?? '—'))
                ->columnSpanFull(),
        ];
    }

    /**
     * Items repeater. Children use Filament's auto-resolution against the
     * array row — each TextEntry::make('key') reads $row['key'] via data_get,
     * including dotted paths like 'product.name'. We pre-hydrate each row
     * with denormalized fields (product_name, product_sku, supplier_name,
     * suppliers_summary, line_total) so legacy snapshots without nested
     * objects still render.
     */
    protected static function itemsRepeater(): RepeatableEntry
    {
        return RepeatableEntry::make('items_list')
            ->label('')
            ->state(function ($record) {
                $items = $record->snapshot['items'] ?? [];

                return collect($items)->map(function (array $row) {
                    $productName = $row['product']['name']
                        ?? optional(! empty($row['product_id']) ? Product::find($row['product_id']) : null)->name
                        ?? $row['description']
                        ?? '#'.($row['product_id'] ?? '?');
                    $productSku = $row['product']['sku']
                        ?? optional(! empty($row['product_id']) ? Product::find($row['product_id']) : null)->sku
                        ?? '—';

                    $supplierName = $row['selected_supplier']['name']
                        ?? (! empty($row['selected_supplier_id'])
                            ? optional(Company::find($row['selected_supplier_id']))->name ?? '—'
                            : '—');

                    $alts = $row['suppliers'] ?? [];
                    $altsSummary = empty($alts) ? '—' : collect($alts)
                        ->map(function ($s) {
                            $name = $s['company']['name']
                                ?? (! empty($s['company_id'])
                                    ? optional(Company::find($s['company_id']))->name ?? ('#'.$s['company_id'])
                                    : '—');
                            $cur = $s['currency_code'] ?? '';
                            $cost = static::money($s['unit_cost'] ?? 0, 4);

                            return trim($name.': '.$cur.' '.$cost);
                        })
                        ->implode(' · ');

                    $unitCostExtras = [];
                    if (! empty($row['cost_currency_code'])) {
                        $unitCostExtras[] = $row['cost_currency_code'];
                    }
                    if (! empty($row['cost_exchange_rate']) && (float) $row['cost_exchange_rate'] > 0 && (float) $row['cost_exchange_rate'] !== 1.0) {
                        $unitCostExtras[] = '@ '.number_format((float) $row['cost_exchange_rate'], 6);
                    }
                    if (! empty($row['cost_exchange_rate_captured_at'])) {
                        $unitCostExtras[] = static::formatDate($row['cost_exchange_rate_captured_at']);
                    }
                    $unitCost = static::money($row['unit_cost'] ?? 0, 4)
                        .(empty($unitCostExtras) ? '' : ' ('.implode(' · ', $unitCostExtras).')');

                    $lineTotal = (int) ($row['unit_price'] ?? 0) * (int) ($row['quantity'] ?? 0);

                    return [
                        'product_name' => $productName,
                        'product_sku' => $productSku,
                        'qty' => (int) ($row['quantity'] ?? 0),
                        // Versões antigas (antes da coluna) não trazem unit.
                        'unit' => $row['unit'] ?? 'pcs',
                        'unit_cost_display' => $unitCost,
                        'unit_price_display' => static::money($row['unit_price'] ?? 0, 4),
                        'line_total_display' => static::money($lineTotal),
                        'supplier_name' => $supplierName,
                        'suppliers_summary' => $altsSummary,
                    ];
                })->toArray();
            })
            ->schema([
                TextEntry::make('product_name')
                    ->label(__('forms.labels.product'))
                    ->weight(FontWeight::Bold),
                TextEntry::make('product_sku')
                    ->label(__('forms.labels.sku'))
                    ->badge()
                    ->color('gray'),
                TextEntry::make('qty')
                    ->label(__('forms.labels.qty'))
                    ->alignCenter(),
                TextEntry::make('unit')
                    ->label(__('forms.labels.unit'))
                    ->alignCenter(),
                TextEntry::make('unit_cost_display')
                    ->label(__('forms.labels.unit_cost')),
                TextEntry::make('unit_price_display')
                    ->label(__('forms.labels.unit_price')),
                TextEntry::make('line_total_display')
                    ->label(__('forms.labels.line_total'))
                    ->weight(FontWeight::Bold),
                TextEntry::make('supplier_name')
                    ->label(__('forms.labels.supplier'))
                    ->placeholder('—'),
                TextEntry::make('suppliers_summary')
                    ->label(__('forms.sections.version_alternatives'))
                    ->columnSpanFull(),
            ])
            ->columns(7);
    }

    /**
     * @return array<int, TextEntry>
     */
    protected static function metaEntries(): array
    {
        return [
            TextEntry::make('creator.name')
                ->label(__('forms.labels.created_by'))
                ->placeholder(__('forms.placeholders.system')),
            TextEntry::make('created_at')
                ->label(__('forms.labels.snapshot_date'))
                ->dateTime('d/m/Y H:i:s'),
            TextEntry::make('change_notes')
                ->label(__('forms.labels.change_notes'))
                ->placeholder(__('forms.placeholders.no_notes_2'))
                ->columnSpanFull(),
        ];
    }

    /** Read a key from $record->snapshot['quotation'] safely. */
    protected static function q($record, string $key, mixed $default = null): mixed
    {
        $q = $record->snapshot['quotation'] ?? [];

        return $q[$key] ?? $default;
    }

    /** Subtotal — prefer snapshot's pre-computed value, else sum from items. */
    protected static function computedSubtotal($record): int
    {
        $explicit = static::q($record, 'subtotal');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        return collect($record->snapshot['items'] ?? [])
            ->sum(fn ($i) => (int) ($i['unit_price'] ?? 0) * (int) ($i['quantity'] ?? 0));
    }

    protected static function computedCommission($record): int
    {
        $explicit = static::q($record, 'commission_amount');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }
        $type = static::q($record, 'commission_type');
        $rate = (float) (static::q($record, 'commission_rate') ?? 0);
        if ($type !== 'separate' || $rate <= 0) {
            return 0;
        }

        return (int) round(static::computedSubtotal($record) * ($rate / 100));
    }

    protected static function computedTotal($record): int
    {
        $explicit = static::q($record, 'total');
        if ($explicit !== null && $explicit !== '') {
            return (int) $explicit;
        }

        return static::computedSubtotal($record) + static::computedCommission($record);
    }

    protected static function money(mixed $minor, int $decimals = 2): string
    {
        if ($minor === null || $minor === '') {
            return '—';
        }

        return Money::format((int) $minor, $decimals);
    }

    protected static function formatDate(?string $value): string
    {
        if (! $value) {
            return '—';
        }
        try {
            return \Carbon\Carbon::parse($value)->format('d/m/Y');
        } catch (\Throwable) {
            return $value;
        }
    }
}
