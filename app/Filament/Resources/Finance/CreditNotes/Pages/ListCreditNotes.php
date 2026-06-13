<?php

namespace App\Filament\Resources\Finance\CreditNotes\Pages;

use App\Filament\Resources\Finance\CreditNotes\CreditNoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCreditNotes extends ListRecords
{
    protected static string $resource = CreditNoteResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
