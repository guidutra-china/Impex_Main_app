<?php

namespace App\Filament\Support;

use App\Domain\Catalog\Support\Ncm;
use Filament\Forms\Components\TextInput;

/**
 * Campo NCM do cliente (pivot company_product.external_ncm).
 *
 * NCM é a classificação fiscal do importador, então só existe do lado cliente —
 * não adicionar este campo nas telas de fornecedor. Aceita de 4 a 8 dígitos: 4
 * é a posição, 8 é o NCM completo — mesmo intervalo que
 * {@see \App\Domain\Catalog\Support\Ncm} valida do lado do documento, pela
 * mesma constante, para as duas camadas não voltarem a divergir.
 */
class ClientNcmInput
{
    public static function make(string $name = 'external_ncm'): TextInput
    {
        return TextInput::make($name)
            ->label(__('forms.labels.ncm'))
            ->helperText(__('forms.helpers.client_ncm'))
            ->maxLength(20)
            ->rule('regex:'.Ncm::DIGIT_COUNT_PATTERN)
            ->validationMessages([
                'regex' => __('validation.custom.ncm_digits'),
            ])
            // Guarda só dígitos: a máscara de exibição é cosmética e não pode
            // vazar para o banco, senão a busca por NCM deixa de casar.
            ->dehydrateStateUsing(fn (?string $state) => filled($state)
                ? preg_replace('/\D/', '', $state)
                : null)
            ->placeholder('84314900');
    }
}
