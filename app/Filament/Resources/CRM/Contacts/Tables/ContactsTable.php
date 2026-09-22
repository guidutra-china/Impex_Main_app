<?php

namespace App\Filament\Resources\CRM\Contacts\Tables;

use App\Domain\CRM\Enums\ContactFunction;
use App\Domain\CRM\Models\Contact;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ContactsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('company'))
            ->defaultSort('name')
            ->columns([
                IconColumn::make('is_primary')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->tooltip(__('forms.labels.primary_contact'))
                    ->alignCenter()
                    ->width('40px'),
                TextColumn::make('name')
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Contact $record) => $record->position),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.company'))
                    ->searchable()
                    ->sortable()
                    ->url(fn (Contact $record) => $record->company_id
                        ? CompanyResource::getUrl('view', ['record' => $record->company_id])
                        : null)
                    ->color('primary'),
                TextColumn::make('function')
                    ->label(__('forms.labels.department'))
                    ->badge()
                    ->toggleable(),
                TextColumn::make('email')
                    ->label(__('forms.labels.email'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),
                TextColumn::make('phone')
                    ->label(__('forms.labels.phone'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-phone'),
                TextColumn::make('whatsapp')
                    ->label(__('forms.labels.whatsapp'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->toggleable(),
                TextColumn::make('wechat')
                    ->label(__('forms.labels.wechat'))
                    ->searchable()
                    ->copyable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('function')
                    ->label(__('forms.labels.department'))
                    ->options(ContactFunction::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.company'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                SelectFilter::make('is_primary')
                    ->label(__('forms.labels.primary_contact'))
                    ->options(['1' => __('forms.labels.yes'), '0' => __('forms.labels.no')]),
            ])
            ->recordActions([
                Action::make('open_company')
                    ->label(__('forms.labels.open_company'))
                    ->icon('heroicon-o-building-office-2')
                    ->url(fn (Contact $record) => CompanyResource::getUrl('view', ['record' => $record->company_id])),
            ])
            ->persistSearchInSession()
            ->emptyStateHeading(__('forms.labels.no_contacts'))
            ->emptyStateIcon('heroicon-o-users');
    }
}
