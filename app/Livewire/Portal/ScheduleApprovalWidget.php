<?php

namespace App\Livewire\Portal;

use App\Domain\Planning\Enums\ProductionScheduleStatus;
use App\Domain\Planning\Models\ProductionSchedule;
use Filament\Notifications\Notification;
use Illuminate\View\View;
use Livewire\Component;

class ScheduleApprovalWidget extends Component
{
    public ProductionSchedule $schedule;
    public string $approvalNote = '';

    public function mount(ProductionSchedule $schedule): void
    {
        $this->schedule = $schedule;
    }

    public function approve(): void
    {
        if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) {
            return;
        }

        $this->schedule->update([
            'status'      => ProductionScheduleStatus::Approved,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        $this->schedule->refresh();

        Notification::make()->success()->title('Schedule approved')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function reject(): void
    {
        if ($this->schedule->status !== ProductionScheduleStatus::PendingApproval) {
            return;
        }

        $this->validate(['approvalNote' => 'required|string|min:5']);

        $this->schedule->update([
            'status'         => ProductionScheduleStatus::Rejected,
            'approved_by'    => auth()->id(),
            'approved_at'    => now(),
            'approval_notes' => $this->approvalNote,
        ]);
        $this->schedule->refresh();

        Notification::make()->warning()->title('Schedule rejected — supplier will be notified')->send();
        $this->dispatch('schedule-status-changed');
    }

    public function render(): View
    {
        $this->schedule->load(['entries.proformaInvoiceItem.product']);

        $entries = $this->schedule->entries;

        $items = $this->schedule->proformaInvoice->items()
            ->with('product')
            ->get()
            ->map(function ($piItem) use ($entries) {
                $itemEntries = $entries->where('proforma_invoice_item_id', $piItem->id);
                $dates       = $itemEntries->pluck('production_date')
                    ->map->format('Y-m-d')
                    ->sort()
                    ->values();
                $quantities = [];
                foreach ($itemEntries as $entry) {
                    $quantities[$entry->production_date->format('Y-m-d')] = $entry->quantity;
                }

                return [
                    'id'          => $piItem->id,
                    'name'        => $piItem->product?->name ?? $piItem->description ?? '—',
                    'pi_quantity' => $piItem->quantity,
                    'dates'       => $dates,
                    'quantities'  => $quantities,
                    'total'       => $itemEntries->sum('quantity'),
                ];
            });

        $allDates = $entries->pluck('production_date')
            ->map->format('Y-m-d')
            ->unique()
            ->sort()
            ->values();

        return view('livewire.portal.schedule-approval-widget', [
            'items'    => $items,
            'allDates' => $allDates,
            'isPending' => $this->schedule->status === ProductionScheduleStatus::PendingApproval,
        ]);
    }
}
