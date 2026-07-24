# Resumo de parcelas pagas por Shipment

**Data:** 2026-07-24
**Contexto:** o usuário precisa ver, em cada embarque, o que já foi pago e o que falta,
seguindo os estágios do payment term (ex. 10/30/60), sem detalhar cada PI/PO.
Superfícies: painel admin (ambos os lados), Portal do cliente (lado cliente),
Supplier Portal (lado do fornecedor logado).

## Núcleo — ShipmentPaymentSummaryService

`App\Domain\Financial\Services\ShipmentPaymentSummaryService`

- `forClient(Shipment $shipment): array` — parcelas payable_type=ProformaInvoice
- `forSupplier(Shipment $shipment, ?int $supplierCompanyId = null): array` — payable_type=PurchaseOrder,
  opcionalmente restrito às POs de um fornecedor (Supplier Portal)

Retorno: seções por moeda, cada uma com linhas de estágio + totais
(amount/paid/remaining/percent_paid). Valores em minor units (padrão do projeto).

### Regras de agregação

1. **Linhas canônicas apenas**: payable PI (cliente) / PO (fornecedor) com
   `shipment_id = embarque`. Linhas espelho payable=Shipment são ignoradas
   (decisão já estabelecida — ver OpenScheduleItemsQuery e obs 744/855).
2. **Agrupamento por `due_condition`** (momento de cobrança), não por percentual
   nominal — PIs/POs com payment terms diferentes no mesmo embarque agregam no
   mesmo evento. `nominal_percentage` só é exibido quando todos os membros do
   grupo têm o mesmo %; caso contrário mostra-se o % efetivo (valor ÷ total).
3. **Estágios document-level rateados**: condições que não são eventos de embarque
   (order_date, po_date, invoice_date, before/after_production) existem uma vez por
   documento (shipment_id NULL). Entram no resumo **rateadas**:
   `slice = % do estágio × valor deste embarque no documento`;
   `paid_slice = slice × (paid_amount/amount do estágio)`. Linha marcada `prorated`.
   Linhas `[remaining]` (condições de embarque com shipment_id NULL) ficam de fora.
4. **Pago = alocação real** via accessor `paid_amount` (inclui espelhos e créditos);
   parcial conta proporcionalmente. `is_credit` fora.
5. Status do grupo: paid (restante ≤ tolerância) / partial / overdue / due / pending;
   vencimento exibido = menor due_date ainda em aberto.
6. Multi-moeda: uma seção por currency_code.

## Apresentação

Uma view Blade compartilhada (`filament.widgets.shipment-payment-progress`) com barra
de progresso, linhas de estágio (ícone status, rótulo, valor pago/total, vencimento)
e rodapé de totais. Três widgets finos:

- Admin `ViewShipment`: `ShipmentPaymentProgressWidget` — blocos cliente + fornecedores
- Portal cliente `ViewShipment`: bloco cliente
- Supplier Portal `ViewShipment`: bloco fornecedor com `supplierCompanyId = auth()->user()->company_id`

## Testes

`tests/Feature/ShipmentPaymentSummaryServiceTest.php`: agrupamento mesmo termo,
termos mistos (nominal % nulo), parcial via alocação aprovada, rateio de estágio
document-level, escopo por fornecedor, espelhos ignorados.
