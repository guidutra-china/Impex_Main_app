<?php

namespace App\Filament\Resources\Inquiries\Schemas;

use App\Domain\Inquiries\Enums\InquiryStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class InquiryInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Tabs::make('Inquiry')
                    ->tabs([
                        Tabs\Tab::make('General')
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make('Notes')
                            ->icon('heroicon-o-chat-bubble-left-right')
                            ->schema(static::notesTab()),
                    ])
                    ->columnSpanFull()
                    ->persistTabInQueryString(),
            ]);
    }

    protected static function generalTab(): array
    {
        return [
            Section::make('Inquiry Identity')
                ->schema([
                    TextEntry::make('reference')
                        ->label('Reference')
                        ->weight('bold')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label('Status')
                        ->badge(),
                    TextEntry::make('source')
                        ->label('Source')
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label('Currency')
                        ->badge()
                        ->color('gray'),
                ])
                ->columns(4),

            Section::make('Client')
                ->schema([
                    TextEntry::make('company.name')
                        ->label('Company'),
                    TextEntry::make('contact.name')
                        ->label('Contact')
                        ->placeholder('—'),
                    TextEntry::make('contact.email')
                        ->label('Email')
                        ->icon('heroicon-o-envelope')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('contact.phone')
                        ->label('Phone')
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make('Dates')
                ->schema([
                    TextEntry::make('received_at')
                        ->label('Received Date')
                        ->date('d/m/Y'),
                    TextEntry::make('deadline')
                        ->label('Response Deadline')
                        ->date('d/m/Y')
                        ->placeholder('No deadline')
                        ->color(fn ($record) => $record->deadline && $record->deadline->isPast() ? 'danger' : null),
                    TextEntry::make('creator.name')
                        ->label('Created By')
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label('Created At')
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(4),

            Section::make('Linked Quotations')
                ->schema([
                    TextEntry::make('quotations_count')
                        ->label('Total Quotations')
                        ->state(fn ($record) => $record->quotations->count())
                        ->placeholder('0'),
                ])
                ->columns(1),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make('Notes')
                ->schema([
                    TextEntry::make('notes')
                        ->label('Client Notes / Requirements')
                        ->placeholder('No client notes.')
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label('Internal Notes')
                        ->placeholder('No internal notes.')
                        ->columnSpanFull(),
                ]),
        ];
    }
}
