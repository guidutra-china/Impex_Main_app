<?php

namespace App\Livewire\SupplierPortal;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use App\Domain\Planning\Models\ProductionScheduleEntry;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ProductionScheduleGrid extends Component
{
    public ProductionSchedule $schedule;
    public array $quantities = [];
    public array $dates = [];
    public array $items = [];
    public bool $showAddDate = false;
    public ?string $newDateInput = null;
    public array $riskDates = [];
    public bool $editingMode = false;

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
        $this->loadData();
    }

    public function loadData(): void
    {
        $this->schedule->load(['proformaInvoice.items.product', 'entries', 'components']);

        $this->items = $this->schedule->proformaInvoice->items
            ->map(fn ($item) => [
                'id'          => $item->id,
                'name'        => $item->product?->name ?? $item->description ?? '—',
                'sku'         => $item->product?->sku ?? '',
                'pi_quantity' => $item->quantity,
            ])
            ->values()
            ->toArray();

        $this->quantities = [];
        $dateSet = [];

        foreach ($this->schedule->entries as $entry) {
            $dateKey = $entry->production_date->format('Y-m-d');
            $itemKey = 'item-' . $entry->proforma_invoice_item_id;
            $this->quantities[$itemKey][$dateKey] = $entry->quantity;
            $dateSet[$dateKey] = true;
        }

        $this->dates = array_keys($dateSet);
        sort($this->dates);

        $this->riskDates = $this->schedule->componentRiskDates();
    }

    public function canEdit(): bool
    {
        if ($this->schedule->status->canBeEditedBySupplier()) {
            return true;
        }

        return $this->editingMode && $this->schedule->status->canRequestEdit();
    }

    public function startEditing(): void
    {
        if ($this->schedule->status->canRequestEdit()) {
            $this->editingMode = true;
        }
    }

    public function cancelEditing(): void
    {
        $this->editingMode = false;
        $this->loadData();
    }

    public function updateQuantity(int $itemId, string $date, ?string $value): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $quantity = ($value !== null && $value !== '') ? max(0, (int) $value) : 0;
        $itemKey  = 'item-' . $itemId;

        $this->quantities[$itemKey][$date] = $quantity;

        if ($quantity > 0) {
            ProductionScheduleEntry::updateOrCreate(
                [
                    'production_schedule_id'   => $this->schedule->id,
                    'proforma_invoice_item_id' => $itemId,
                    'production_date'          => $date,
                ],
                ['quantity' => $quantity]
            );
        } else {
            ProductionScheduleEntry::where([
                'production_schedule_id'   => $this->schedule->id,
                'proforma_invoice_item_id' => $itemId,
                'production_date'          => $date,
            ])->delete();
        }
    }

    public function addDate(): void
    {
        if (! $this->canEdit() || ! $this->newDateInput) {
            return;
        }

        if (! in_array($this->newDateInput, $this->dates)) {
            $this->dates[] = $this->newDateInput;
            sort($this->dates);
        }

        $this->newDateInput = null;
    }

    public function removeDate(string $date): void
    {
        if (! $this->canEdit()) {
            return;
        }

        ProductionScheduleEntry::where('production_schedule_id', $this->schedule->id)
            ->whereDate('production_date', $date)
            ->delete();

        $this->dates = array_values(array_filter($this->dates, fn ($d) => $d !== $date));

        foreach ($this->items as $item) {
            unset($this->quantities['item-' . $item['id']][$date]);
        }
    }

    public function submit(): void
    {
        if (! $this->canEdit()) {
            return;
        }

        $errors = [];
        foreach ($this->items as $item) {
            $total = array_sum($this->quantities['item-' . $item['id']] ?? []);
            if ($total < $item['pi_quantity']) {
                $errors[] = "{$item['name']}: planned {$total} / PI qty {$item['pi_quantity']}";
            }
        }

        if (! empty($errors)) {
            Notification::make()
                ->danger()
                ->title('Validation failed — quantities insufficient')
                ->body(implode("\n", $errors))
                ->send();
            return;
        }

        $this->schedule->update([
            'status'       => ProductionScheduleStatus::PendingApproval,
            'submitted_at' => now(),
            'approved_by'  => null,
            'approved_at'  => null,
        ]);
        $this->schedule->refresh();
        $this->editingMode = false;

        Notification::make()->success()->title('Schedule submitted for approval')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function totalsPerDate(): array
    {
        $totals = [];
        foreach ($this->dates as $date) {
            $totals[$date] = 0;
            foreach ($this->items as $item) {
                $totals[$date] += $this->quantities['item-' . $item['id']][$date] ?? 0;
            }
        }
        return $totals;
    }

    public function totalsPerItem(): array
    {
        $totals = [];
        foreach ($this->items as $item) {
            $key = 'item-' . $item['id'];
            $totals[$key] = array_sum($this->quantities[$key] ?? []);
        }
        return $totals;
    }

    public function render(): View
    {
        return view('livewire.supplier-portal.production-schedule-grid', [
            'totalsPerDate' => $this->totalsPerDate(),
            'totalsPerItem' => $this->totalsPerItem(),
        ]);
    }
}
