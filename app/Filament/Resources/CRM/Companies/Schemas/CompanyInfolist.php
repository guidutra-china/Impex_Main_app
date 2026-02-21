<?php

namespace App\Filament\Resources\CRM\Companies\Schemas;

use App\Domain\CRM\Models\Company;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Enums\TextSize;

class CompanyInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Company Information')
                    ->schema([
                        TextEntry::make('name')
                            ->label('Company Name')
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('legal_name')
                            ->label('Legal Name')
                            ->placeholder('—'),
                        TextEntry::make('tax_number')
                            ->label('Tax Number')
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('email')
                            ->label('Email')
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-envelope'),
                        TextEntry::make('phone')
                            ->label('Phone')
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-phone'),
                        TextEntry::make('website')
                            ->label('Website')
                            ->placeholder('—')
                            ->icon('heroicon-o-globe-alt')
                            ->url(fn ($state) => $state ? 'https://' . ltrim($state, 'https://') : null, shouldOpenInNewTab: true),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2]),

                Section::make('Status & Roles')
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge(),
                        TextEntry::make('roles_list')
                            ->label('Roles')
                            ->badge()
                            ->color('info')
                            ->separator(', ')
                            ->placeholder('No roles assigned'),
                    ])
                    ->columnSpan(['lg' => 1]),

                Section::make('Primary Contact')
                    ->schema([
                        TextEntry::make('primaryContact.name')
                            ->label('Name')
                            ->placeholder('No primary contact set')
                            ->weight(FontWeight::Bold)
                            ->icon('heroicon-o-user'),
                        TextEntry::make('primaryContact.position')
                            ->label('Position')
                            ->placeholder('—'),
                        TextEntry::make('primaryContact.email')
                            ->label('Email')
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-envelope'),
                        TextEntry::make('primaryContact.phone')
                            ->label('Phone')
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-phone'),
                        TextEntry::make('primaryContact.whatsapp')
                            ->label('WhatsApp')
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-chat-bubble-left-ellipsis'),
                        TextEntry::make('primaryContact.wechat')
                            ->label('WeChat')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2])
                    ->visible(fn (Company $record) => $record->contacts()->where('is_primary', true)->exists()),

                Section::make('Address')
                    ->schema([
                        TextEntry::make('full_address')
                            ->label('Full Address')
                            ->placeholder('No address registered')
                            ->icon('heroicon-o-map-pin')
                            ->columnSpanFull(),
                        TextEntry::make('address_country')
                            ->label('Country')
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make('Notes')
                    ->schema([
                        TextEntry::make('notes')
                            ->label('Internal Notes')
                            ->placeholder('No notes')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(),
            ])
            ->columns(3);
    }
}
