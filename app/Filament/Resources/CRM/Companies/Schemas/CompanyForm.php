<?php

namespace App\Filament\Resources\CRM\Companies\Schemas;

use App\Domain\Catalog\Enums\DocumentNamingSource;
use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Enums\CompanyStatus;
use App\Domain\CRM\Models\Company;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class CompanyForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.company_information'))
                    ->schema([
                        Select::make('parent_company_id')
                            ->label(__('forms.labels.parent_company'))
                            ->relationship(
                                'parentCompany',
                                'name',
                                fn (Builder $query) => $query->whereNull('parent_company_id')
                            )
                            ->searchable()
                            ->preload()
                            ->placeholder(__('forms.placeholders.none_this_is_a_matrix'))
                            ->helperText(__('forms.helpers.select_if_this_company_is_a_branch'))
                            ->columnSpanFull()
                            ->rules([
                                fn () => function (string $attribute, $value, \Closure $fail) {
                                    if ($value && Company::where('id', $value)->whereNotNull('parent_company_id')->exists()) {
                                        $fail(__('validation.custom.parent_company_must_be_matrix'));
                                    }
                                },
                            ]),
                        TextInput::make('name')
                            ->label(__('forms.labels.company_name'))
                            ->required()
                            ->maxLength(255)
                            ->unique(table: Company::class, ignoreRecord: true),
                        TextInput::make('legal_name')
                            ->label(__('forms.labels.legal_name'))
                            ->maxLength(255)
                            ->helperText(__('forms.helpers.official_registered_name_for_legal_documents')),
                        TextInput::make('tax_number')
                            ->label(__('forms.labels.tax_number'))
                            ->maxLength(50)
                            ->unique(table: Company::class, ignoreRecord: true)
                            ->helperText(__('forms.helpers.cnpj_vat_ein_etc')),
                        TextInput::make('email')
                            ->label(__('forms.labels.email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('forms.labels.phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('website')
                            ->label(__('forms.labels.website'))
                            ->url()
                            ->maxLength(255)
                            ->prefix('https://'),
                        Select::make('preferred_language')
                            ->label(__('statements.preferred_language'))
                            ->options([
                                'en' => 'English',
                                'pt_BR' => 'Português (Brasil)',
                                'zh_CN' => '中文 (简体)',
                            ])
                            ->placeholder(__('statements.use_system_default'))
                            ->nullable(),
                        // As quatro colunas abaixo são anuláveis por natureza (ver a
                        // migração 2026_08_25_024241): NULL significa "não configurado
                        // — herda da matriz e, na ausência dela, do padrão histórico do
                        // sistema" (NamingPreference::fromCompany()). Um Select em
                        // branco (nullable + placeholder "Herdar da matriz") é a
                        // representação natural para os três campos de origem — mesmo
                        // padrão já usado no modal de geração de documentos.
                        Select::make('document_code_source')
                            ->label(__('forms.labels.naming_code_source'))
                            ->options(DocumentNamingSource::class)
                            ->native(false)
                            ->nullable()
                            ->placeholder(__('forms.placeholders.inherit_from_headquarters'))
                            ->helperText(__('forms.helpers.naming_code_source').' '.__('forms.helpers.document_naming_inherits')),
                        Select::make('document_name_source')
                            ->label(__('forms.labels.naming_name_source'))
                            ->options(DocumentNamingSource::class)
                            ->native(false)
                            ->nullable()
                            ->placeholder(__('forms.placeholders.inherit_from_headquarters'))
                            ->helperText(__('forms.helpers.naming_name_source').' '.__('forms.helpers.document_naming_inherits')),
                        Select::make('document_description_source')
                            ->label(__('forms.labels.naming_description_source'))
                            ->options(DocumentNamingSource::class)
                            ->native(false)
                            ->nullable()
                            ->placeholder(__('forms.placeholders.inherit_from_headquarters'))
                            ->helperText(__('forms.helpers.naming_description_source').' '.__('forms.helpers.document_naming_inherits')),
                        // document_show_description é um booleano anulável — o terceiro
                        // estado (herdar) é o comportamento correto, não um acidente do
                        // schema. Um Toggle não serve: o BooleanStateCast padrão do
                        // Filament (Toggle::getDefaultStateCasts()) é fixado com
                        // isNullable: false e transformaria "herdar" em "ocultar" a
                        // cada salvamento, quebrando a herança de toda filial cujo
                        // cadastro seja reaberto e salvo. Select::boolean() é o helper
                        // pronto do próprio Filament para exatamente este caso: registra
                        // um BooleanStateCast com isNullable: true (padrão do método),
                        // então em branco vai e volta como null — sem hooks manuais.
                        Select::make('document_show_description')
                            ->label(__('forms.labels.document_show_description'))
                            ->boolean(
                                trueLabel: __('forms.labels.document_show_description_show'),
                                falseLabel: __('forms.labels.document_show_description_hide'),
                                placeholder: __('forms.placeholders.inherit_from_headquarters'),
                            )
                            ->native(false)
                            ->helperText(__('forms.helpers.document_show_description_inherits')),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => fn (?Company $record) => $record === null ? 3 : 2]),

                Section::make(__('forms.sections.status_roles'))
                    ->schema([
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE->value),
                        CheckboxList::make('roles')
                            ->label(__('forms.labels.roles'))
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText(__('forms.helpers.select_all_roles_this_company_plays_in_your_business'))
                            ->columns(1),
                    ])
                    ->columnSpan(['lg' => 1])
                    ->hidden(fn (?Company $record) => $record === null),

                Section::make(__('forms.sections.status_roles'))
                    ->schema([
                        Select::make('status')
                            ->label(__('forms.labels.status'))
                            ->options(CompanyStatus::class)
                            ->required()
                            ->default(CompanyStatus::ACTIVE->value),
                        CheckboxList::make('roles')
                            ->label(__('forms.labels.roles'))
                            ->options(CompanyRole::class)
                            ->required()
                            ->helperText(__('forms.helpers.select_all_roles_this_company_plays_in_your_business'))
                            ->columns(2),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->visible(fn (?Company $record) => $record === null),

                Section::make(__('forms.sections.address'))
                    ->schema([
                        TextInput::make('address_street')
                            ->label(__('forms.labels.street'))
                            ->maxLength(255),
                        TextInput::make('address_number')
                            ->label(__('forms.labels.number'))
                            ->maxLength(20),
                        TextInput::make('address_complement')
                            ->label(__('forms.labels.complement'))
                            ->maxLength(255),
                        TextInput::make('address_city')
                            ->label(__('forms.labels.city'))
                            ->maxLength(255),
                        TextInput::make('address_state')
                            ->label(__('forms.labels.state_province'))
                            ->maxLength(255),
                        TextInput::make('address_zip')
                            ->label(__('forms.labels.zip_postal_code'))
                            ->maxLength(20),
                        TextInput::make('address_country')
                            ->label(__('forms.labels.country_code'))
                            ->maxLength(2)
                            ->placeholder(__('forms.placeholders.us'))
                            ->helperText(__('forms.helpers.iso_31661_alpha2_code_eg_us_cn_br_de')),
                    ])
                    ->columns(3)
                    ->columnSpan(['lg' => 3])
                    ->collapsible(),

                Section::make(__('forms.sections.contracted_importer'))
                    ->description(__('forms.descriptions.contracted_importer_details_for_conta_e_ordem'))
                    ->schema([
                        Textarea::make('contracted_importer_details')
                            ->label(__('forms.labels.contracted_importer_details'))
                            ->rows(6)
                            ->placeholder("Company Name\nCNPJ: 00.000.000/0001-00\nAddress: Rua Example, 123\nCity/State - CEP\nPhone: +55 11 0000-0000")
                            ->helperText(__('forms.helpers.enter_all_contracted_importer_details_as_they_should_appear'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->collapsible()
                    ->collapsed(),

                Section::make(__('forms.sections.notes'))
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('forms.labels.internal_notes'))
                            ->rows(3)
                            ->maxLength(5000),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->collapsible()
                    ->collapsed(),

                Section::make('Fotos da empresa')
                    ->schema([
                        FileUpload::make('company_photos')
                            ->label('Fotos da empresa')
                            ->helperText(__('forms.helpers.first_image_is_primary'))
                            ->image()
                            ->multiple()
                            ->reorderable()
                            ->appendFiles()
                            ->panelLayout('grid')
                            ->disk('public')
                            ->directory('company-photos')
                            ->maxFiles(8)
                            ->maxSize(8192)
                            ->imageResizeTargetWidth(1200)
                            ->imageResizeTargetHeight(1200)
                            ->imageResizeMode('contain')
                            ->columnSpanFull()
                            // Persisted manually into the company_photos relation by the
                            // page (see ManagesCompanyGallery). Not a real company column.
                            ->dehydrated(false),
                    ])
                    ->columnSpan(['lg' => 3])
                    ->collapsible(),
            ])
            ->columns(3);
    }
}
