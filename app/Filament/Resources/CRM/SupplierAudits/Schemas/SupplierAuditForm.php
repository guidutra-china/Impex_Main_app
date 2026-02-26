<?php

namespace App\Filament\Resources\CRM\SupplierAudits\Schemas;

use App\Domain\CRM\Enums\CompanyRole;
use App\Domain\CRM\Models\Company;
use App\Domain\SupplierAudits\Enums\AuditType;
use App\Domain\SupplierAudits\Services\AuditReferenceService;
use App\Models\User;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupplierAuditForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('forms.sections.audit_details'))
                    ->schema([
                        TextInput::make('reference')
                            ->label(__('forms.labels.reference'))
                            ->default(fn () => AuditReferenceService::generate())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),
                        Select::make('company_id')
                            ->label(__('forms.labels.supplier'))
                            ->relationship(
                                'company',
                                'name',
                                fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('audit_type')
                            ->label(__('forms.labels.audit_type'))
                            ->options(AuditType::class)
                            ->required()
                            ->default(AuditType::INITIAL->value),
                        DatePicker::make('scheduled_date')
                            ->label(__('forms.labels.scheduled_date'))
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make(__('forms.sections.assignment'))
                    ->schema([
                        Select::make('conducted_by')
                            ->label(__('forms.labels.auditor'))
                            ->options(fn () => User::where('status', 'active')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->helperText(__('forms.helpers.the_person_who_will_conduct_the_audit')),
                        TextInput::make('location')
                            ->label(__('forms.labels.location'))
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.factory_address_or_location_name')),
                    ])
                    ->columns(2),

                Section::make(__('forms.sections.notes'))
                    ->schema([
                        Textarea::make('summary')
                            ->label(__('forms.labels.summary_objective'))
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder(__('forms.placeholders.purpose_and_scope_of_this_audit'))
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
