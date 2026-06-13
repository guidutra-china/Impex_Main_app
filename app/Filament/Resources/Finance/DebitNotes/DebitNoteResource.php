<?php

namespace App\Filament\Resources\Finance\DebitNotes;

use App\Domain\Financial\Models\DebitNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\CreateDebitNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\EditDebitNote;
use App\Filament\Resources\Finance\DebitNotes\Pages\ListDebitNotes;
use App\Filament\Resources\Finance\DebitNotes\Pages\ViewDebitNote;
use App\Filament\Resources\Finance\DebitNotes\Schemas\DebitNoteForm;
use App\Filament\Resources\Finance\DebitNotes\Schemas\DebitNoteInfolist;
use App\Filament\Resources\Finance\DebitNotes\Tables\DebitNotesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class DebitNoteResource extends Resource
{
    protected static ?string $model = DebitNote::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?int $navigationSort = 65;

    protected static ?string $slug = 'debit-notes';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-payments') ?? false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'company.name'];
    }

    public static function form(Schema $schema): Schema
    {
        return DebitNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DebitNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DebitNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDebitNotes::route('/'),
            'create' => CreateDebitNote::route('/create'),
            'view' => ViewDebitNote::route('/{record}'),
            'edit' => EditDebitNote::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.debit_notes');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.debit_note');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.debit_notes');
    }
}
