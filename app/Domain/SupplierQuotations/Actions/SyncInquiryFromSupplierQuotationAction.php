<?php

namespace App\Domain\SupplierQuotations\Actions;

use App\Domain\Catalog\Actions\CreateDraftProductForSupplierAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\Inquiries\Enums\InquirySource;
use App\Domain\Inquiries\Enums\InquiryStatus;
use App\Domain\Inquiries\Models\Inquiry;
use App\Domain\Inquiries\Models\InquiryItem;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Support\Collection;
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

        // A inquiry gerada por esta SQ para este cliente é reencontrada nas rodadas
        // seguintes; sem isto, cada clique criaria uma inquiry (e uma cadeia de
        // cotações) nova para o mesmo cliente.
        $fromThisSq = Inquiry::query()
            ->where('source_supplier_quotation_id', $sq->id)
            ->where('company_id', $companyId)
            ->latest('id')
            ->first();

        if ($fromThisSq) {
            return $fromThisSq;
        }

        $inquiry = Inquiry::create([
            'description' => $sq->description ?: 'Itens de '.$sq->reference,
            'company_id' => $companyId,
            'contact_id' => $contactId,
            'status' => InquiryStatus::RECEIVED,
            'source' => InquirySource::OTHER,
            'currency_code' => $currencyCode,
            'received_at' => today(),
            'source_supplier_quotation_id' => $sq->id,
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
        // Linhas existentes com product_id nulo (texto livre) ficam de fora desta
        // lista: casar por descrição com uma InquiryItem manual seria frágil
        // demais. Se a SQ trouxer o mesmo item sem produto, a linha pode
        // duplicar — aceito conscientemente.
        $existingProductIds = $inquiry->items()->pluck('product_id')->filter()->all();

        $maxSortOrder = $inquiry->items()->max('sort_order');
        $nextSortOrder = $maxSortOrder === null ? 0 : (int) $maxSortOrder + 1;

        $sqItems = $sq->items()->orderBy('sort_order')->orderBy('id')->get();
        $productsById = $this->resolveProducts($sq, $sqItems);

        // Duas linhas da SQ podem apontar para o mesmo produto (mesmo catálogo ou
        // mesmo rascunho recém-criado por descrição idêntica) — mantém uma linha
        // por produto na Inquiry, elegendo a de menor unit_cost, na mesma regra
        // usada pela Quotation (CreateOrUpdateQuotationFromInquiryAction). Linha
        // sem preço (unit_cost <= 0, inclusive o default da coluna) é demovida,
        // não excluída: só vence se for a ÚNICA oferta para aquele produto —
        // senão um produto sem nenhum preço preenchido sumiria da Inquiry.
        $elected = $sqItems
            ->groupBy(fn ($sqItem) => $productsById[$sqItem->id]->id)
            ->map(fn ($group) => $group->sortBy(fn ($item) => [$item->unit_cost <= 0 ? 1 : 0, $item->unit_cost])->first());

        foreach ($elected as $productId => $sqItem) {
            if (in_array($productId, $existingProductIds, true)) {
                continue;
            }

            InquiryItem::create([
                'inquiry_id' => $inquiry->id,
                'product_id' => $productId,
                // `description` é varchar(255) em inquiry_items; SQ item description
                // permite até 500 — sem truncar aqui o insert quebra.
                'description' => $sqItem->description !== null ? mb_substr($sqItem->description, 0, 255) : null,
                'quantity' => max(1, (int) $sqItem->quantity),
                'unit' => $sqItem->unit ?: 'pcs',
                'specifications' => $sqItem->specifications,
                // `notes` do item da SQ NÃO é copiado: é anotação do fornecedor,
                // não do cliente — não faz sentido aparecer na Inquiry.
                'sort_order' => $nextSortOrder++,
            ]);

            $existingProductIds[] = $productId;
        }
    }

    /**
     * Resolve o Product de cada SupplierQuotationItem, criando rascunhos para os
     * que ainda não têm produto e fazendo o backfill de product_id.
     *
     * @param  Collection<int,\App\Domain\SupplierQuotations\Models\SupplierQuotationItem>  $sqItems
     * @return array<int,Product> chave = supplier_quotation_item.id
     */
    private function resolveProducts(SupplierQuotation $sq, Collection $sqItems): array
    {
        // Busca em lote (inclusive soft-deleted): um produto vinculado que foi
        // removido não pode ser silenciosamente substituído por um rascunho novo
        // — isso apagaria o vínculo real com uma linha "órfã".
        $existingIds = $sqItems->pluck('product_id')->filter()->unique()->values();
        $products = $existingIds->isEmpty()
            ? collect()
            : Product::withTrashed()->whereIn('id', $existingIds)->get()->keyBy('id');

        $resolved = [];
        // Produtos rascunho recém-criados nesta rodada, por descrição normalizada
        // — evita mintar dois produtos para duas linhas idênticas sem product_id.
        $draftsByDescription = [];

        foreach ($sqItems as $sqItem) {
            if ($sqItem->product_id !== null) {
                $resolved[$sqItem->id] = $products[$sqItem->product_id];

                continue;
            }

            $key = mb_strtolower(trim((string) $sqItem->description));

            if ($key !== '' && isset($draftsByDescription[$key])) {
                $product = $draftsByDescription[$key];
            } else {
                $product = $this->productCreator->execute(
                    description: (string) ($sqItem->description ?: 'Item '.$sqItem->id.' de '.$sq->reference),
                    supplier: $sq->company,
                );

                if ($key !== '') {
                    $draftsByDescription[$key] = $product;
                }
            }

            // Backfill: sem isto, reexecutar a ação criaria um produto novo a
            // cada rodada para o mesmo item do fornecedor.
            $sqItem->update(['product_id' => $product->id]);
            $resolved[$sqItem->id] = $product;
        }

        return $resolved;
    }
}
