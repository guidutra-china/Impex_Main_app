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
 * live FKs, since the underlying entities (clients, products, suppliers)
 * may have been renamed or deleted since the snapshot was taken. The
 * Quotation::saveVersion() helper denormalizes the names we care about
 * (company.name, product.name, supplier.name) into the snapshot, so the
 * historical view stays accurate even after the source row changes.
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
            TextEntry::make('snapshot.quotation.reference')
                ->label(__('forms.labels.reference'))
                ->state(fn ($record) => $record->snapshot['quotation']['reference'] ?? '—')
                ->copyable(),
            TextEntry::make('snapshot.quotation.status')
                ->label(__('forms.labels.status'))
                ->state(fn ($record) => $record->snapshot['quotation']['status'] ?? '—')
                ->badge(),
            TextEntry::make('snapshot.quotation.company.name')
                ->label(__('forms.labels.client'))
                ->state(fn ($record) => $record->snapshot['quotation']['company']['name']
                    ?? '#'.($record->snapshot['quotation']['company_id'] ?? '?'))
                ->icon('heroicon-o-building-office-2'),
            TextEntry::make('snapshot.quotation.contact.name')
                ->label(__('forms.labels.contact'))
                ->state(fn ($record) => $record->snapshot['quotation']['contact']['name'] ?? '—')
                ->icon('heroicon-o-user'),
            TextEntry::make('snapshot.quotation.valid_until')
                ->label(__('forms.labels.valid_until'))
                ->state(fn ($record) => static::formatDate($record->snapshot['quotation']['valid_until'] ?? null)),
            TextEntry::make('snapshot.quotation.currency_code')
                ->label(__('forms.labels.currency'))
                ->state(fn ($record) => $record->snapshot['quotation']['currency_code'] ?? '—')
                ->badge()
                ->color('gray'),
            TextEntry::make('snapshot.quotation.commission_type')
                ->label(__('forms.labels.commission_model'))
                ->state(fn ($record) => $record->snapshot['quotation']['commission_type'] ?? '—')
                ->badge(),
            TextEntry::make('snapshot.quotation.commission_rate')
                ->label(__('forms.labels.commission_rate'))
                ->state(fn ($record) => ($record->snapshot['quotation']['commission_rate'] ?? null) !== null
                    ? number_format((float) $record->snapshot['quotation']['commission_rate'], 2).'%'
                    : '—'),
            TextEntry::make('snapshot.quotation.subtotal')
                ->label(__('forms.labels.subtotal'))
                ->state(fn ($record) => static::money($record->snapshot['quotation']['subtotal'] ?? 0)),
            TextEntry::make('snapshot.quotation.commission_amount')
                ->label(__('forms.labels.commission_separate'))
                ->state(fn ($record) => static::money($record->snapshot['quotation']['commission_amount'] ?? 0)),
            TextEntry::make('snapshot.quotation.total')
                ->label(__('forms.labels.total'))
                ->state(fn ($record) => static::money($record->snapshot['quotation']['total'] ?? 0))
                ->weight(FontWeight::Bold)
                ->color('success'),
            TextEntry::make('snapshot.quotation.notes')
                ->label(__('forms.labels.client_notes'))
                ->state(fn ($record) => $record->snapshot['quotation']['notes'] ?? '—')
                ->columnSpanFull()
                ->markdown(),
            TextEntry::make('snapshot.quotation.internal_notes')
                ->label(__('forms.labels.internal_notes'))
                ->state(fn ($record) => $record->snapshot['quotation']['internal_notes'] ?? '—')
                ->columnSpanFull(),
        ];
    }

    protected static function itemsRepeater(): RepeatableEntry
    {
        return RepeatableEntry::make('snapshot.items')
            ->label('')
            ->state(fn ($record) => $record->snapshot['items'] ?? [])
            ->schema([
                TextEntry::make('product.name')
                    ->label(__('forms.labels.product'))
                    ->state(fn ($state, $record) => $record['product']['name']
                        ?? $record['product_name']
                        ?? $record['description']
                        ?? '#'.($record['product_id'] ?? '?'))
                    ->weight(FontWeight::Bold),
                TextEntry::make('product.sku')
                    ->label(__('forms.labels.sku'))
                    ->state(fn ($state, $record) => $record['product']['sku'] ?? '—')
                    ->badge()
                    ->color('gray'),
                TextEntry::make('quantity')
                    ->label(__('forms.labels.qty'))
                    ->state(fn ($state, $record) => $record['quantity'] ?? 0)
                    ->alignCenter(),
                TextEntry::make('unit_cost')
                    ->label(__('forms.labels.unit_cost'))
                    ->state(function ($state, $record) {
                        $cost = static::money($record['unit_cost'] ?? 0, 4);
                        $cur = $record['cost_currency_code'] ?? null;
                        $rate = $record['cost_exchange_rate'] ?? null;
                        $captured = $record['cost_exchange_rate_captured_at'] ?? null;
                        $extras = [];
                        if ($cur) {
                            $extras[] = $cur;
                        }
                        if ($rate && (float) $rate !== 1.0) {
                            $extras[] = '@ '.number_format((float) $rate, 6);
                        }
                        if ($captured) {
                            $extras[] = static::formatDate($captured);
                        }

                        return $cost.(empty($extras) ? '' : ' ('.implode(' · ', $extras).')');
                    }),
                TextEntry::make('unit_price')
                    ->label(__('forms.labels.unit_price'))
                    ->state(fn ($state, $record) => static::money($record['unit_price'] ?? 0, 4)),
                TextEntry::make('line_total')
                    ->label(__('forms.labels.line_total'))
                    ->state(fn ($state, $record) => static::money(
                        (int) ($record['unit_price'] ?? 0) * (int) ($record['quantity'] ?? 0)
                    ))
                    ->weight(FontWeight::Bold),
                TextEntry::make('selected_supplier.name')
                    ->label(__('forms.labels.supplier'))
                    ->state(fn ($state, $record) => $record['selected_supplier']['name'] ?? '—')
                    ->placeholder('—'),
                TextEntry::make('suppliers_summary')
                    ->label(__('forms.sections.version_alternatives'))
                    ->state(function ($state, $record) {
                        $alts = $record['suppliers'] ?? [];
                        if (empty($alts)) {
                            return '—';
                        }

                        return collect($alts)
                            ->map(fn ($s) => ($s['company']['name'] ?? '#'.($s['company_id'] ?? '?'))
                                .': '.($s['currency_code'] ?? '').' '.static::money($s['unit_cost'] ?? 0, 4))
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

    protected static function money(mixed $minor, int $decimals = 2): string
    {
        if ($minor === null) {
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
