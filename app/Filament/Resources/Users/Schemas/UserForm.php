<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Domain\CRM\Models\Company;
use App\Domain\Users\Enums\UserType;
use Filament\Schemas\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.account_information'))
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->required()
                        ->maxLength(255),
                    TextInput::make('email')
                        ->email()
                        ->required()
                        ->unique(ignoreRecord: true)
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->tel()
                        ->maxLength(30),
                    TextInput::make('job_title')
                        ->maxLength(100),
                ]),

            Section::make(__('forms.sections.authentication'))
                ->columns(2)
                ->schema([
                    TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->rule(Password::defaults())
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->helperText(fn (string $operation) => $operation === 'edit' ? 'Leave blank to keep current password.' : null),
                    TextInput::make('password_confirmation')
                        ->password()
                        ->revealable()
                        ->same('password')
                        ->requiredWith('password')
                        ->dehydrated(false),
                ]),

            Section::make(__('forms.sections.access_role'))
                ->columns(2)
                ->schema([
                    Select::make('type')
                        ->label(__('forms.labels.user_type'))
                        ->options(UserType::class)
                        ->default(UserType::INTERNAL->value)
                        ->required()
                        ->live(),
                    Select::make('company_id')
                        ->label(__('forms.labels.company'))
                        ->relationship('company', 'name')
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get) => in_array($get('type'), [
                            UserType::CLIENT->value,
                            UserType::SUPPLIER->value,
                            'client',
                            'supplier',
                        ]))
                        ->required(fn (Get $get) => in_array($get('type'), [
                            UserType::CLIENT->value,
                            UserType::SUPPLIER->value,
                            'client',
                            'supplier',
                        ])),
                    Select::make('roles')
                        ->label(__('forms.labels.role'))
                        ->relationship('roles', 'name')
                        ->options(fn () => Role::where('guard_name', 'web')->pluck('name', 'id'))
                        ->preload()
                        ->required(),
                    Select::make('status')
                        ->options([
                            'active' => 'Active',
                            'inactive' => 'Inactive',
                        ])
                        ->default('active')
                        ->required(),
                    Select::make('locale')
                        ->label(__('forms.labels.language'))
                        ->options([
                            'en' => 'English',
                            'pt_BR' => 'Portuguese (Brazil)',
                            'zh_CN' => 'Chinese (Simplified)',
                        ])
                        ->default('en')
                        ->required(),
                ]),
        ]);
    }
}
