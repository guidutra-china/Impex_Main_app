<?php

namespace App\Filament\Resources\Finance\Trips\Schemas;

use App\Domain\Infrastructure\Support\Money;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TripInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forms.sections.trip_details'))->columns(3)->columnSpanFull()->schema([
                TextEntry::make('title')
                    ->label(__('forms.labels.trip_title')),

                TextEntry::make('company_label')
                    ->label(__('forms.labels.company'))
                    ->state(fn ($record) => $record->company_label),

                TextEntry::make('traveler.name')
                    ->label(__('forms.labels.traveler'))
                    ->placeholder('—'),

                TextEntry::make('destination_city')
                    ->label(__('forms.labels.destination_city'))
                    ->placeholder('—'),

                TextEntry::make('destination_country')
                    ->label(__('forms.labels.destination_country'))
                    ->placeholder('—'),

                TextEntry::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge(),

                TextEntry::make('start_date')
                    ->label(__('forms.labels.start_date'))
                    ->date('d/m/Y'),

                TextEntry::make('end_date')
                    ->label(__('forms.labels.end_date'))
                    ->date('d/m/Y')
                    ->placeholder('—'),

                TextEntry::make('total')
                    ->label(__('forms.labels.total'))
                    ->state(fn ($record) => collect($record->totals_by_currency)
                        ->map(fn ($amount, $code) => Money::format($amount).' '.$code)
                        ->implode(' · ') ?: '—'),

                TextEntry::make('approvedByUser.name')
                    ->label(__('forms.labels.approved_by'))
                    ->placeholder('—'),

                TextEntry::make('approved_at')
                    ->label(__('forms.labels.approved_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('—'),

                TextEntry::make('rejected_reason')
                    ->label(__('forms.labels.rejection_reason'))
                    ->placeholder('—')
                    ->visible(fn ($record) => filled($record->rejected_reason))
                    ->columnSpanFull(),
            ]),

            Section::make(__('forms.sections.additional_info'))->columnSpanFull()->schema([
                TextEntry::make('notes')
                    ->label(__('forms.labels.notes'))
                    ->placeholder('—'),
            ]),
        ]);
    }
}
