<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Select de documento (Inquiry, PI…) que pré-carrega só os mais recentes mas
 * aceita QUALQUER registro.
 *
 * O padrão antigo era `options(latest 100)` + `searchable()`: a busca era só
 * no navegador, em cima dessas 100, e a validação do Filament exige que o
 * valor tenha rótulo. Registro fora das 100 aparecia como o id cru e o form
 * não salvava — PI-2026-00011 ↔ INQ-2026-00020, e outras 14 PIs, quando as
 * inquiries passaram de 100.
 *
 * - getOptionLabelUsing: resolve o rótulo por id (exibição E validação);
 * - getSearchResultsUsing: busca no servidor por referência ou empresa.
 */
final class RecentRecordSelect
{
    public const PRELOADED = 100;

    public const SEARCH_LIMIT = 50;

    /**
     * @param  class-string<Model>  $modelClass  precisa de `reference` e da relação `company`
     * @param  Closure(Model): string  $label
     */
    public static function configure(Select $select, string $modelClass, Closure $label): Select
    {
        return $select
            ->options(fn () => $modelClass::query()
                ->with('company')
                ->orderByDesc('id')
                ->limit(self::PRELOADED)
                ->get()
                ->mapWithKeys(fn (Model $record) => [$record->getKey() => $label($record)])
                ->all())
            ->getSearchResultsUsing(fn (string $search) => $modelClass::query()
                ->with('company')
                ->where(fn (Builder $query) => $query
                    ->where('reference', 'like', "%{$search}%")
                    ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', "%{$search}%")))
                ->orderByDesc('id')
                ->limit(self::SEARCH_LIMIT)
                ->get()
                ->mapWithKeys(fn (Model $record) => [$record->getKey() => $label($record)])
                ->all())
            ->getOptionLabelUsing(function ($value) use ($modelClass, $label): ?string {
                $record = filled($value) ? $modelClass::query()->with('company')->find($value) : null;

                return $record ? $label($record) : null;
            })
            ->searchable();
    }
}
