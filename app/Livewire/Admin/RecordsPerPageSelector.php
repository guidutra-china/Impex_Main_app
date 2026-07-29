<?php

namespace App\Livewire\Admin;

use Illuminate\View\View;
use Livewire\Component;

class RecordsPerPageSelector extends Component
{
    /** @var list<int> */
    public const OPTIONS = [10, 25, 50, 100];

    public int $recordsPerPage = 25;

    public function mount(): void
    {
        $this->recordsPerPage = auth()->user()?->records_per_page ?? 25;
    }

    public function updatedRecordsPerPage(int $value): void
    {
        if (! in_array($value, self::OPTIONS, true)) {
            $this->recordsPerPage = 25;

            return;
        }

        auth()->user()?->update(['records_per_page' => $value]);

        // Reload so every table on the current page picks up the new default.
        $this->js('window.location.reload()');
    }

    public function render(): View
    {
        return view('livewire.admin.records-per-page-selector');
    }
}
