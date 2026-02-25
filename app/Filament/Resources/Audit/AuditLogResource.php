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

    protected static UnitEnum|string|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Audit Log';

    protected static ?string $modelLabel = 'Audit Entry';

    protected static ?string $pluralModelLabel = 'Audit Log';

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
                    ->label('Date/Time')
                    ->dateTime('M d, Y H:i:s')
                    ->sortable(),

                TextColumn::make('causer.name')
                    ->label('User')
                    ->default('System'),

                TextColumn::make('log_name')
                    ->label('Module')
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
                    ->label('Action')
                    ->searchable(),

                TextColumn::make('subject_type')
                    ->label('Entity')
                    ->formatStateUsing(function (?string $state) {
                        if (! $state) {
                            return '-';
                        }

                        return class_basename($state);
                    }),

                TextColumn::make('subject_id')
                    ->label('ID'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('log_name')
                    ->label('Module')
                    ->options(fn () => Activity::query()
                        ->distinct()
                        ->pluck('log_name', 'log_name')
                        ->mapWithKeys(fn ($value, $key) => [$key => ucfirst($key)])
                        ->toArray()
                    ),

                SelectFilter::make('description')
                    ->label('Action')
                    ->options([
                        'created' => 'Created',
                        'updated' => 'Updated',
                        'deleted' => 'Deleted',
                    ]),

                SelectFilter::make('causer_id')
                    ->label('User')
                    ->options(fn () => \App\Models\User::query()
                        ->whereIn('id', Activity::query()->distinct()->pluck('causer_id')->filter())
                        ->pluck('name', 'id')
                        ->toArray()
                    ),

                Filter::make('date_range')
                    ->form([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('until')
                            ->label('Until'),
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
                Section::make('Event Details')
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Date/Time')
                            ->dateTime('M d, Y H:i:s'),

                        TextEntry::make('causer.name')
                            ->label('User')
                            ->default('System'),

                        TextEntry::make('log_name')
                            ->label('Module')
                            ->badge()
                            ->formatStateUsing(fn (string $state) => ucfirst($state)),

                        TextEntry::make('description')
                            ->label('Action'),

                        TextEntry::make('subject_type')
                            ->label('Entity Type')
                            ->formatStateUsing(fn (?string $state) => $state ? class_basename($state) : '-'),

                        TextEntry::make('subject_id')
                            ->label('Entity ID'),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),

                Section::make('Old Values')
                    ->schema([
                        KeyValueEntry::make('properties.old')
                            ->label('')
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (Activity $record) => ! empty($record->properties->get('old'))),

                Section::make('New Values')
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
}
