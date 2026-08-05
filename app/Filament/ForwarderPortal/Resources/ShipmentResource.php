<?php

namespace App\Filament\ForwarderPortal\Resources;

use App\Domain\Financial\Enums\AdditionalCostStatus;
use App\Domain\Logistics\Enums\ShipmentStatus;
use App\Domain\Logistics\Models\Shipment;
use App\Events\ShipmentEtaChangedByForwarder;
use App\Filament\ForwarderPortal\Resources\ShipmentResource\Pages;
use App\Filament\ForwarderPortal\Resources\ShipmentResource\RelationManagers\ForwarderAdditionalCostsRelationManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Section as FormSection;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ShipmentResource extends Resource
{
    protected static ?string $model = Shipment::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'shipments';

    protected static ?string $recordTitleAttribute = 'reference';

    protected static bool $isScopedToTenant = false;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('forwarder-portal:view-shipments') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            FormSection::make(__('forwarder_portal.shipment.section.read_only'))
                ->schema([
                    TextInput::make('reference')->disabled(),
                    TextInput::make('bl_number')->label('B/L')->disabled(),
                    TextInput::make('booking_number')->disabled(),
                    TextInput::make('container_number')->disabled(),
                    TextInput::make('vessel_name')->disabled(),
                    TextInput::make('voyage_number')->disabled(),
                    TextInput::make('origin_port')->disabled(),
                    TextInput::make('destination_port')->disabled(),
                ])
                ->columns(2),

            FormSection::make(__('forwarder_portal.shipment.section.editable'))
                ->description(__('forwarder_portal.shipment.section.editable_desc'))
                ->schema([
                    DatePicker::make('etd')
                        ->label(__('forwarder_portal.shipment.etd'))
                        ->native(false),
                    DatePicker::make('eta')
                        ->label(__('forwarder_portal.shipment.eta'))
                        ->native(false)
                        ->helperText(__('forwarder_portal.shipment.eta_helper')),
                    Select::make('status')
                        ->label(__('forwarder_portal.shipment.status'))
                        ->options(fn ($record) => static::editableStatusOptions($record))
                        ->disabled(fn ($record) => empty(static::editableStatusOptions($record)))
                        ->helperText(__('forwarder_portal.shipment.status_helper'))
                        ->live()
                        ->required(),
                    DatePicker::make('actual_departure')
                        ->label(__('forwarder_portal.shipment.actual_departure'))
                        ->native(false),
                    DatePicker::make('actual_arrival')
                        ->label(__('forwarder_portal.shipment.actual_arrival'))
                        ->native(false),
                    Toggle::make('set_actual_arrival_today')
                        ->label(__('forwarder_portal.shipment.set_actual_arrival_today'))
                        ->helperText(__('forwarder_portal.shipment.set_actual_arrival_today_helper'))
                        ->dehydrated(false)
                        ->default(true)
                        ->visible(function (Get $get, ?Shipment $record) {
                            $newStatus = $get('status');
                            $previousStatus = $record?->status?->value;

                            return $newStatus === ShipmentStatus::ARRIVED->value
                                && $previousStatus !== ShipmentStatus::ARRIVED->value;
                        }),
                ])
                ->columns(2),
        ]);
    }

    /**
     * Quick ETD / ETA editor — opens a modal with two date pickers.
     * Replaces the full Edit page on the list because forwarders only
     * need to revise the shipping window from here. Also attached to the
     * ETD/ETA columns (under distinct names) so clicking a date cell opens
     * the same modal. Fires the
     * ShipmentEtaChangedByForwarder event when the ETA actually
     * differs so the responsible admin and client portal users get
     * notified through the existing pipeline.
     */
    protected static function updateScheduleAction(string $name = 'updateSchedule'): Action
    {
        return Action::make($name)
            ->label(__('forwarder_portal.shipment.update_schedule'))
            ->icon('heroicon-o-calendar-days')
            ->color('primary')
            ->modalHeading(fn (Shipment $record) => __('forwarder_portal.shipment.update_schedule_heading', [
                'ref' => $record->reference,
            ]))
            ->modalSubmitActionLabel(__('forwarder_portal.shipment.update_schedule_submit'))
            ->modalWidth('md')
            ->visible(fn () => auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false)
            ->fillForm(fn (Shipment $record) => [
                'etd' => $record->etd?->toDateString(),
                'eta' => $record->eta?->toDateString(),
            ])
            ->form([
                \Filament\Forms\Components\DatePicker::make('etd')
                    ->label(__('forwarder_portal.shipment.etd'))
                    ->native(false),
                \Filament\Forms\Components\DatePicker::make('eta')
                    ->label(__('forwarder_portal.shipment.eta'))
                    ->native(false)
                    ->helperText(__('forwarder_portal.shipment.eta_helper'))
                    ->required(),
            ])
            ->action(function (Shipment $record, array $data) {
                $previousEta = $record->getOriginal('eta')?->format('Y-m-d');

                $record->update([
                    'etd' => $data['etd'],
                    'eta' => $data['eta'],
                ]);

                $newEta = $record->refresh()->eta?->format('Y-m-d');
                if ($previousEta !== $newEta) {
                    event(new ShipmentEtaChangedByForwarder(
                        shipment: $record,
                        previousEta: $previousEta,
                        newEta: $newEta,
                        userId: auth()->id(),
                    ));

                    Notification::make()
                        ->success()
                        ->title(__('forwarder_portal.shipment.eta_change_acknowledged'))
                        ->body(__('forwarder_portal.shipment.eta_change_acknowledged_body'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('forwarder_portal.shipment.update_schedule_done'))
                    ->send();
            });
    }

    /**
     * Quick status changer — clicking the status badge on the list opens a
     * modal showing the flow previous → current → next, and submitting
     * advances the shipment to the next forwarder-allowed status. Mirrors
     * the guards on EditShipment: only IN_TRANSIT/ARRIVED, only when the
     * state machine permits it, and ARRIVED is blocked while today is
     * earlier than the planned ETA.
     */
    protected static function changeStatusAction(): Action
    {
        return Action::make('changeStatus')
            ->label(__('forwarder_portal.shipment.change_status'))
            ->icon('heroicon-o-arrows-right-left')
            ->modalHeading(fn (Shipment $record) => __('forwarder_portal.shipment.change_status_heading', [
                'ref' => $record->reference,
            ]))
            ->modalWidth('md')
            ->modalSubmitActionLabel(function (Shipment $record) {
                $next = static::nextForwarderStatus($record);

                return $next
                    ? __('forwarder_portal.shipment.change_status_submit', ['status' => $next->getLabel()])
                    : __('forwarder_portal.shipment.change_status');
            })
            ->modalSubmitAction(fn ($action, Shipment $record) => static::nextForwarderStatus($record) ? $action : false)
            ->visible(fn () => auth()->user()?->can('forwarder-portal:update-shipment-logistics') ?? false)
            ->registerModalActions([
                Action::make('revertToInTransit')
                    ->label(__('forwarder_portal.shipment.revert_to_in_transit'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('gray')
                    ->outlined()
                    ->size('sm')
                    ->requiresConfirmation()
                    ->modalHeading(__('forwarder_portal.shipment.revert_to_in_transit'))
                    ->modalDescription(__('forwarder_portal.shipment.revert_confirm'))
                    ->cancelParentActions()
                    ->visible(fn (Shipment $record) => static::canRevertToInTransit($record))
                    ->action(function (Shipment $record) {
                        if (! static::canRevertToInTransit($record)) {
                            Notification::make()
                                ->danger()
                                ->title(__('forwarder_portal.shipment.invalid_transition'))
                                ->send();

                            return;
                        }

                        // Reverting means the cargo did not actually arrive, so
                        // the actual arrival date is cleared — leaving it set
                        // would also block the "arrival = today" auto-fill on
                        // the next real arrival.
                        $record->update([
                            'status' => ShipmentStatus::IN_TRANSIT->value,
                            'actual_arrival' => null,
                        ]);

                        Notification::make()
                            ->success()
                            ->title(__('forwarder_portal.shipment.revert_done'))
                            ->send();
                    }),
            ])
            ->modalContent(fn (Shipment $record, Action $action) => view('filament.forwarder-portal.status-flow', [
                'previous' => static::previousStatusInFlow($record),
                'current' => $record->status,
                'next' => static::nextForwarderStatus($record),
                'canRevert' => static::canRevertToInTransit($record),
                'action' => $action,
            ]))
            ->form(fn (Shipment $record) => static::nextForwarderStatus($record) === ShipmentStatus::ARRIVED
                ? [
                    Toggle::make('set_actual_arrival_today')
                        ->label(__('forwarder_portal.shipment.set_actual_arrival_today'))
                        ->helperText(__('forwarder_portal.shipment.set_actual_arrival_today_helper'))
                        ->default(true),
                ]
                : [])
            ->action(function (Shipment $record, array $data) {
                $next = static::nextForwarderStatus($record);

                if (! $next || ! $record->canTransitionTo($next->value)) {
                    Notification::make()
                        ->danger()
                        ->title(__('forwarder_portal.shipment.invalid_transition'))
                        ->send();

                    return;
                }

                if ($next === ShipmentStatus::ARRIVED) {
                    $eta = $record->eta;
                    if ($eta && now()->startOfDay()->lt($eta->copy()->startOfDay())) {
                        Notification::make()
                            ->danger()
                            ->title(__('forwarder_portal.shipment.cannot_arrive_before_eta_title'))
                            ->body(__('forwarder_portal.shipment.cannot_arrive_before_eta_body', [
                                'eta' => $eta->format('d/m/Y'),
                            ]))
                            ->send();

                        return;
                    }
                }

                $payload = ['status' => $next->value];

                // Same auto-fill rule as EditShipment: only when arriving now
                // and no actual arrival was recorded yet.
                if ($next === ShipmentStatus::ARRIVED
                    && (bool) ($data['set_actual_arrival_today'] ?? false)
                    && empty($record->actual_arrival)
                ) {
                    $payload['actual_arrival'] = now()->toDateString();
                }

                $record->update($payload);

                Notification::make()
                    ->success()
                    ->title(__('forwarder_portal.shipment.change_status_done', ['status' => $next->getLabel()]))
                    ->send();
            });
    }

    /**
     * Next status the forwarder may move this shipment FORWARD to, or null
     * when there is no forward progression (draft, booked, cancelled — and
     * ARRIVED, which is the end of the flow; the ARRIVED → IN_TRANSIT
     * transition is an undo, exposed separately via canRevertToInTransit()).
     */
    public static function nextForwarderStatus(Shipment $record): ?ShipmentStatus
    {
        if ($record->status === ShipmentStatus::ARRIVED) {
            return null;
        }

        $allowedForForwarder = [
            ShipmentStatus::IN_TRANSIT->value,
            ShipmentStatus::ARRIVED->value,
        ];

        $current = $record->status?->value;

        foreach (Shipment::allowedTransitions()[$current] ?? [] as $candidate) {
            if (in_array($candidate, $allowedForForwarder, true)) {
                return ShipmentStatus::from($candidate);
            }
        }

        return null;
    }

    /**
     * The state machine allows ARRIVED → IN_TRANSIT as a correction path for
     * shipments marked as arrived by mistake. The modal presents it as an
     * explicit undo button rather than the "next" status.
     */
    public static function canRevertToInTransit(Shipment $record): bool
    {
        return $record->status === ShipmentStatus::ARRIVED
            && $record->canTransitionTo(ShipmentStatus::IN_TRANSIT->value);
    }

    /**
     * Predecessor of the current status in the happy-path flow, shown in the
     * modal purely for orientation. CANCELLED sits outside the flow.
     */
    public static function previousStatusInFlow(Shipment $record): ?ShipmentStatus
    {
        $flow = [
            ShipmentStatus::DRAFT,
            ShipmentStatus::BOOKED,
            ShipmentStatus::CUSTOMS,
            ShipmentStatus::IN_TRANSIT,
            ShipmentStatus::ARRIVED,
        ];

        $index = array_search($record->status, $flow, true);

        return $index !== false && $index > 0 ? $flow[$index - 1] : null;
    }

    /**
     * Forwarder may only set IN_TRANSIT or ARRIVED, and only when the
     * existing state machine permits it. Current status is always included
     * so the select doesn't appear empty when the value is unchanged.
     */
    protected static function editableStatusOptions($record): array
    {
        if (! $record) {
            return [];
        }

        $allowedForForwarder = [
            ShipmentStatus::IN_TRANSIT->value,
            ShipmentStatus::ARRIVED->value,
        ];

        $current = $record->status?->value;
        $candidates = collect(Shipment::allowedTransitions()[$current] ?? [])
            ->intersect($allowedForForwarder)
            ->values()
            ->all();

        $options = [];
        if ($current) {
            $options[$current] = ShipmentStatus::from($current)->getLabel();
        }
        foreach ($candidates as $value) {
            $options[$value] = ShipmentStatus::from($value)->getLabel();
        }

        return $options;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(function ($query) {
                $tenant = Filament::getTenant();
                if ($tenant) {
                    $query->forForwarderCompany($tenant->getKey());
                    // Preload only this forwarder's AdditionalCost rows so the
                    // "total due / received" columns aggregate cheaply per row.
                    $query->with(['additionalCosts' => function ($q) use ($tenant) {
                        $q->where('forwarder_company_id', $tenant->getKey());
                    }]);
                }
            })
            ->columns([
                TextColumn::make('reference')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->copyable(),
                TextColumn::make('bl_number')
                    ->label('B/L')
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('company.name')
                    ->label(__('forwarder_portal.shipment.client'))
                    ->searchable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->badge()
                    ->action(static::changeStatusAction()),
                TextColumn::make('origin_port')->label(__('forwarder_portal.shipment.origin'))->placeholder('—'),
                TextColumn::make('destination_port')->label(__('forwarder_portal.shipment.destination'))->placeholder('—'),
                TextColumn::make('etd')->label('ETD')->date('d/m/Y')->sortable()->placeholder('—')
                    ->action(static::updateScheduleAction('updateScheduleFromEtd')),
                TextColumn::make('eta')->label('ETA')->date('d/m/Y')->sortable()->placeholder('—')
                    ->action(static::updateScheduleAction('updateScheduleFromEta')),
                TextColumn::make('forwarder_total_due')
                    ->label(__('forwarder_portal.shipment.total_due'))
                    ->visible(fn () => auth()->user()?->can('forwarder-portal:view-financial-summary') ?? false)
                    ->state(fn (Shipment $record) => $record->additionalCosts
                        ->reject(fn ($cost) => $cost->forwarder_status === AdditionalCostStatus::WAIVED)
                        ->sum('forwarder_amount_in_document_currency'))
                    ->money(fn (Shipment $r) => $r->currency_code ?? 'USD', divideBy: 10000)
                    ->alignEnd(),
                TextColumn::make('forwarder_total_received')
                    ->label(__('forwarder_portal.shipment.total_received'))
                    ->visible(fn () => auth()->user()?->can('forwarder-portal:view-financial-summary') ?? false)
                    ->state(fn (Shipment $record) => $record->additionalCosts
                        ->where('forwarder_status', AdditionalCostStatus::PAID)
                        ->sum('forwarder_amount_in_document_currency'))
                    ->money(fn (Shipment $r) => $r->currency_code ?? 'USD', divideBy: 10000)
                    ->color(fn (Shipment $r) => $r->additionalCosts
                        ->where('forwarder_status', AdditionalCostStatus::PAID)
                        ->sum('forwarder_amount_in_document_currency') > 0 ? 'success' : 'gray')
                    ->alignEnd(),
                TextColumn::make('vessel_name')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('container_number')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->options(ShipmentStatus::class),
            ])
            ->recordUrl(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record]))
            ->recordActions([
                \Filament\Actions\ViewAction::make()
                    ->url(fn (Shipment $record) => Pages\ViewShipment::getUrl(['record' => $record])),
                static::updateScheduleAction(),
            ])
            ->defaultSort('eta', 'asc')
            ->emptyStateHeading(__('forwarder_portal.shipment.empty_heading'))
            ->emptyStateDescription(__('forwarder_portal.shipment.empty_desc'))
            ->emptyStateIcon('heroicon-o-truck');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('forwarder_portal.shipment.section.summary'))
                ->schema([
                    TextEntry::make('reference')->copyable()->weight('bold'),
                    TextEntry::make('status')->badge(),
                    TextEntry::make('bl_number')->label('B/L')->placeholder('—'),
                    TextEntry::make('booking_number')->placeholder('—'),
                    TextEntry::make('forwarderCompany.name')->label(__('forwarder_portal.shipment.forwarder'))->placeholder('—'),
                    TextEntry::make('responsible.name')->label(__('forwarder_portal.shipment.responsible'))->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Section::make(__('forwarder_portal.shipment.section.route'))
                ->schema([
                    TextEntry::make('origin_port')->placeholder('—'),
                    TextEntry::make('destination_port')->placeholder('—'),
                    TextEntry::make('vessel_name')->placeholder('—'),
                    TextEntry::make('voyage_number')->placeholder('—'),
                    TextEntry::make('container_number')->placeholder('—'),
                    TextEntry::make('container_type')->placeholder('—'),
                    TextEntry::make('etd')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('eta')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('actual_departure')->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('actual_arrival')->date('d/m/Y')->placeholder('—'),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
    }

    public static function getRelations(): array
    {
        return [
            ForwarderAdditionalCostsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListShipments::route('/'),
            'view' => Pages\ViewShipment::route('/{record}'),
            'edit' => Pages\EditShipment::route('/{record}/edit'),
        ];
    }

    public static function getNavigationGroup(): ?string
    {
        return __('navigation.groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('navigation.resources.shipments');
    }

    public static function getModelLabel(): string
    {
        return __('navigation.models.shipment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('navigation.models.shipments');
    }
}
