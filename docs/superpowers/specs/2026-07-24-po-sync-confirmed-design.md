# Sync PI→PO para POs confirmadas

**Data:** 2026-07-24
**Contexto:** PI-2026-00011 / PO-2026-00007 — o Workflow "Generate Purchase Orders" não
atualizava POs com status além de `SENT`, silenciosamente. A edição manual da PO já é
permitida em qualquer status, então o bloqueio era inconsistente. Decisão do Gui:
mudar a regra de negócio, pois PIs são editadas com frequência após a confirmação da PO.

## Regra nova

`GeneratePurchaseOrdersAction::execute(ProformaInvoice $pi, bool $updateConfirmed = false)`

| Status da PO | Sem flag | Com flag `updateConfirmed` |
|---|---|---|
| DRAFT, SENT | sincroniza (comportamento atual) | sincroniza |
| CONFIRMED, IN_PRODUCTION, AWAITING_SHIPMENT | não toca | sincroniza |
| SHIPPED, COMPLETED, CANCELLED | não toca | não toca (nunca) |

## Proteções por item (`syncPoItems`)

1. **Nunca abaixo do alocado em embarques:** se a nova quantidade da PI for menor que a
   soma alocada em embarques **não cancelados** (inclui drafts — reduzir abaixo de uma
   alocação planejada já quebra a integridade embarque↔PO; nota: o accessor
   `quantity_shipped` do model conta só IN_TRANSIT/ARRIVED e não serve aqui), o item é
   **pulado por completo** (nenhum campo atualizado) e registrado em
   `getSkippedShippedItems()` para a notificação.
2. **Guard de deleção ampliado:** cleanup de itens não mais presentes na PI passa a exigir
   `whereDoesntHave('shipmentItems')` **e** `whereDoesntHave('productionScheduleEntries')`
   (FK `production_schedule_entries.purchase_order_item_id` é ON DELETE SET NULL — evita órfãos).
3. Itens manuais da PO (`proforma_invoice_item_id` NULL) continuam intocados.

## UI — modal do Workflow (ProformaInvoiceHeaderActions)

- Descrição do modal ganha um grupo: "**N PO(s) confirmada(s)/em produção** serão
  atualizadas se você marcar a opção abaixo" (lista referência + status).
- Checkbox `update_confirmed_pos` (default off) no form do modal; quando marcado, o action
  roda com `updateConfirmed: true`.
- POs SHIPPED/COMPLETED continuam no grupo "Cannot update".
- Notificação de sucesso: lista POs atualizadas; se houver itens pulados pela proteção de
  embarque, lista-os; quando POs confirmadas foram atualizadas, inclui lembrete para
  verificar os cronogramas de pagamento (não há regeneração automática — o banner de
  schedule stale cobre a detecção).

## Limitações conscientes

- O deleting-hook do `ProformaInvoiceItem` continua removendo PO items apenas de POs
  DRAFT/SENT. Em POs confirmadas, um item deletado da PI vira linha desvinculada na PO
  (preservada, semântica de item manual). Propagar deleção para PO confirmada foi
  considerado arriscado demais neste momento.
- Permissão: mantém `generate-purchase-orders`; sem permissão nova.

## Testes

Extensão de `tests/Feature/GeneratePurchaseOrdersRegenerationTest.php`:

- confirmed sem flag → intocada (pinning do comportamento atual)
- confirmed com flag → sincronizada
- in_production e awaiting_shipment com flag → sincronizadas
- shipped com flag → intocada
- quantidade nova < embarcada → item pulado por completo + reportado
- cleanup não deleta PO item referenciado por production_schedule_entries
