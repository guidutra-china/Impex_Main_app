<?php

namespace App\Filament\Resources\CRM\Contacts\Pages;

use App\Filament\Resources\CRM\Contacts\ContactResource;
use Filament\Resources\Pages\ListRecords;

class ListContacts extends ListRecords
{
    protected static string $resource = ContactResource::class;

    /** Sem "Novo": o contato é criado na aba Contatos da empresa. */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
