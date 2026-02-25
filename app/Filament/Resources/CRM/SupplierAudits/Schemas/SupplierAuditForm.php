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
                Section::make('Audit Details')
                    ->schema([
                        TextInput::make('reference')
                            ->label('Reference')
                            ->default(fn () => AuditReferenceService::generate())
                            ->disabled()
                            ->dehydrated()
                            ->required()
                            ->unique(ignoreRecord: true),
                        Select::make('company_id')
                            ->label('Supplier')
                            ->relationship(
                                'company',
                                'name',
                                fn ($query) => $query->whereHas('companyRoles', fn ($q) => $q->where('role', CompanyRole::SUPPLIER))
                            )
                            ->searchable()
                            ->preload()
                            ->required(),
                        Select::make('audit_type')
                            ->label('Audit Type')
                            ->options(AuditType::class)
                            ->required()
                            ->default(AuditType::INITIAL->value),
                        DatePicker::make('scheduled_date')
                            ->label('Scheduled Date')
                            ->required()
                            ->default(now()),
                    ])
                    ->columns(2),

                Section::make('Assignment')
                    ->schema([
                        Select::make('conducted_by')
                            ->label('Auditor')
                            ->options(fn () => User::where('status', 'active')->pluck('name', 'id'))
                            ->searchable()
                            ->preload()
                            ->helperText('The person who will conduct the audit.'),
                        TextInput::make('location')
                            ->label('Location')
                            ->maxLength(255)
                            ->placeholder('Factory address or location name'),
                    ])
                    ->columns(2),

                Section::make('Notes')
                    ->schema([
                        Textarea::make('summary')
                            ->label('Summary / Objective')
                            ->rows(3)
                            ->maxLength(2000)
                            ->placeholder('Purpose and scope of this audit')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}
