<?php

namespace App\Filament\Concerns;

use App\Domain\Catalog\DataTransferObjects\NamingPreference;
use App\Domain\Catalog\Enums\DocumentNamingSource;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Os quatro controles de nomenclatura (código/nome/descrição + toggle de
 * exibição), extraídos do ShipmentHeaderActions original (Task 8) porque a
 * Purchase Order e a RFQ (Task 10) precisam do mesmo modal.
 *
 * As quatro labels, o título da seção e os dois helper texts (ver
 * lang/*\/forms.php, chaves naming_*) já foram redigidos de forma neutra —
 * "Contraparte"/"Counterparty" em vez de "Cliente"/"Client" — desde o commit
 * original (969d8bb7). Nenhum texto precisa saber se a contraparte do
 * documento é cliente ou fornecedor, então este método recebe só a
 * NamingPreference já resolvida pelo chamador (cada recurso deriva a dele:
 * Shipment via getDocumentClient()+company, PO via supplierCompany, SQ via
 * company) e não um rótulo de contraparte.
 */
trait HasDocumentNamingOptions
{
    protected function documentNamingSection(NamingPreference $defaults): Section
    {
        return Section::make(__('forms.sections.naming_preferences'))
            ->description(__('forms.helpers.naming_preferences_section'))
            ->columns(2)
            ->collapsible()
            ->schema([
                Select::make(NamingPreference::KEY_CODE)
                    ->label(__('forms.labels.naming_code_source'))
                    ->options(DocumentNamingSource::class)
                    ->default($defaults->code->value)
                    ->native(false)
                    ->helperText(__('forms.helpers.naming_code_source')),
                Select::make(NamingPreference::KEY_NAME)
                    ->label(__('forms.labels.naming_name_source'))
                    ->options(DocumentNamingSource::class)
                    ->default($defaults->name->value)
                    ->native(false)
                    ->helperText(__('forms.helpers.naming_name_source')),
                Toggle::make(NamingPreference::KEY_SHOW_DESCRIPTION)
                    ->label(__('forms.labels.naming_show_description'))
                    ->default($defaults->showDescription)
                    ->live(),
                Select::make(NamingPreference::KEY_DESCRIPTION)
                    ->label(__('forms.labels.naming_description_source'))
                    ->options(DocumentNamingSource::class)
                    ->default($defaults->description->value)
                    ->native(false)
                    ->visible(fn (Get $get) => (bool) $get(NamingPreference::KEY_SHOW_DESCRIPTION))
                    ->helperText(__('forms.helpers.naming_description_source')),
            ]);
    }
}
