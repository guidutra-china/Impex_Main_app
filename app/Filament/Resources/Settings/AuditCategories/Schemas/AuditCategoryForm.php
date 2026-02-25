<?php

namespace App\Filament\Resources\Settings\AuditCategories\Schemas;

use App\Domain\SupplierAudits\Enums\CriterionType;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class AuditCategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Category Details')
                    ->schema([
                        TextInput::make('name')
                            ->label('Category Name')
                            ->required()
                            ->maxLength(255)
                            ->placeholder('e.g., Quality Management'),
                        Textarea::make('description')
                            ->label('Description')
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder('Brief description of what this category evaluates')
                            ->columnSpanFull(),
                        TextInput::make('weight')
                            ->label('Weight (%)')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText('Percentage weight for scoring. All active categories should total 100%.'),
                        TextInput::make('sort_order')
                            ->label('Sort Order')
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label('Active')
                            ->default(true)
                            ->helperText('Inactive categories will not appear in new audits.'),
                    ])
                    ->columns(2),

                Section::make('Evaluation Criteria')
                    ->description('Define the specific criteria that will be evaluated under this category.')
                    ->schema([
                        Repeater::make('criteria')
                            ->relationship('criteria')
                            ->schema([
                                TextInput::make('name')
                                    ->label('Criterion')
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Textarea::make('description')
                                    ->label('Guidance Notes')
                                    ->rows(1)
                                    ->maxLength(500)
                                    ->placeholder('Optional guidance for the auditor')
                                    ->columnSpan(2),
                                Select::make('type')
                                    ->label('Evaluation Type')
                                    ->options(CriterionType::class)
                                    ->required()
                                    ->default('scored'),
                                Toggle::make('is_critical')
                                    ->label('Critical')
                                    ->helperText('Failing a critical criterion auto-rejects the supplier.'),
                                TextInput::make('sort_order')
                                    ->label('Order')
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),
                                Toggle::make('is_active')
                                    ->label('Active')
                                    ->default(true),
                            ])
                            ->columns(4)
                            ->defaultItems(0)
                            ->addActionLabel('Add Criterion')
                            ->reorderable()
                            ->reorderableWithDragAndDrop()
                            ->collapsible()
                            ->itemLabel(fn (array $state): ?string => $state['name'] ?? 'New Criterion'),
                    ]),
            ]);
    }
}
