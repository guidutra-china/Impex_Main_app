<?php

namespace App\Filament\Resources\Shipments\Tables;

use App\Domain\Financial\Enums\AdditionalCostType;
use App\Domain\Financial\Enums\BillableTo;
use App\Domain\Infrastructure\Actions\TransitionStatusAction;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Enums\TransportMode;
use App\Domain\Logistics\Models\Shipment;
use App\Filament\Actions\QuickViewAction;
use App\Filament\Actions\StatusTransitionActions;
use App\Filament\Resources\Shipments\ShipmentResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Collection;

class ShipmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['items.proformaInvoiceItem', 'additionalCosts']))
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('company.name')
                    ->label(__('forms.labels.client'))
                    ->searchable()
                    ->sortable()
                    ->limit(30),
                TextColumn::make('client_reference')
                    ->label(__('forms.labels.client_reference'))
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->action(static::changeStatusAction()),
                TextColumn::make('transport_mode')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('bl_number')
                    ->label(__('forms.labels.bl'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('container_number')
                    ->label(__('forms.labels.container'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('origin_port')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('destination_port')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('etd')
                    ->label(__('forms.labels.etd'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->action(static::updateScheduleAction('updateScheduleFromEtd')),
                TextColumn::make('eta')
                    ->label(__('forms.labels.eta'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->action(static::updateScheduleAction('updateScheduleFromEta')),
                TextColumn::make('actual_departure')
                    ->label(__('forms.labels.actual_departure'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable()
                    ->action(static::updateScheduleAction('updateScheduleFromActualDeparture')),
                TextColumn::make('actual_arrival')
                    ->label(__('forms.labels.actual_arrival'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable()
                    ->action(static::updateScheduleAction('updateScheduleFromActualArrival')),
                TextColumn::make('total_value')
                    ->label(__('forms.labels.products_total'))
                    ->getStateUsing(fn ($record) => $record->total_value)
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state, 2))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('freight_total')
                    ->label(__('forms.labels.freight'))
                    ->getStateUsing(fn ($record) => $record->additionalCosts
                        ->where('billable_to', BillableTo::CLIENT)
                        ->where('cost_type', AdditionalCostType::FREIGHT)
                        ->sum('amount_in_document_currency'))
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '').' '.Money::format($state, 2))
                    ->alignEnd()
                    ->toggleable(),
                TextColumn::make('items_count')
                    ->label(__('forms.labels.items'))
                    ->counts('items')
                    ->alignCenter()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('responsible.name')
                    ->label(__('forms.labels.responsible'))
                    ->sortable()
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->dateTime('d/m/Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(ShipmentStatus::class),
                SelectFilter::make('transport_mode')
                    ->options(TransportMode::class),
                SelectFilter::make('company_id')
                    ->label(__('forms.labels.client'))
                    ->relationship('company', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('my_projects')
                    ->label(__('forms.labels.my_projects'))
                    ->toggle()
                    ->query(fn ($query) => $query->where('responsible_user_id', auth()->id())),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ActionGroup::make([
                    StatusTransitionActions::make(ShipmentStatus::class),
                    QuickViewAction::make(ShipmentResource::class),
                    ViewAction::make(),
                    EditAction::make(),
                    DeleteAction::make()
                        ->before(function (Shipment $record, DeleteAction $action) {
                            static::haltIfDeletionBlocked([$record], $action);
                        }),
                ])
                    ->icon('heroicon-m-ellipsis-vertical')
                    ->color('gray'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->before(function (Collection $records, DeleteBulkAction $action) {
                            static::haltIfDeletionBlocked($records, $action);
                        }),
                ]),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('No shipments')
            ->emptyStateDescription('Create a shipment to start tracking your exports.')
            ->emptyStateIcon('heroicon-o-truck');
    }

    /**
     * Quick status changer — clicking the status badge opens a modal with the
     * transitions allowed by the state machine. Runs through the same
     * TransitionStatusAction pipeline as the row "Change Status" menu, so all
     * guards and side effects apply.
     */
    protected static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('forms.labels.change_status'))
            ->icon('heroicon-o-arrows-right-left')
            ->modalHeading(fn (Shipment $record) => $record->reference.' — '.__('forms.labels.change_status'))
            ->modalWidth('md')
            ->visible(fn (Shipment $record) => ! empty($record->getAllowedNextStatuses())
                && (auth()->user()?->can('update', $record) ?? false))
            ->form(fn (Shipment $record) => [
                Select::make('status')
                    ->label(__('forms.labels.new_status'))
                    ->options(collect($record->getAllowedNextStatuses())
                        ->mapWithKeys(fn (string $value) => [$value => ShipmentStatus::from($value)->getLabel()]))
                    ->required()
                    ->live()
                    ->native(false),
                Textarea::make('notes')
                    ->label(__('forms.labels.transition_notes'))
                    ->rows(2)
                    ->maxLength(1000)
                    ->visible(fn (Get $get) => $get('status') === ShipmentStatus::CANCELLED->value),
            ])
            ->action(function (Shipment $record, array $data) {
                try {
                    app(TransitionStatusAction::class)->execute(
                        $record,
                        ShipmentStatus::from($data['status']),
                        $data['notes'] ?? null,
                    );

                    Notification::make()
                        ->title(__('messages.status_changed_to').' '.ShipmentStatus::from($data['status'])->getLabel())
                        ->success()
                        ->send();
                } catch (\Throwable $e) {
                    Notification::make()
                        ->title(__('messages.status_transition_failed'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * Quick ETD/ETA editor — clicking either date cell opens a modal with the
     * two date pickers, mirroring the Forwarder Portal quick action. Attached
     * to each column under a distinct name so both cells work independently.
     */
    protected static function updateScheduleAction(string $name): Action
    {
        return Action::make($name)
            ->label(__('forms.labels.update_dates'))
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->modalHeading(fn (Shipment $record) => $record->reference.' — '.__('forms.labels.update_dates'))
            ->modalWidth('md')
            ->visible(fn (Shipment $record) => auth()->user()?->can('update', $record) ?? false)
            ->fillForm(fn (Shipment $record) => [
                'etd' => $record->etd?->toDateString(),
                'eta' => $record->eta?->toDateString(),
                'actual_departure' => $record->actual_departure?->toDateString(),
                'actual_arrival' => $record->actual_arrival?->toDateString(),
            ])
            ->form([
                DatePicker::make('etd')
                    ->label(__('forms.labels.etd'))
                    ->native(false),
                DatePicker::make('eta')
                    ->label(__('forms.labels.eta'))
                    ->native(false),
                DatePicker::make('actual_departure')
                    ->label(__('forms.labels.actual_departure'))
                    ->native(false),
                DatePicker::make('actual_arrival')
                    ->label(__('forms.labels.actual_arrival'))
                    ->native(false),
            ])
            ->action(function (Shipment $record, array $data) {
                $record->update([
                    'etd' => $data['etd'],
                    'eta' => $data['eta'],
                    'actual_departure' => $data['actual_departure'],
                    'actual_arrival' => $data['actual_arrival'],
                ]);

                Notification::make()
                    ->title(__('messages.dates_updated'))
                    ->success()
                    ->send();
            });
    }

    /**
     * Barra a exclusão de embarques cujas parcelas têm pagamento alocado.
     *
     * O hook `deleting` do Shipment é a rede de segurança e lança exceção; aqui
     * a checagem acontece antes para virar notificação em vez de erro cru, no
     * mesmo formato de lista usado nos bloqueios de finalização da PI.
     *
     * @param  iterable<Shipment>  $records
     */
    protected static function haltIfDeletionBlocked(iterable $records, mixed $action): void
    {
        $blocked = [];

        foreach ($records as $record) {
            foreach ($record->getDeletionBlockers() as $blocker) {
                $blocked[] = '• '.$record->reference.': '.$blocker;
            }
        }

        if ($blocked === []) {
            return;
        }

        Notification::make()
            ->danger()
            ->title(__('messages.shipment_delete_blocked_title'))
            ->body(__('messages.shipment_delete_blocked_body')."\n".implode("\n", $blocked))
            ->persistent()
            ->send();

        $action->halt();
    }
}
