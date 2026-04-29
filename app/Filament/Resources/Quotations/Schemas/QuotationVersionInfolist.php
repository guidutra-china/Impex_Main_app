<?php

namespace App\Filament\Resources\Quotations\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;

/**
 * Renders a QuotationVersion snapshot as a structured infolist.
 *
 * Reads exclusively from $record->snapshot (cast to array) — never follows
 * live FKs. Quotation::saveVersion() denormalizes the names we care about
 * (company, contact, product, supplier) so the historical view stays
 * accurate even after the source rows change.
 *
 * Implementation note: TextEntry::make() keys are intentionally flat,
 * unique sentinel names (no dot notation), to prevent Filament from
 * attempting to resolve them as relationships against the QuotationVersion
 * model. All values are produced via ->state() closures.
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

                    return $q['company']['name'] ?? ('#'.($q['company_id'] ?? '?'));
                })
                ->icon('heroicon-o-building-office-2'),
            TextEntry::make('v_contact')
                ->label(__('forms.labels.contact'))
                ->state(function ($record) {
                    $q = $record->snapshot['quotation'] ?? [];

                    return $q['contact']['name'] ?? '—';
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
                ->state(fn ($record) => static::money(static::q($record, 'subtotal', 0))),
            TextEntry::make('v_commission_amount')
                ->label(__('forms.labels.commission_separate'))
                ->state(fn ($record) => static::money(static::q($record, 'commission_amount', 0))),
            TextEntry::make('v_total')
                ->label(__('forms.labels.total'))
                ->state(fn ($record) => static::money(static::q($record, 'total', 0)))
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

    protected static function itemsRepeater(): RepeatableEntry
    {
        return RepeatableEntry::make('items_list')
            ->label('')
            ->state(fn ($record) => $record->snapshot['items'] ?? [])
            ->schema([
                TextEntry::make('product_name')
                    ->label(__('forms.labels.product'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;

                        return $row['product']['name']
                            ?? $row['description']
                            ?? '#'.($row['product_id'] ?? '?');
                    })
                    ->weight(FontWeight::Bold),
                TextEntry::make('product_sku')
                    ->label(__('forms.labels.sku'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;

                        return $row['product']['sku'] ?? '—';
                    })
                    ->badge()
                    ->color('gray'),
                TextEntry::make('qty')
                    ->label(__('forms.labels.qty'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;

                        return (int) ($row['quantity'] ?? 0);
                    })
                    ->alignCenter(),
                TextEntry::make('item_unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;
                        $cost = static::money($row['unit_cost'] ?? 0, 4);
                        $extras = [];
                        $cur = $row['cost_currency_code'] ?? null;
                        $rate = $row['cost_exchange_rate'] ?? null;
                        $captured = $row['cost_exchange_rate_captured_at'] ?? null;
                        if ($cur) {
                            $extras[] = $cur;
                        }
                        if ($rate !== null && (float) $rate !== 1.0 && (float) $rate > 0) {
                            $extras[] = '@ '.number_format((float) $rate, 6);
                        }
                        if ($captured) {
                            $extras[] = static::formatDate($captured);
                        }

                        return $cost.(empty($extras) ? '' : ' ('.implode(' · ', $extras).')');
                    }),
                TextEntry::make('item_unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;

                        return static::money($row['unit_price'] ?? 0, 4);
                    }),
                TextEntry::make('item_line_total')
                    ->label(__('forms.labels.line_total'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;
                        $price = (int) ($row['unit_price'] ?? 0);
                        $qty = (int) ($row['quantity'] ?? 0);

                        return static::money($price * $qty);
                    })
                    ->weight(FontWeight::Bold),
                TextEntry::make('item_supplier')
                    ->label(__('forms.labels.supplier'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;

                        return $row['selected_supplier']['name'] ?? '—';
                    })
                    ->placeholder('—'),
                TextEntry::make('item_alternatives')
                    ->label(__('forms.sections.version_alternatives'))
                    ->state(function ($record) {
                        $row = is_array($record) ? $record : (array) $record;
                        $alts = $row['suppliers'] ?? [];
                        if (empty($alts)) {
                            return '—';
                        }

                        return collect($alts)
                            ->map(function ($s) {
                                $name = $s['company']['name'] ?? '#'.($s['company_id'] ?? '?');
                                $cur = $s['currency_code'] ?? '';
                                $cost = static::money($s['unit_cost'] ?? 0, 4);

                                return trim($name.': '.$cur.' '.$cost);
                            })
                            ->implode("\n");
                    })
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

    /**
     * Read a key from $record->snapshot['quotation'] safely.
     */
    protected static function q($record, string $key, mixed $default = null): mixed
    {
        $q = $record->snapshot['quotation'] ?? [];

        return $q[$key] ?? $default;
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
