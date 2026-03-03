<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Domain\Infrastructure\Support\Money;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\QuotationStatus;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\Quotations\Models\QuotationItem;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use App\Domain\SupplierQuotations\Models\SupplierQuotationItem;
use App\Filament\Resources\Inquiries\InquiryResource;
use App\Filament\Resources\Quotations\QuotationResource;
use App\Domain\Inquiries\Enums\ProjectTeamRole;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Facades\DB;

class CompareSupplierQuotations extends Page
{
    use InteractsWithRecord;

    protected static string $resource = InquiryResource::class;

    protected string $view = 'filament.resources.inquiries.pages.compare-supplier-quotations';

    protected static ?string $title = 'Compare Supplier Quotations';

    public array $selections = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $this->record->load([
            'items.product',
            'supplierQuotations' => fn ($q) => $q->whereIn('status', [
                SupplierQuotationStatus::RECEIVED,
                SupplierQuotationStatus::UNDER_ANALYSIS,
                SupplierQuotationStatus::SELECTED,
            ])->with(['company', 'items']),
        ]);
    }

    public function getComparisonData(): array
    {
        $inquiry = $this->record;
        $supplierQuotations = $inquiry->supplierQuotations;
        $inquiryItems = $inquiry->items;

        $sqItemsByInquiryItem = [];
        foreach ($supplierQuotations as $sq) {
            foreach ($sq->items as $sqItem) {
                $key = $sqItem->inquiry_item_id ?? $sqItem->product_id;
                $sqItemsByInquiryItem[$key][$sq->id] = $sqItem;
            }
        }

        $rows = [];
        foreach ($inquiryItems as $inquiryItem) {
            $row = [
                'inquiry_item_id' => $inquiryItem->id,
                'product_id' => $inquiryItem->product_id,
                'product_name' => $inquiryItem->product?->name ?? $inquiryItem->description ?? 'Item #' . $inquiryItem->id,
                'quantity' => $inquiryItem->quantity,
                'unit' => $inquiryItem->unit ?? 'pcs',
                'target_price' => $inquiryItem->target_price,
                'suppliers' => [],
            ];

            foreach ($supplierQuotations as $sq) {
                $sqItem = $sqItemsByInquiryItem[$inquiryItem->id][$sq->id]
                    ?? ($inquiryItem->product_id ? ($sqItemsByInquiryItem[$inquiryItem->product_id][$sq->id] ?? null) : null);

                $row['suppliers'][$sq->id] = $sqItem ? [
                    'sq_item_id' => $sqItem->id,
                    'unit_cost' => $sqItem->unit_cost,
                    'total_cost' => $sqItem->total_cost,
                    'moq' => $sqItem->moq,
                    'lead_time_days' => $sqItem->lead_time_days,
                    'has_quote' => $sqItem->unit_cost > 0,
                ] : [
                    'sq_item_id' => null,
                    'unit_cost' => 0,
                    'total_cost' => 0,
                    'moq' => null,
                    'lead_time_days' => null,
                    'has_quote' => false,
                ];
            }

            $rows[] = $row;
        }

        return [
            'supplier_quotations' => $supplierQuotations,
            'rows' => $rows,
        ];
    }

    public function selectSupplier(int $inquiryItemId, int $sqId, int $sqItemId): void
    {
        $this->selections[$inquiryItemId] = [
            'sq_id' => $sqId,
            'sq_item_id' => $sqItemId,
        ];
    }

    public function clearSelection(int $inquiryItemId): void
    {
        unset($this->selections[$inquiryItemId]);
    }

    public function selectBestPrices(): void
    {
        $data = $this->getComparisonData();

        foreach ($data['rows'] as $row) {
            $bestSqId = null;
            $bestSqItemId = null;
            $bestCost = PHP_INT_MAX;

            foreach ($row['suppliers'] as $sqId => $sqData) {
                if ($sqData['has_quote'] && $sqData['unit_cost'] < $bestCost) {
                    $bestCost = $sqData['unit_cost'];
                    $bestSqId = $sqId;
                    $bestSqItemId = $sqData['sq_item_id'];
                }
            }

            if ($bestSqId) {
                $this->selections[$row['inquiry_item_id']] = [
                    'sq_id' => $bestSqId,
                    'sq_item_id' => $bestSqItemId,
                ];
            }
        }
    }

    public function selectAllFromSupplier(int $sqId): void
    {
        $data = $this->getComparisonData();

        foreach ($data['rows'] as $row) {
            $sqData = $row['suppliers'][$sqId] ?? null;
            if ($sqData && $sqData['has_quote']) {
                $this->selections[$row['inquiry_item_id']] = [
                    'sq_id' => $sqId,
                    'sq_item_id' => $sqData['sq_item_id'],
                ];
            }
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('backToInquiry')
                ->label('Back to Inquiry')
                ->icon('heroicon-o-arrow-left')
                ->color('gray')
                ->url(fn () => InquiryResource::getUrl('edit', ['record' => $this->record])),

            Action::make('createQuotationFromSelection')
                ->label('Create Quotation from Selection')
                ->icon('heroicon-o-document-plus')
                ->color('success')
                ->visible(fn () => ! empty($this->selections))
                ->form([
                    Select::make('commission_type')
                        ->label(__('forms.labels.commission_type'))
                        ->options(CommissionType::class)
                        ->default(CommissionType::EMBEDDED->value)
                        ->required(),

                    TextInput::make('commission_rate')
                        ->label(__('forms.labels.default_commission_rate'))
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(100)
                        ->step(0.01)
                        ->suffix('%')
                        ->default(10),
                ])
                ->action(function (array $data) {
                    $this->createQuotationFromSelections($data);
                }),
        ];
    }

    protected function createQuotationFromSelections(array $data): void
    {
        if (empty($this->selections)) {
            Notification::make()
                ->title('No items selected')
                ->body('Please select at least one supplier for each product before creating a quotation.')
                ->warning()
                ->send();

            return;
        }

        try {
            $quotation = DB::transaction(function () use ($data) {
                $inquiry = Inquiry::with(['items.product.clients', 'items.product.suppliers'])
                    ->lockForUpdate()
                    ->findOrFail($this->record->id);

                $commissionType = $data['commission_type'] instanceof CommissionType
                    ? $data['commission_type']
                    : CommissionType::from($data['commission_type']);
                $commissionRate = (float) ($data['commission_rate'] ?? 0);

                $quotation = Quotation::create([
                    'inquiry_id' => $inquiry->id,
                    'company_id' => $inquiry->company_id,
                    'contact_id' => $inquiry->contact_id,
                    'status' => QuotationStatus::DRAFT,
                    'currency_code' => $inquiry->currency_code,
                    'commission_type' => $commissionType,
                    'commission_rate' => $commissionType === CommissionType::SEPARATE ? $commissionRate : 0,
                    'notes' => $inquiry->notes,
                    'responsible_user_id' => $inquiry->getTeamMemberByRole(ProjectTeamRole::SALES)?->id
                        ?? $inquiry->responsible_user_id,
                ]);

                $sortOrder = 0;
                $clientId = $inquiry->company_id;

                foreach ($inquiry->items as $inquiryItem) {
                    $selection = $this->selections[$inquiryItem->id] ?? null;

                    $unitCost = 0;
                    $unitPrice = 0;
                    $selectedSupplierId = null;
                    $sqItemId = null;
                    $itemCommissionRate = 0;

                    if ($selection) {
                        $sqItem = SupplierQuotationItem::with('supplierQuotation')
                            ->find($selection['sq_item_id']);

                        if ($sqItem) {
                            $unitCost = $sqItem->unit_cost;
                            $selectedSupplierId = $sqItem->supplierQuotation->company_id;
                            $sqItemId = $sqItem->id;
                        }
                    }

                    $clientPivot = null;
                    if ($inquiryItem->product_id && $inquiryItem->product) {
                        $clientPivot = $inquiryItem->product->clients()
                            ->where('companies.id', $clientId)
                            ->first()
                            ?->pivot;
                    }

                    if ($clientPivot && $clientPivot->unit_price > 0) {
                        $unitPrice = $clientPivot->unit_price;
                    } elseif ($unitCost > 0 && $commissionType === CommissionType::EMBEDDED && $commissionRate > 0) {
                        $itemCommissionRate = $commissionRate;
                        $unitPrice = (int) round($unitCost * (1 + ($commissionRate / 100)));
                    } elseif ($unitCost > 0) {
                        $unitPrice = $unitCost;
                    }

                    if ($inquiryItem->target_price && $inquiryItem->target_price > 0 && $unitPrice === 0) {
                        $unitPrice = $inquiryItem->target_price;
                    }

                    QuotationItem::create([
                        'quotation_id' => $quotation->id,
                        'product_id' => $inquiryItem->product_id,
                        'supplier_quotation_item_id' => $sqItemId,
                        'quantity' => $inquiryItem->quantity,
                        'selected_supplier_id' => $selectedSupplierId,
                        'unit_cost' => $unitCost,
                        'commission_rate' => $itemCommissionRate,
                        'unit_price' => $unitPrice,
                        'notes' => $inquiryItem->specifications,
                        'sort_order' => $sortOrder++,
                    ]);
                }

                return $quotation;
            });

            Notification::make()
                ->title('Quotation created: ' . $quotation->reference)
                ->body('Items populated from selected supplier quotations.')
                ->success()
                ->send();

            $this->redirect(QuotationResource::getUrl('edit', ['record' => $quotation]));
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Error creating quotation')
                ->body($e->getMessage())
                ->danger()
                ->send();
        }
    }
}
