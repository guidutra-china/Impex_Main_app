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
}
