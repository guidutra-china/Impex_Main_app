<?php

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Support\Facades\DB;

class SyncInquiryFromSupplierQuotationAction
{
    public function __construct(
        private readonly CreateDraftProductForSupplierAction $productCreator = new CreateDraftProductForSupplierAction,
    ) {}

    /**
     * Garante que exista uma Inquiry do cliente contendo os itens desta SQ —
     * inclusive as opções que o fornecedor ofereceu por conta própria, que não
     * faziam parte do pedido original.
     */
    public function execute(
        SupplierQuotation $sq,
        int $companyId,
        ?int $contactId,
        string $currencyCode,
    ): Inquiry {
        return DB::transaction(function () use ($sq, $companyId, $contactId, $currencyCode) {
            $inquiry = $this->resolveInquiry($sq, $companyId, $contactId, $currencyCode);

            $this->syncItems($sq, $inquiry);

            if ($inquiry->status === InquiryStatus::RECEIVED) {
                $inquiry->transitionTo(
                    InquiryStatus::QUOTING,
                    'Itens da cotação de fornecedor '.$sq->reference.' sincronizados.',
                );
            }

            return $inquiry->fresh('items');
        });
    }

    private function resolveInquiry(
        SupplierQuotation $sq,
        int $companyId,
        ?int $contactId,
        string $currencyCode,
    ): Inquiry {
        $existing = $sq->inquiry;

        if ($existing && $existing->company_id === $companyId) {
            return $existing;
        }

        $inquiry = Inquiry::create([
            'description' => $sq->description ?: 'Itens de '.$sq->reference,
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'status' => InquiryStatus::RECEIVED,
            'source' => InquirySource::OTHER,
            'currency_code' => $currencyCode,
            'received_at' => today(),
        ]);

        // A SQ só é reamarrada quando ainda não pertencia a inquiry nenhuma:
        // roubar o vínculo apagaria o histórico do cliente original.
        if ($sq->inquiry_id === null) {
            $sq->update(['inquiry_id' => $inquiry->id]);
            $sq->setRelation('inquiry', $inquiry);
        }

        return $inquiry;
    }

    private function syncItems(SupplierQuotation $sq, Inquiry $inquiry): void
    {
        $existingProductIds = $inquiry->items()->pluck('product_id')->filter()->all();
        $nextSortOrder = (int) $inquiry->items()->max('sort_order') + 1;

        foreach ($sq->items()->orderBy('sort_order')->orderBy('id')->get() as $sqItem) {
            $product = $sqItem->product;

            if ($product === null) {
                $product = $this->productCreator->execute(
                    description: (string) ($sqItem->description ?: 'Item '.$sqItem->id.' de '.$sq->reference),
                    supplier: $sq->company,
                );

                // Backfill: sem isto, reexecutar a ação criaria um produto novo
                // a cada rodada para o mesmo item do fornecedor.
                $sqItem->update(['product_id' => $product->id]);
            }

            if (in_array($product->id, $existingProductIds, true)) {
                continue;
            }

            InquiryItem::create([
                'inquiry_id' => $inquiry->id,
                'product_id' => $product->id,
                // `description` é varchar(255) em inquiry_items; SQ item description
                // permite até 500 — sem truncar aqui o insert quebra.
                'description' => $sqItem->description !== null ? mb_substr($sqItem->description, 0, 255) : null,
                'quantity' => max(1, (int) $sqItem->quantity),
                'unit' => $sqItem->unit ?: 'pcs',
                'specifications' => $sqItem->specifications,
                'sort_order' => $nextSortOrder++,
            ]);

            $existingProductIds[] = $product->id;
        }
    }
}
