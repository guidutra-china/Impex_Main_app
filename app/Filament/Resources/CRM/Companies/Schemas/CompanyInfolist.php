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
                Section::make(__('forms.sections.company_information'))
                    ->schema([
                        TextEntry::make('name')
                            ->label(__('forms.labels.company_name'))
                            ->weight(FontWeight::Bold)
                            ->size(TextSize::Large),
                        TextEntry::make('legal_name')
                            ->label(__('forms.labels.legal_name'))
                            ->placeholder('—'),
                        TextEntry::make('tax_number')
                            ->label(__('forms.labels.tax_number'))
                            ->placeholder('—')
                            ->copyable(),
                        TextEntry::make('email')
                            ->label(__('forms.labels.email'))
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-envelope'),
                        TextEntry::make('phone')
                            ->label(__('forms.labels.phone'))
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-phone'),
                        TextEntry::make('website')
                            ->label(__('forms.labels.website'))
                            ->placeholder('—')
                            ->icon('heroicon-o-globe-alt')
                            ->url(fn ($state) => $state ? 'https://' . ltrim($state, 'https://') : null, shouldOpenInNewTab: true),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2]),

                Section::make(__('forms.sections.status_roles'))
                    ->schema([
                        TextEntry::make('status')
                            ->label(__('forms.labels.status'))
                            ->badge(),
                        TextEntry::make('roles_list')
                            ->label(__('forms.labels.roles'))
                            ->badge()
                            ->color('info')
                            ->separator(', ')
                            ->placeholder(__('forms.placeholders.no_roles_assigned')),
                    ])
                    ->columnSpan(['lg' => 1]),

                Section::make(__('forms.sections.primary_contact'))
                    ->schema([
                        TextEntry::make('primaryContact.name')
                            ->label(__('forms.labels.name'))
                            ->placeholder(__('forms.placeholders.no_primary_contact_set'))
                            ->weight(FontWeight::Bold)
                            ->icon('heroicon-o-user'),
                        TextEntry::make('primaryContact.position')
                            ->label(__('forms.labels.position'))
                            ->placeholder('—'),
                        TextEntry::make('primaryContact.email')
                            ->label(__('forms.labels.email'))
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-envelope'),
                        TextEntry::make('primaryContact.phone')
                            ->label(__('forms.labels.phone'))
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-phone'),
                        TextEntry::make('primaryContact.whatsapp')
                            ->label(__('forms.labels.whatsapp'))
                            ->placeholder('—')
                            ->copyable()
                            ->icon('heroicon-o-chat-bubble-left-ellipsis'),
                        TextEntry::make('primaryContact.wechat')
                            ->label(__('forms.labels.wechat'))
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpan(['lg' => 2])
                    ->visible(fn (Company $record) => $record->contacts()->where('is_primary', true)->exists()),

                Section::make(__('forms.sections.address'))
                    ->schema([
                        TextEntry::make('full_address')
                            ->label(__('forms.labels.full_address'))
                            ->placeholder(__('forms.placeholders.no_address_registered'))
                            ->icon('heroicon-o-map-pin')
                            ->columnSpanFull(),
                        TextEntry::make('address_country')
                            ->label(__('forms.labels.country'))
                            ->placeholder('—'),
                    ])
                    ->columns(2)
                    ->columnSpanFull()
                    ->collapsible(),

                Section::make(__('forms.sections.notes'))
                    ->schema([
                        TextEntry::make('notes')
                            ->label(__('forms.labels.internal_notes'))
                            ->placeholder(__('forms.placeholders.no_notes'))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->collapsible()
                    ->collapsed(),
            ])
            ->columns(3);
    }
}
