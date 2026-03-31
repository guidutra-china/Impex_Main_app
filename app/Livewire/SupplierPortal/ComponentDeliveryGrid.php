<?php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Actions\PopulateScheduleComponentsFromProductAction;
use App\Domain\Planning\Models\ComponentDelivery;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use Illuminate\View\View;
use Livewire\Component;

class ComponentDeliveryGrid extends Component
{
    public ProductionSchedule $schedule;
    public ?string $newDateInput = null;
    public bool $showAddDate = false;

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->autoPopulateFromBom();
    }

    private function autoPopulateFromBom(): void
    {
        if (!$this->canEdit()) {
            return;
        }
        if ($this->schedule->components()->count() > 0) {
            return;
        }
        app(PopulateScheduleComponentsFromProductAction::class)->execute($this->schedule);
    }

    public function canEdit(): bool
    {
        return $this->schedule->status->canBeEditedBySupplier();
    }

    public function addDateColumn(): void
    {
        if (!$this->canEdit() || !$this->newDateInput) {
            return;
        }

        $date = $this->newDateInput;
        $this->newDateInput = null;
        $this->showAddDate = false;

        $this->schedule->load('components');
        foreach ($this->schedule->components as $component) {
            $exists = ComponentDelivery::where([
                'production_schedule_component_id' => $component->id,
                'expected_date'                    => $date,
            ])->exists();

            if (!$exists) {
                ComponentDelivery::create([
                    'production_schedule_component_id' => $component->id,
                    'expected_date'                    => $date,
                    'expected_qty'                     => 0,
                ]);
            }
        }
    }

    public function removeDateColumn(string $date): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        ComponentDelivery::whereIn('production_schedule_component_id', $componentIds)
            ->whereDate('expected_date', $date)
            ->delete();
    }

    public function addDelivery(int $componentId, string $date, int $qty): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $component = $this->schedule->components()->find($componentId);
        if (!$component) {
            return;
        }

        ComponentDelivery::updateOrCreate(
            [
                'production_schedule_component_id' => $componentId,
                'expected_date'                    => $date,
            ],
            ['expected_qty' => max(0, $qty)]
        );
    }

    public function updateExpectedQty(int $deliveryId, ?string $value): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery || $delivery->isReceived()) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $qty = ($value !== null && $value !== '') ? max(0, (int) $value) : 0;
        $delivery->update(['expected_qty' => $qty]);
    }

    public function markReceived(int $deliveryId, ?int $receivedQty = null): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $delivery->update([
            'received_qty'  => $receivedQty ?? $delivery->expected_qty,
            'received_date' => now()->toDateString(),
        ]);
    }

    public function undoReceived(int $deliveryId): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $delivery->update([
            'received_qty'  => null,
            'received_date' => null,
        ]);
    }

    public function removeDelivery(int $deliveryId): void
    {
        if (!$this->canEdit()) {
            return;
        }

        $delivery = ComponentDelivery::find($deliveryId);
        if (!$delivery) {
            return;
        }

        $componentIds = $this->schedule->components()->pluck('id');
        if (!$componentIds->contains($delivery->production_schedule_component_id)) {
            return;
        }

        $delivery->delete();
    }

    public function render(): View
    {
        $this->schedule->load(['components.deliveries', 'proformaInvoice.items.product']);

        $components = $this->schedule->components
            ->map(function (ProductionScheduleComponent $comp) {
                $piItem = $this->schedule->proformaInvoice->items
                    ->firstWhere('id', $comp->proforma_invoice_item_id);

                return [
                    'id'                => $comp->id,
                    'name'              => $comp->component_name,
                    'product_name'      => $piItem?->product?->name ?? $piItem?->description ?? '—',
                    'supplier_name'     => $comp->supplier_name,
                    'quantity_required' => $comp->quantity_required,
                    'total_received'    => $comp->totalReceived(),
                    'progress_percent'  => $comp->progressPercent(),
                    'deliveries'        => $comp->deliveries,
                ];
            })
            ->values()
            ->toArray();

        $allDates = $this->schedule->components
            ->flatMap(fn ($c) => $c->deliveries->pluck('expected_date'))
            ->map(fn ($d) => $d->format('Y-m-d'))
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $deliveryMap = [];
        foreach ($this->schedule->components as $comp) {
            foreach ($comp->deliveries as $delivery) {
                $deliveryMap[$comp->id][$delivery->expected_date->format('Y-m-d')] = $delivery;
            }
        }

        return view('livewire.supplier-portal.component-delivery-grid', [
            'components'  => $components,
            'allDates'    => $allDates,
            'deliveryMap' => $deliveryMap,
        ]);
    }
}
