<?php

namespace App\Filament\Portal\Widgets;

use App\Domain\Infrastructure\Models\Document;
use App\Domain\Logistics\Models\Shipment;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class RecentDocumentsWidget extends BaseWidget
{
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 3;

    public function getHeading(): string
    {
        return __('widgets.portal.recent_documents');
    }

    public function table(Table $table): Table
    {
        $tenant = Filament::getTenant();
        $companyId = $tenant?->getKey();

        $shipmentIds = Shipment::where('company_id', $companyId)->pluck('id');
        $piIds = ProformaInvoice::where('company_id', $companyId)->pluck('id');

        return $table
            ->query(
                Document::query()
                    ->where(function ($query) use ($shipmentIds, $piIds) {
                        $query->where(function ($q) use ($shipmentIds) {
                            $q->where('documentable_type', Shipment::class)
                                ->whereIn('documentable_id', $shipmentIds);
                        })->orWhere(function ($q) use ($piIds) {
                            $q->where('documentable_type', ProformaInvoice::class)
                                ->whereIn('documentable_id', $piIds);
                        });
                    })
                    ->whereIn('type', [
                        'commercial_invoice_pdf',
                        'packing_list_pdf',
                        'proforma_invoice_pdf',
                    ])
                    ->orderByDesc('created_at')
                    ->limit(10)
            )
            ->columns([
                TextColumn::make('name')
                    ->label(__('widgets.portal.document'))
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(__('widgets.portal.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'commercial_invoice_pdf' => __('widgets.portal.commercial_invoice'),
                        'packing_list_pdf' => __('widgets.portal.packing_list'),
                        'proforma_invoice_pdf' => __('navigation.models.proforma_invoice'),
                        default => $state,
                    }),
                TextColumn::make('created_at')
                    ->label(__('widgets.portal.generated'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
            ])
            ->recordActions([
                \Filament\Actions\Action::make('download')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (Document $record) => route('portal.documents.download', $record))
                    ->openUrlInNewTab()
                    ->visible(fn () => auth()->user()?->can('portal:download-documents')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('widgets.portal.no_documents_yet'))
            ->emptyStateIcon('heroicon-o-document');
    }
}
