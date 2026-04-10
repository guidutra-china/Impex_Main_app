<?php

namespace App\Filament\Resources\Finance\DebitNotes\Pages;

use App\Filament\Resources\Finance\DebitNotes\DebitNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListDebitNotes extends ListRecords
{
    protected static string $resource = DebitNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
