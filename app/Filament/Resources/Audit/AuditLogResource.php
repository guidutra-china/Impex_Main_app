<?php

namespace App\Filament\Resources\Audit;

use BackedEnum;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Filament\Forms\Components\DatePicker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;
use UnitEnum;

class AuditLogResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 99;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-audit-log') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('forms.labels.datetime'))
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label(__('forms.labels.user'))
                    ->default('System'),

                TextColumn::make('log_name')
                    ->label(__('forms.labels.module'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => ucfirst($state))
                    ->color(fn (string $state) => match ($state) {
                        'payment' => 'danger',
                        'shipment' => 'info',
                        'purchase_order' => 'warning',
                        'proforma_invoice' => 'success',
                        default => 'gray',
                    }),

                TextColumn::make('description')
                    ->label(__('forms.labels.action'))
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label(__('forms.labels.entity'))
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) {
                            return '-';
                        }

                        return class_basename($state);
                    }),

                TextColumn::make('subject_id')
                    ->label(__('forms.labels.id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label(__('forms.labels.module'))
                    ->options(fn () => Activity::query()
                        ->distinct()
                        ->pluck('log_name', 'log_name')
                        ->mapWithKeys(fn ($value, $key) => [$key => ucfirst($key)])
                        ->toArray()
                    ),

                SelectFilter::make('description')
                    ->label(__('forms.labels.action'))
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),

                SelectFilter::make('causer_id')
                    ->label(__('forms.labels.user'))
                    ->options(fn () => \App\Models\User::query()
                        ->whereIn('id', Activity::query()->distinct()->pluck('causer_id')->filter())
                        ->pluck('name', 'id')
                        ->toArray()
                    ),

                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->label(__('forms.labels.from')),
                        DatePicker::make('until')
                            ->label(__('forms.labels.until')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['from'],
                                fn (Builder $query, $date) => $query->whereDate('created_at', '>=', $date),
                            )
                            ->when(
                                $data['until'],
                                fn (Builder $query, $date) => $query->whereDate('created_at', '<=', $date),
                            );
                    }),
            ])
            ->actions([
                ViewAction::make(),
            ])
            ->bulkActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->schema([
                Section::make(__('forms.sections.event_details'))
                    ->schema([
                        TextEntry::make('created_at')
                            ->label(__('forms.labels.datetime'))
                            ->dateTime('M d, Y H:i:s'),

                        TextEntry::make('causer.name')
                            ->label(__('forms.labels.user'))
                            ->default('System'),

                        TextEntry::make('log_name')
                            ->label(__('forms.labels.module'))
                            ->badge()
                            ->formatStateUsing(fn (string $state) => ucfirst($state)),

                        TextEntry::make('description')
                            ->label(__('forms.labels.action')),

                        TextEntry::make('subject_type')
                            ->label(__('forms.labels.entity_type'))
                            ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-'),

                        TextEntry::make('subject_id')
                            ->label(__('forms.labels.entity_id')),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make(__('forms.sections.old_values'))
                    ->schema([
                        KeyValueEntry::make('properties.old')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (Activity $record) => ! empty($record->properties->get('old'))),

                Section::make(__('forms.sections.new_values'))
                    ->schema([
                        KeyValueEntry::make('properties.attributes')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (Activity $record) => ! empty($record->properties->get('attributes'))),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => \App\Filament\Resources\Audit\Pages\ListAuditLogs::route('/'),
            'view' => \App\Filament\Resources\Audit\Pages\ViewAuditLog::route('/{record}'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.settings');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.audit_log');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.audit_entry');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.audit_log');
    }
}
