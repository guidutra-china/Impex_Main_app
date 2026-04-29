<?php

namespace App\Filament\Resources\Quotations\RelationManagers;

use App\Domain\Quotations\Models\Quotation;
use App\Filament\Resources\Quotations\Schemas\QuotationVersionInfolist;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Version History';

    protected static string|\BackedEnum|null $icon = 'heroicon-o-clock';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('version')
                    ->label(__('forms.labels.version'))
                    ->prefix('v')
                    ->sortable()
                    ->weight('bold')
                    ->alignCenter(),
                TextColumn::make('creator.name')
                    ->label(__('forms.labels.created_by'))
                    ->placeholder(__('forms.placeholders.system')),
                TextColumn::make('change_notes')
                    ->label(__('forms.labels.change_notes'))
                    ->limit(50)
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('forms.labels.date'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->defaultSort('version', 'desc')
            ->headerActions([
                $this->saveVersionAction(),
            ])
            ->recordActions([
                ViewAction::make()
                    ->modalHeading(fn ($record) => __('forms.labels.version').' v'.$record->version)
                    ->modalWidth('5xl')
                    ->infolist(fn (Schema $schema) => QuotationVersionInfolist::configure($schema)),
            ])
            ->emptyStateHeading(__('forms.empty.version_history_heading'))
            ->emptyStateDescription(__('forms.empty.version_history_description'))
            ->emptyStateIcon('heroicon-o-clock')
            ->emptyStateActions([
                $this->saveVersionAction(),
            ]);
    }

    protected function saveVersionAction(): Action
    {
        return Action::make('createVersion')
            ->label(__('forms.labels.save_version'))
            ->tooltip(__('forms.helpers.save_version_explainer'))
            ->icon('heroicon-o-clock')
            ->color('info')
            ->requiresConfirmation()
            ->modalHeading(__('forms.modals.save_version_heading'))
            ->modalDescription(__('forms.modals.save_version_description'))
            ->form([
                Textarea::make('change_notes')
                    ->label(__('forms.labels.change_notes'))
                    ->placeholder(__('forms.placeholders.describe_what_changed_in_this_version'))
                    ->rows(3)
                    ->maxLength(2000),
            ])
            ->action(function (array $data) {
                try {
                    /** @var Quotation $quotation */
                    $quotation = $this->getOwnerRecord();
                    $savedVersion = $quotation->saveVersion(
                        $data['change_notes'] ?? null,
                        auth()->id(),
                    );

                    Notification::make()
                        ->title(__('messages.version_saved', ['version' => $savedVersion]))
                        ->body(__('messages.snapshot_created', ['version' => $savedVersion + 1]))
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.version_save_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }
}
