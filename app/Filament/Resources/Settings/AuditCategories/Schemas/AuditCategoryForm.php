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
                Section::make(__('forms.sections.category_details'))
                    ->schema([
                        TextInput::make('name')
                            ->label(__('forms.labels.category_name'))
                            ->required()
                            ->maxLength(255)
                            ->placeholder(__('forms.placeholders.eg_quality_management')),
                        Textarea::make('description')
                            ->label(__('forms.labels.description'))
                            ->rows(2)
                            ->maxLength(1000)
                            ->placeholder(__('forms.placeholders.brief_description_of_what_this_category_evaluates'))
                            ->columnSpanFull(),
                        TextInput::make('weight')
                            ->label(__('forms.labels.weight_2'))
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->helperText(__('forms.helpers.percentage_weight_for_scoring_all_active_categories_should')),
                        TextInput::make('sort_order')
                            ->label(__('forms.labels.sort_order'))
                            ->numeric()
                            ->required()
                            ->default(0)
                            ->minValue(0),
                        Toggle::make('is_active')
                            ->label(__('forms.labels.active'))
                            ->default(true)
                            ->helperText(__('forms.helpers.inactive_categories_will_not_appear_in_new_audits')),
                    ])
                    ->columns(2),

                Section::make(__('forms.sections.evaluation_criteria'))
                    ->description(__('forms.descriptions.define_the_specific_criteria_that_will_be_evaluated_under'))
                    ->schema([
                        Repeater::make('criteria')
                            ->relationship('criteria')
                            ->schema([
                                TextInput::make('name')
                                    ->label(__('forms.labels.criterion'))
                                    ->required()
                                    ->maxLength(255)
                                    ->columnSpan(2),
                                Textarea::make('description')
                                    ->label(__('forms.labels.guidance_notes'))
                                    ->rows(1)
                                    ->maxLength(500)
                                    ->placeholder(__('forms.placeholders.optional_guidance_for_the_auditor'))
                                    ->columnSpan(2),
                                Select::make('type')
                                    ->label(__('forms.labels.evaluation_type'))
                                    ->options(CriterionType::class)
                                    ->required()
                                    ->default('scored'),
                                Toggle::make('is_critical')
                                    ->label(__('forms.labels.critical'))
                                    ->helperText(__('forms.helpers.failing_a_critical_criterion_autorejects_the_supplier')),
                                TextInput::make('sort_order')
                                    ->label(__('forms.labels.order'))
                                    ->numeric()
                                    ->default(0)
                                    ->minValue(0),
                                Toggle::make('is_active')
                                    ->label(__('forms.labels.active'))
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
