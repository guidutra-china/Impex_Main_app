<?php

namespace App\Filament\Resources\CRM\Companies\Pages;

use App\Domain\CRM\Models\CompanyRoleAssignment;
use App\Filament\Pages\Concerns\HasSaveAndReturnFormActions;
use App\Filament\Resources\CRM\Companies\CompanyResource;
use App\Filament\Resources\CRM\Companies\Concerns\ManagesCompanyGallery;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\DB;

class EditCompany extends EditRecord
{
    use HasSaveAndReturnFormActions;
    use ManagesCompanyGallery;

    protected static string $resource = CompanyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ActionGroup::make([
                Action::make('generateStatement')
                    ->label(__('statements.filters.title'))
                    ->icon('heroicon-o-document-text')
                    ->url(fn (): string => url('/panel/statement?company='.$this->record->id)),
                Action::make('financialReport')
                    ->label(__('financial_report.title'))
                    ->icon('heroicon-o-currency-dollar')
                    ->url(fn (): string => url('/panel/financial-report?company='.$this->record->id)),
                Action::make('customFinancialReport')
                    ->label(__('custom_financial_report.title'))
                    ->icon('heroicon-o-document-chart-bar')
                    ->url(fn (): string => url('/panel/custom-financial-report?company='.$this->record->id)),
                Action::make('clientAccountsPayable')
                    ->label(__('client_accounts_payable.title'))
                    ->icon('heroicon-o-clipboard-document-list')
                    ->url(fn (): string => url('/panel/client-accounts-payable?company='.$this->record->id)),
            ])
                ->label(__('custom_financial_report.group_label'))
                ->icon('heroicon-m-document-chart-bar')
                ->button()
                ->color('gray'),
            DeleteAction::make(),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['roles'] = $this->record->companyRoles
            ->pluck('role')
            ->map(fn ($role) => $role->value)
            ->toArray();

        return $this->fillCompanyGallery($data);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['roles'], $data['company_photos']);

        return $data;
    }

    protected function afterSave(): void
    {
        $this->syncCompanyGallery($this->record);

        $roles = $this->data['roles'] ?? [];

        DB::transaction(function () use ($roles) {
            $this->record->companyRoles()->delete();

            if (empty($roles)) {
                return;
            }

            $now = now();

            $assignments = collect($roles)->map(fn ($role) => [
                'company_id' => $this->record->id,
                'role' => $role,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            CompanyRoleAssignment::insert($assignments);
        });
    }
}
