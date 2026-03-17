<?php

namespace App\Filament\Portal\Resources\PaymentResource\Pages;

use App\Domain\Financial\Enums\PaymentScheduleStatus;
use App\Domain\Financial\Enums\PaymentStatus;
use App\Domain\Financial\Models\PaymentScheduleItem;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use App\Filament\Portal\Resources\PaymentResource;
use App\Filament\Portal\Widgets\PaymentsListStats;
use Filament\Facades\Filament;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;

class ListPayments extends ListRecords
{
    protected static string $resource = PaymentResource::class;

    protected static string $view = 'portal.pages.list-payments';

    public string $activeTab = 'payments';

    protected function getHeaderWidgets(): array
    {
        return [
            PaymentsListStats::class,
        ];
    }

    public function getTitle(): string|Htmlable
    {
        return __('navigation.resources.payments');
    }

    public function table(Table $table): Table
    {
        if ($this->activeTab === 'schedule') {
            return $this->scheduleTable($table);
        }

        return parent::table($table);
    }

    protected function scheduleTable(Table $table): Table
    {
        $tenant = Filament::getTenant();

        return $table
            ->query(
                PaymentScheduleItem::query()
                    ->with(['payable', 'paymentTermStage'])
                    ->whereHasMorph('payable', [ProformaInvoice::class], function ($query) use ($tenant) {
                        $query->where('company_id', $tenant->id);
                    })
            )
            ->columns([
                TextColumn::make('payable_document')
                    ->label(__('forms.labels.document'))
                    ->state(function ($record) {
                        $payable = $record->payable;
                        if (! $payable) {
                            return '—';
                        }
                        $type = class_basename($payable);
                        $ref = $payable->reference ?? '—';

                        return "{$type}: {$ref}";
                    }),
                TextColumn::make('label')
                    ->label(__('forms.labels.label'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('percentage')
                    ->label(__('forms.labels.percent'))
                    ->suffix('%')
                    ->alignCenter()
                    ->sortable(),
                TextColumn::make('amount')
                    ->label(__('forms.labels.amount'))
                    ->formatStateUsing(fn ($state, $record) => ($record->currency_code ?? '') . ' ' . Money::format($state, 2))
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('due_condition')
                    ->label(__('forms.labels.condition'))
                    ->badge()
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('forms.labels.due_date'))
                    ->date('d/m/Y')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('status')
                    ->label(__('forms.labels.status'))
                    ->badge()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(PaymentScheduleStatus::class),
            ])
            ->recordUrl(null)
            ->recordActions([]);
    }

    public function switchTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resetTable();
    }
}
