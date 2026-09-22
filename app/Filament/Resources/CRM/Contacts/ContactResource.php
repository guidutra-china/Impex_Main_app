<?php

namespace App\Filament\Resources\CRM\Contacts;

use App\Domain\CRM\Models\Contact;
use App\Filament\Resources\CRM\Contacts\Pages\ListContacts;
use App\Filament\Resources\CRM\Contacts\Tables\ContactsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Table;

/**
 * Lista geral de contatos, pesquisável por nome, e-mail, telefone, WhatsApp
 * e WeChat, sem precisar saber a empresa. Só consulta: o cadastro segue na
 * aba Contatos da empresa, onde vive a regra do contato principal.
 */
class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 32;

    protected static ?string $slug = 'crm/contacts';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('view-companies') ?? false;
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.crm');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.contacts');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.contact');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.contacts');
    }

    public static function table(Table $table): Table
    {
        return ContactsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
        ];
    }
}
