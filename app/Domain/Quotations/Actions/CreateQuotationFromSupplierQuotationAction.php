<?php

namespace App\Domain\Quotations\Actions;

use App\Domain\Quotations\Enums\CommissionType;
use App\Domain\Quotations\Enums\Incoterm;
use App\Domain\Quotations\Models\Quotation;
use App\Domain\SupplierQuotations\Actions\SyncInquiryFromSupplierQuotationAction;
use App\Domain\SupplierQuotations\Enums\SupplierQuotationStatus;
use App\Domain\SupplierQuotations\Models\SupplierQuotation;
use Illuminate\Support\Facades\DB;

class CreateQuotationFromSupplierQuotationAction
{
    /** SQs que ainda valem como fonte de custo para a cotação do cliente. */
    private const QUOTABLE_STATUSES = [
        SupplierQuotationStatus::RECEIVED,
        SupplierQuotationStatus::UNDER_ANALYSIS,
        SupplierQuotationStatus::SELECTED,
    ];

    /** SQs sem preço confirmado: não podem ser fonte de custo, nem mesmo como origem. */
    private const UNQUOTABLE_SOURCE_STATUSES = [
        SupplierQuotationStatus::REQUESTED,
        SupplierQuotationStatus::EXPIRED,
    ];

    public function __construct(
        private readonly SyncInquiryFromSupplierQuotationAction $inquirySync,
        private readonly CreateOrUpdateQuotationFromInquiryAction $quotationBuilder,
    ) {}

    /**
     * Cria (ou atualiza) a Quotation do cliente a partir de uma SupplierQuotation,
     * incluindo os itens que o fornecedor ofereceu por conta própria (opções que
     * não faziam parte do pedido original do cliente).
     *
     * Semânticas não óbvias:
     * - A moeda escolhida (`currencyCode`) nunca reescreve a moeda da Inquiry do
     *   cliente — o pedido original é preservado; a conversão vale só para o
     *   cabeçalho e os itens desta Quotation.
     * - A SQ de origem (`$sq`) sempre vence a eleição de fornecedor por produto,
     *   mesmo quando outra SQ do mesmo pool oferece um custo menor.
     * - `$sq` só avança de status quando está em `RECEIVED`; qualquer outro status
     *   quotável (`UNDER_ANALYSIS`, `SELECTED`, `REJECTED`) permanece como está —
     *   escolher o fornecedor (`SELECTED`) é uma decisão posterior, feita em outro
     *   fluxo.
     * - Campos de cabeçalho opcionais nulos (`contactId`, `incoterm`,
     *   `paymentTermId`) significam "mantém o que já está gravado" — relevante
     *   porque um form do Filament sempre manda o payload inteiro, inclusive os
     *   campos que o usuário não alterou. `validityDays` é a exceção documentada:
     *   nulo assume o padrão de negócio de 30 dias (ver abaixo), nunca "preserva".
     *
     * @throws \InvalidArgumentException quando a SQ de origem ainda não tem preço
     *                                   confirmado (`REQUESTED`/`EXPIRED`) — não faz sentido gerar uma
     *                                   cotação de cliente precificada com um placeholder que o fornecedor
     *                                   nunca chegou a confirmar.
     * @throws \App\Domain\Quotations\Exceptions\QuotationLockedException quando já
     *                                                                    existe uma Quotation travada para esta Inquiry e `forceNewVersion`
     *                                                                    não foi pedido.
     */
    public function execute(
        SupplierQuotation $sq,
        int $companyId,
        ?int $contactId,
        string $currencyCode,
        CommissionType $commissionType,
        float $commissionRate,
        bool $showSuppliers = false,
        ?Incoterm $incoterm = null,
        ?int $paymentTermId = null,
        ?int $validityDays = null,
        bool $forceNewVersion = false,
    ): Quotation {
        if (in_array($sq->status, self::UNQUOTABLE_SOURCE_STATUSES, true)) {
            throw new \InvalidArgumentException(
                'Cotação de fornecedor sem preços confirmados não pode gerar cotação ao cliente.',
            );
        }

        // Prazo de validade padrão do negócio: sem isto, o hook `creating` da
        // Quotation não calcula `valid_until` (o default de coluna preenche
        // `validity_days` só depois do hook rodar) e a cotação nasceria sem
        // data de validade.
        $validityDays ??= 30;

        return DB::transaction(function () use (
            $sq, $companyId, $contactId, $currencyCode, $commissionType, $commissionRate,
            $showSuppliers, $incoterm, $paymentTermId, $validityDays, $forceNewVersion
        ) {
            $inquiry = $this->inquirySync->execute(
                sq: $sq,
                companyId: $companyId,
                contactId: $contactId,
                currencyCode: $currencyCode,
            );

            $supplierQuotationIds = $inquiry->supplierQuotations()
                ->whereIn('status', array_map(
                    fn (SupplierQuotationStatus $status) => $status->value,
                    self::QUOTABLE_STATUSES,
                ))
                ->pluck('id')
                ->push($sq->id)
                ->unique()
                ->values()
                ->all();

            $quotation = $this->quotationBuilder->execute(
                inquiry: $inquiry->load('items'),
                supplierQuotationIds: $supplierQuotationIds,
                commissionType: $commissionType,
                commissionRate: $commissionRate,
                showSuppliers: $showSuppliers,
                forceNewVersion: $forceNewVersion,
                incoterm: $incoterm,
                preferredSupplierQuotationId: $sq->id,
                currencyCode: $currencyCode,
            );

            $header = [];

            if ($paymentTermId !== null) {
                $header['payment_term_id'] = $paymentTermId;
            }

            // Sempre gravados juntos (nunca condicionados a um valor explícito do
            // chamador): `validityDays` já tem o default de 30 aplicado acima.
            $header['validity_days'] = $validityDays;
            $header['valid_until'] = today()->addDays($validityDays);

            $quotation->update($header);

            // Esta transição precisa ser a ÚLTIMA escrita do closure: TransitionStatusAction
            // dispara auto-advances best-effort logo depois de mudar o status da SQ, ainda
            // dentro desta mesma transação (savepoint aninhado, não commit real). Deixando a
            // transição por último garantimos que qualquer auto-advance que leia a Quotation
            // a encontre já completa (cabeçalho + itens); e como falhas de auto-advance são
            // só reportadas, nunca propagadas, isso nunca é o passo que aborta a transação.
            if ($sq->status === SupplierQuotationStatus::RECEIVED) {
                $sq->transitionTo(
                    SupplierQuotationStatus::UNDER_ANALYSIS,
                    'Cotação ao cliente '.$quotation->reference.' gerada a partir desta SQ.',
                );
            }

            return $quotation->fresh(['items.suppliers']);
        });
    }
}
