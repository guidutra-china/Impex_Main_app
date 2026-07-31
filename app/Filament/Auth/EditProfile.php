<?php

namespace App\Filament\Auth;

use Filament\Auth\Pages\EditProfile as BaseEditProfile;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;

class EditProfile extends BaseEditProfile
{
    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getNameFormComponent(),
                $this->getEmailFormComponent(),
                $this->getLocaleFormComponent(),
                $this->getDefaultDashboardFormComponent(),
                $this->getPasswordFormComponent(),
                $this->getPasswordConfirmationFormComponent(),
                $this->getCurrentPasswordFormComponent(),
            ]);
    }

    protected function getLocaleFormComponent(): Component
    {
        return Select::make('locale')
            ->label('Language / 语言 / Idioma')
            ->options([
                'en' => 'English',
                'zh_CN' => '简体中文',
                'pt_BR' => 'Português (Brasil)',
            ])
            ->default('en')
            ->required()
            ->native(false);
    }

    protected function getDefaultDashboardFormComponent(): Component
    {
        return Select::make('default_dashboard')
            ->label(__('forms.labels.default_dashboard'))
            ->options([
                'operational' => __('navigation.pages.operational_dashboard'),
                'financial' => __('navigation.pages.financial_dashboard'),
            ])
            ->default('operational')
            ->required()
            ->native(false)
            ->visible(fn (): bool => auth()->user()?->can('view-financial-dashboard') ?? false);
    }
}
