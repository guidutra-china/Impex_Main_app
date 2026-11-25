<?php

namespace App\Filament\Support;

use Closure;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
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
 *
 * Opcionalmente o select pode ser restrito por outro campo do form (ex.: só
 * as inquiries do cliente escolhido) e buscar em colunas extras. A restrição
 * vale para a lista e para a busca, nunca para o rótulo: um vínculo antigo
 * fora do filtro continua aparecendo com nome e o form continua salvando.
 */
final class RecentRecordSelect
{
    public const PRELOADED = 100;

    public const SEARCH_LIMIT = 50;

    /**
     * @param  class-string<Model>  $modelClass  precisa de `reference` e da relação `company`
     * @param  Closure(Model): string  $label
     * @param  (Closure(Builder, Get): mixed)|null  $scope  restringe lista e busca; devolver false = nenhuma opção
     * @param  list<string>  $searchColumns  colunas do próprio registro buscadas além de `reference`
     */
    public static function configure(
        Select $select,
        string $modelClass,
        Closure $label,
        ?Closure $scope = null,
        array $searchColumns = [],
    ): Select {
        $query = function (Get $get) use ($modelClass, $scope): ?Builder {
            $query = $modelClass::query()->with('company');

            if ($scope !== null && $scope($query, $get) === false) {
                return null;
            }

            return $query->orderByDesc('id');
        };

        $toOptions = fn (?Builder $query, int $limit): array => $query === null
            ? []
            : $query->limit($limit)->get()
                ->mapWithKeys(fn (Model $record) => [$record->getKey() => $label($record)])
                ->all();

        return $select
            ->options(fn (Get $get) => $toOptions($query($get), self::PRELOADED))
            ->getSearchResultsUsing(fn (string $search, Get $get) => $toOptions(
                $query($get)?->where(function (Builder $query) use ($search, $searchColumns): void {
                    $query->where('reference', 'like', "%{$search}%")
                        ->orWhereHas('company', fn (Builder $company) => $company->where('name', 'like', "%{$search}%"));

                    foreach ($searchColumns as $column) {
                        $query->orWhere($column, 'like', "%{$search}%");
                    }
                }),
                self::SEARCH_LIMIT,
            ))
            ->getOptionLabelUsing(function ($value) use ($modelClass, $label): ?string {
                $record = filled($value) ? $modelClass::query()->with('company')->find($value) : null;

                return $record ? $label($record) : null;
            })
            ->searchable();
    }
}
