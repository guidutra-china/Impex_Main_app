<?php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Actions\PopulateScheduleComponentsFromProductAction;
use App\Domain\Planning\Enums\ComponentStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleComponent;
use Illuminate\View\View;
use Livewire\Component;

class ComponentInventoryPanel extends Component
{
    public ProductionSchedule $schedule;

    public array $expandedItems = [];

    public bool $isExpanded = true;

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->autoPopulateFromBom();
    }

    /**
     * Auto-populate components from product BOM when the schedule
     * has no components yet and is in an editable state.
     */
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

    public function toggleExpand(int $itemId): void
    {
        if (in_array($itemId, $this->expandedItems)) {
            $this->expandedItems = array_values(
                array_filter($this->expandedItems, fn ($id) => $id !== $itemId)
            );
        } else {
            $this->expandedItems[] = $itemId;
        }
    }

    public function saveComponent(
        int $itemId,
        ?string $componentName,
        string $status,
        ?string $supplierName,
        ?string $eta
    ): void {
        if (! $this->canEdit()) {
            return;
        }

        ProductionScheduleComponent::updateOrCreate(
            [
                'production_schedule_id'   => $this->schedule->id,
                'proforma_invoice_item_id' => $itemId,
                'component_name'           => $componentName,
            ],
            [
                'status'        => ComponentStatus::from($status),
                'supplier_name' => $supplierName,
                'eta'           => $eta ?: null,
                'updated_by'    => auth()->id(),
            ]
        );

        $this->schedule->load('components');
        $this->dispatch('component-updated');
    }

    public function deleteComponent(int $componentId): void
    {
        if (! $this->canEdit()) {
            return;
        }

        ProductionScheduleComponent::where('id', $componentId)->delete();
        $this->schedule->load('components');
        $this->dispatch('component-updated');
    }

    public function render(): View
    {
        $this->schedule->load(['proformaInvoice.items.product', 'components']);

        $items = $this->schedule->proformaInvoice->items
            ->map(function ($piItem) {
                $components    = $this->schedule->components
                    ->where('proforma_invoice_item_id', $piItem->id);
                $productLevel  = $components->firstWhere('component_name', null);
                $subComponents = $components->whereNotNull('component_name')->values();

                return [
                    'id'            => $piItem->id,
                    'name'          => $piItem->product?->name ?? $piItem->description ?? '—',
                    'productLevel'  => $productLevel,
                    'subComponents' => $subComponents,
                ];
            })
            ->values();

        return view('livewire.supplier-portal.component-inventory-panel', [
            'items'             => $items,
            'componentStatuses' => ComponentStatus::cases(),
        ]);
    }
}
