<?php

namespace App\Livewire\Admin;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ProductionActualsGrid extends Component
{
    public ProductionSchedule $schedule;

    public array $actuals = [];

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->loadActuals();
    }

    public function loadActuals(): void
    {
        $this->schedule->load('entries');

        foreach ($this->schedule->entries as $entry) {
            $dateKey = $entry->production_date->format('Y-m-d');
            $itemKey = 'item-' . $entry->proforma_invoice_item_id;
            $this->actuals[$itemKey][$dateKey] = $entry->actual_quantity;
        }
    }

    public function isVisible(): bool
    {
        // Grid is always visible for admin — shows planned data in all statuses
        return true;
    }

    public function canEditActuals(): bool
    {
        return in_array($this->schedule->status, [
            ProductionScheduleStatus::Approved,
            ProductionScheduleStatus::Completed,
        ]);
    }

    public function updateActual(int $itemId, string $date, ?string $value): void
    {
        if (! $this->isVisible()) {
            return;
        }

        $quantity = ($value !== null && $value !== '') ? max(0, (int) $value) : null;
        $itemKey = 'item-' . $itemId;

        $this->actuals[$itemKey][$date] = $quantity;

        ProductionScheduleEntry::where('production_schedule_id', $this->schedule->id)
            ->where('proforma_invoice_item_id', $itemId)
            ->whereDate('production_date', $date)
            ->update(['actual_quantity' => $quantity]);

        $this->checkAutoComplete();
    }

    private function checkAutoComplete(): void
    {
        $this->schedule->refresh();
        $totalPlanned = $this->schedule->entries->sum('quantity');
        $totalActual = $this->schedule->entries->sum(fn ($e) => $e->actual_quantity ?? 0);

        if ($totalPlanned > 0 && $totalActual >= $totalPlanned && $this->schedule->status === ProductionScheduleStatus::Approved) {
            $this->schedule->update(['status' => ProductionScheduleStatus::Completed]);
            $this->schedule->refresh();
            Notification::make()->success()->title('Production complete — schedule marked as completed')->send();
        }
    }

    public function render(): View
    {
        $this->schedule->load(['proformaInvoice.items.product', 'entries']);

        $today = now()->format('Y-m-d');

        $items = $this->schedule->proformaInvoice->items
            ->map(fn ($piItem) => [
                'id'          => $piItem->id,
                'name'        => $piItem->product?->name ?? $piItem->description ?? '—',
                'sku'         => $piItem->product?->sku ?? '',
                'pi_quantity' => $piItem->quantity,
            ])
            ->values()
            ->toArray();

        $dates = $this->schedule->entries
            ->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values()
            ->toArray();

        $planned = [];
        foreach ($this->schedule->entries as $entry) {
            $planned['item-' . $entry->proforma_invoice_item_id][$entry->production_date->format('Y-m-d')] = $entry->quantity;
        }

        return view('livewire.admin.production-actuals-grid', [
            'items'          => $items,
            'dates'          => $dates,
            'planned'        => $planned,
            'today'          => $today,
            'isVisible'      => $this->isVisible(),
            'canEditActuals' => $this->canEditActuals(),
        ]);
    }
}
