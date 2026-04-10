<?php

namespace App\Filament\Pages;

use App\Domain\CRM\Models\Company;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use BackedEnum;
use Filament\Actions\Action;
use Livewire\Attributes\Url;

class AdminStatementPreview extends StatementPreview
{
    protected static BackedEnum|string|null $navigationIcon = null;

    protected static ?string $slug = 'statement';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'company')]
    public ?int $companyId = null;

    public function mount(): void
    {
        if ($this->companyId === null) {
            $this->companyId = (int) request()->query('company');
        }

        abort_unless($this->companyId > 0, 404);

        $this->initializeStatement();
    }

    public function getTitle(): string|\Illuminate\Contracts\Support\Htmlable
    {
        return __('statements.title') . ' — ' . $this->resolveCompany()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('filament-panels::resources/pages/view-record.actions.back.label', default: __('statements.actions.back')))
                ->url(CompanyResource::getUrl('view', ['record' => $this->companyId]))
                ->color('gray')
                ->icon('heroicon-o-arrow-left'),
        ];
    }

    protected function resolveCompany(): Company
    {
        return Company::findOrFail($this->companyId);
    }
}
