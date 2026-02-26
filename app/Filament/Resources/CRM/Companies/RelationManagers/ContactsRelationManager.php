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
                Section::make(__('forms.sections.personal_information'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.name'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('position')
                            ->label(__('forms.labels.position'))
                            ->placeholder(__('forms.placeholders.eg_export_manager_head_of_procurement'))
                            ->maxLength(255),
                        Select::make('function')
                            ->label(__('forms.labels.department'))
                            ->options(ContactFunction::class)
                            ->searchable(),
                        Toggle::make('is_primary')
                            ->label(__('forms.labels.primary_contact'))
                            ->helperText(__('forms.helpers.only_one_primary_contact_per_company_setting_this_will')),
                    ])
                    ->columns(2),

                Section::make(__('forms.sections.contact_channels'))
                    ->schema([
                        TextInput::make('email')
                            ->label(__('forms.labels.email'))
                            ->email()
                            ->maxLength(255),
                        TextInput::make('phone')
                            ->label(__('forms.labels.phone'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('whatsapp')
                            ->label(__('forms.labels.whatsapp'))
                            ->tel()
                            ->maxLength(50),
                        TextInput::make('wechat')
                            ->label(__('forms.labels.wechat'))
                            ->maxLength(255),
                    ])
                    ->columns(2),

                Section::make()
                    ->schema([
                        Textarea::make('notes')
                            ->label(__('forms.labels.notes'))
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
                    ->label(__('forms.labels.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (Contact $record) => $record->position),
                TextColumn::make('function')
                    ->label(__('forms.labels.department'))
                    ->badge(),
                TextColumn::make('email')
                    ->label(__('forms.labels.email'))
                    ->searchable()
                    ->copyable()
                    ->icon('heroicon-o-envelope'),
                TextColumn::make('phone')
                    ->label(__('forms.labels.phone'))
                    ->copyable()
                    ->icon('heroicon-o-phone'),
                TextColumn::make('whatsapp')
                    ->label(__('forms.labels.whatsapp'))
                    ->copyable()
                    ->icon('heroicon-o-chat-bubble-left-ellipsis')
                    ->toggleable(),
                TextColumn::make('wechat')
                    ->label(__('forms.labels.wechat'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->after(function (Contact $record) {
                        if ($record->is_primary) {
                            $this->ensureSinglePrimary($record);
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('edit-companies'))
                    ->after(function (Contact $record) {
                        if ($record->is_primary) {
                            $this->ensureSinglePrimary($record);
                        }
                    }),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->can('delete-companies')),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => auth()->user()?->can('delete-companies')),
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
