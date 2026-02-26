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
                        Tabs\Tab::make(__('forms.tabs.general'))
                            ->icon('heroicon-o-information-circle')
                            ->schema(static::generalTab()),
                        Tabs\Tab::make(__('forms.tabs.notes'))
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
            Section::make(__('forms.sections.inquiry_identity'))
                ->schema([
                    TextEntry::make('reference')
                        ->label(__('forms.labels.reference'))
                        ->weight('bold')
                        ->copyable(),
                    TextEntry::make('status')
                        ->label(__('forms.labels.status'))
                        ->badge(),
                    TextEntry::make('source')
                        ->label(__('forms.labels.source'))
                        ->badge(),
                    TextEntry::make('currency_code')
                        ->label(__('forms.labels.currency'))
                        ->badge()
                        ->color('gray'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.client'))
                ->schema([
                    TextEntry::make('company.name')
                        ->label(__('forms.labels.company')),
                    TextEntry::make('contact.name')
                        ->label(__('forms.labels.contact'))
                        ->placeholder('—'),
                    TextEntry::make('contact.email')
                        ->label(__('forms.labels.email'))
                        ->icon('heroicon-o-envelope')
                        ->placeholder('—')
                        ->copyable(),
                    TextEntry::make('contact.phone')
                        ->label(__('forms.labels.phone'))
                        ->icon('heroicon-o-phone')
                        ->placeholder('—'),
                ])
                ->columns(2),

            Section::make(__('forms.sections.dates'))
                ->schema([
                    TextEntry::make('received_at')
                        ->label(__('forms.labels.received_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('deadline')
                        ->label(__('forms.labels.response_deadline'))
                        ->date('d/m/Y')
                        ->placeholder(__('forms.placeholders.no_deadline'))
                        ->color(fn ($record) => $record->deadline && $record->deadline->isPast() ? 'danger' : null),
                    TextEntry::make('creator.name')
                        ->label(__('forms.labels.created_by'))
                        ->placeholder('—'),
                    TextEntry::make('created_at')
                        ->label(__('forms.labels.created_at'))
                        ->dateTime('d/m/Y H:i'),
                ])
                ->columns(4),

            Section::make(__('forms.sections.linked_quotations'))
                ->schema([
                    TextEntry::make('quotations_count')
                        ->label(__('forms.labels.total_quotations'))
                        ->state(fn ($record) => $record->quotations->count())
                        ->placeholder('0'),
                ])
                ->columns(1),
        ];
    }

    protected static function notesTab(): array
    {
        return [
            Section::make(__('forms.sections.notes'))
                ->schema([
                    TextEntry::make('notes')
                        ->label(__('forms.labels.client_notes_requirements'))
                        ->placeholder(__('forms.placeholders.no_client_notes'))
                        ->columnSpanFull()
                        ->markdown(),
                    TextEntry::make('internal_notes')
                        ->label(__('forms.labels.internal_notes'))
                        ->placeholder(__('forms.placeholders.no_internal_notes'))
                        ->columnSpanFull(),
                ]),
        ];
    }
}
