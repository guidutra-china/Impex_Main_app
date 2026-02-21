<?php

namespace App\Filament\Resources\CRM\Companies\RelationManagers;

use App\Domain\CRM\Enums\ContactFunction;
use App\Domain\CRM\Models\Contact;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ContactsRelationManager extends RelationManager
{
    protected static string $relationship = 'contacts';

    protected static ?string $title = 'Contacts';

    protected static ?string $recordTitleAttribute = 'name';

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Personal Information')
                    ->schema([
                        TextInput::make('name')
                            ->label('Name')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('position')
                            ->label('Position')
                            ->placeholder('e.g. Export Manager, Head of Procurement')
                            ->maxLength(255),
                        Select::make('function')
                            ->label('Department')
                            ->options(ContactFunction::class)
                            ->searchable(),
                        Toggle::make('is_primary')
                            ->label('Primary Contact')
                            ->helperText('Only one primary contact per company. Setting this will unset any existing primary.'),
                    ])
                    ->columns(2),

                Section::make('Contact Channels')
                    ->schema([
                        TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label('Phone')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('whatsapp')
                            ->label('WhatsApp')
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('wechat')
                            ->label('WeChat')
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make()
                    ->schema([
                        Textarea::make('notes')
                            ->label('Notes')
                            ->rows(2)
                            ->maxLength(1000)
                            ->columnSpanFull(),
                    ]),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                IconColumn::make('is_primary')
                    ->label('')
                    ->boolean()
                    ->trueIcon('heroicon-s-star')
                    ->falseIcon('heroicon-o-star')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->alignCenter()
                    ->width('40px'),
                TextColumn::make('name')
                    ->label('Name')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Contact $record) => $record->position),
                TextColumn::make('function')
                    ->label('Department')
                    ->badge(),
                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),
                TextColumn::make('phone')
                    ->label('Phone')
                    ->copyable()
                    ->icon('heroicon-o-phone'),
                TextColumn::make('whatsapp')
                    ->label('WhatsApp')
                    ->copyable()
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->toggleable(),
                TextColumn::make('wechat')
                    ->label('WeChat')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->after(function (Contact $record) {
                        if ($record->is_primary) {
                            $this->ensureSinglePrimary($record);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->after(function (Contact $record) {
                        if ($record->is_primary) {
                            $this->ensureSinglePrimary($record);
                        }
                    }),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('is_primary', 'desc');
    }

    private function ensureSinglePrimary(Contact $contact): void
    {
        Contact::where('company_id', $contact->company_id)
            ->where('id', '!=', $contact->id)
            ->where('is_primary', true)
            ->update(['is_primary' => false]);
    }
}
