<?php

namespace App\Filament\Resources\Finance\CreditNotes;

use App\Domain\Financial\Models\CreditNote;
use App\Filament\Resources\Finance\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Resources\Finance\CreditNotes\Pages\EditCreditNote;
use App\Filament\Resources\Finance\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Resources\Finance\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Resources\Finance\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Resources\Finance\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Resources\Finance\CreditNotes\Tables\CreditNotesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Table;

class CreditNoteResource extends Resource
{
    protected static ?string $model = CreditNote::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-minus';

    protected static ?int $navigationSort = 66;

    protected static ?string $slug = 'credit-notes';

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
        return CreditNoteForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'create' => CreateCreditNote::route('/create'),
            'view' => ViewCreditNote::route('/{record}'),
            'edit' => EditCreditNote::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.finance');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.credit_notes');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.credit_note');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.credit_notes');
    }
}
