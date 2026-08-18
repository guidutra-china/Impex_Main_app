<?php

namespace App\Filament\Support;

use Filament\Forms\Components\TextInput;

/**
 * Campo NCM do cliente (pivot company_product.external_ncm).
 *
 * NCM é a classificação fiscal do importador, então só existe do lado cliente —
 * não adicionar este campo nas telas de fornecedor. Aceita de 4 a 8 dígitos: 4
 * é a posição, 8 é o NCM completo.
 */
class ClientNcmInput
{
    public static function make(string $name = 'external_ncm'): TextInput
    {
        return TextInput::make($name)
            ->label(__('forms.labels.ncm'))
            ->helperText(__('forms.helpers.client_ncm'))
            ->maxLength(20)
            ->rule('regex:/^\d{4,8}$/')
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
