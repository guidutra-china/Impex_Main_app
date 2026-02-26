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
    protected static ?string $heading = 'Recent Documents';
    protected int|string|array $columnSpan = 'full';
    protected static ?int $sort = 3;

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
                    ->label('Document')
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label('Type')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => match ($state) {
                        'commercial_invoice_pdf' => 'Commercial Invoice',
                        'packing_list_pdf' => 'Packing List',
                        'proforma_invoice_pdf' => 'Proforma Invoice',
                        default => $state,
                    }),
                TextColumn::make('created_at')
                    ->label('Generated')
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
            ->emptyStateHeading('No documents yet')
            ->emptyStateIcon('heroicon-o-document');
    }
}
