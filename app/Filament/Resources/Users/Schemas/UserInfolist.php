<?php

namespace App\Filament\Resources\Users\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.account_information'))
                ->columns(3)
                ->schema([
                    TextEntry::make('name')
                        ->weight('bold'),
                    TextEntry::make('email')
                        ->icon('heroicon-o-envelope'),
                    TextEntry::make('phone')
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                    TextEntry::make('job_title')
                        ->placeholder('—'),
                    TextEntry::make('locale')
                        ->label(__('forms.labels.language'))
                        ->formatStateUsing(fn (string $state) => match ($state) {
                            'en' => 'English',
                            'pt_BR' => 'Portuguese (Brazil)',
                            'zh_CN' => 'Chinese (Simplified)',
                            default => $state,
                        }),
                    TextEntry::make('created_at')
                        ->label(__('forms.labels.member_since'))
                        ->dateTime('d/m/Y'),
                ]),

            Section::make(__('forms.sections.access_role'))
                ->columns(3)
                ->schema([
                    TextEntry::make('type')
                        ->label(__('forms.labels.user_type'))
                        ->badge(),
                    TextEntry::make('roles.name')
                        ->label(__('forms.labels.role'))
                        ->badge()
                        ->color('info'),
                    TextEntry::make('status')
                        ->badge()
                        ->color(fn (string $state) => match ($state) {
                            'active' => 'success',
                            'inactive' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('company.name')
                        ->label(__('forms.labels.company'))
                        ->placeholder('—')
                        ->visible(fn ($record) => $record->company_id !== null),
                ]),
        ]);
    }
}
