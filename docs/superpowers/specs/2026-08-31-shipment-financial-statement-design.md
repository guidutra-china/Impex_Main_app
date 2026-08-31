# Extrato Financeiro do Embarque (Shipment Financial Statement)

Data: 2026-08-31
Status: aprovado, pendente de implementação

## Objetivo

Um documento PDF por embarque, **enviável ao cliente**, que mostra tudo o que ele
deve por aquele embarque: mercadoria embarcada, custos repassados, o cronograma
de pagamento, os pagamentos já recebidos e o saldo em aberto.

Entra como um quarto item do botão **Documents** do `ShipmentResource`, ao lado
de Packing List, Commercial Invoice e Proforma Invoice.

## Público e o que fica de fora

Documento de cliente. **Nenhum número de custo nosso, custo landed ou margem
pode aparecer** — nem no PDF, nem no payload que o alimenta. Ficam de fora, por
decisão:

- custo FOB, custo landed, lucro bruto, margem, margem do frete (isso é o widget
  `LandedCostCalculator`, uso interno);
- as pernas `[forwarder-payable]` e `[supplier-payable]` das parcelas de custo —
  são contas a pagar nossas, não do cliente;
- Credit Notes e Debit Notes;
- lista item a item da mercadoria — isso é a Commercial Invoice.

## Onde os números moram

O cálculo de "quanto deste embarque pertence a cada PI" já existe, privado, em
`ShipmentPaymentSummaryService::shipmentShareByDocument()`. Ele passa a ser
público como `clientShareByProformaInvoice(Shipment): Collection` (PI id => valor
embarcado em unidades menores), e o template consome — junto com a const
`CONDITION_ORDER`, que também vira `public`. Nenhuma terceira implementação da
mesma conta.

O restante o template monta em `getDocumentData()`, seguindo o padrão de
`PaymentStatementPdfTemplate` e `CostStatementPdfTemplate` — que são testados
diretamente, sem passar pelo PDF.

## Componentes

| Arquivo | Papel |
|---|---|
| `app/Domain/Infrastructure/Pdf/Templates/ShipmentFinancialStatementPdfTemplate.php` | monta o payload |
| `resources/views/pdf/shipment-financial-statement.blade.php` | layout, no molde de `pdf/payment-statement.blade.php` |
| `ShipmentPaymentSummaryService::clientShareByProformaInvoice()` | fatia do embarque por PI (extraído do método privado existente) |
| `ShipmentHeaderActions::documentsActionGroup()` | quarto `ActionGroup` |
| `CompanySettings` + `ManageCompanySettings` | chave `email_default_message_financial_statement` |
| `DocumentLabels` | rótulos en / pt_BR / zh_CN |

Do template:

- `getDocumentType()` → `shipment_financial_statement_pdf`
- `getFilename()` → `FS-{reference}.pdf` (ex.: `FS-SH-2026-00041.pdf`), seguindo
  o `PS-`/`CS-` dos extratos existentes
- `getPaper()` → a4, `getOrientation()` → portrait
- locale: `GeneratePdfAction` instancia sempre com `'en'`; o documento não tem
  formulário de opções, igual ao Payment Statement da PI

## Seções do documento

### 1. Cabeçalho

Bloco padrão de empresa/logo do `AbstractPdfTemplate`, bloco do cliente
(`shipment->company`), e um bloco logístico: BL, navio/voo, portos de origem e
destino, ETD/ETA, volumes, peso bruto e CBM.

### 2. Mercadoria embarcada

Uma linha por Proforma Invoice presente no embarque:

| coluna | origem |
|---|---|
| referência | `proforma_invoices.reference` |
| referência do cliente | `proforma_invoices.client_reference` |
| valor embarcado | `clientShareByProformaInvoice()` |

Subtotal ao final. **Sem** abrir item a item.

### 3. Custos repassados

`shipment->additionalCosts` com `billable_to = client`, ordenados por
`cost_date`: tipo, descrição, data, valor em `amount_in_document_currency`.
Subtotal ao final. Custos `company` e `supplier` não aparecem.

### 4. Cronograma de pagamento

Colunas: descrição, PI de origem, vencimento, **valor na PI**, **fatia deste
embarque**, pago, saldo, status.

As linhas vêm de três baldes, todos com `is_credit = false`:

**B1 — parcelas específicas do embarque**
`payable_type = ProformaInvoice`, `shipment_id = shipment.id`,
`source_type IS NULL`.
`valor na PI` = `fatia` = `amount`.

**B2 — parcelas de nível documento das PIs embarcadas**
`payable_type = ProformaInvoice`, `payable_id IN` (PIs do embarque),
`shipment_id IS NULL`, `source_type IS NULL`,
`due_condition IN CalculationBase::documentLevelValues()`.
`valor na PI` = `amount`;
`fatia` = `round(share × percentage / 100)`, mesma fórmula de
`ShipmentPaymentSummaryService::proratedDocumentLevelEntries()`;
`pago da fatia` = `round(fatia × paid_amount / amount)`.
Linhas com `fatia <= 0` são descartadas — é o que mantém fora as parcelas de
custo da própria PI (comissão etc.), que têm `percentage = 0` e `due_condition`
nulo.

**B3 — custos repassados do embarque**
`payable_type = Shipment`, `payable_id = shipment.id`,
`source_type = AdditionalCost`, filtradas por `withoutSideTags()` (remove
forwarder/supplier payable) e restritas a custos com `billable_to = client`.
`PI de origem` sai como `—` (a cobrança é do embarque, não de uma PI) e
`valor na PI` = `fatia` = `amount`.

Linhas `[remaining]` — condição dependente de embarque com `shipment_id` nulo —
ficam fora: são o saldo ainda não embarcado do documento.

**O total da seção soma a coluna `fatia`.**

### 5. Pagamentos recebidos

`PaymentAllocation` cujo `payment_schedule_item_id` está entre as parcelas da
seção 4, com `payment.status = APPROVED` e `credit_schedule_item_id IS NULL`.
Colunas: data, referência do pagamento, método, aplicado a (rótulo da parcela),
valor em `allocated_amount_in_document_currency`. Ordenado por data.

Para parcelas do balde B2, o valor exibido é o alocado cheio (é um pagamento
real, não uma fração) — a fração aparece só na coluna `pago` da seção 4.

### 6. Resumo

Agrupado por `due_condition`, na ordem de
`ShipmentPaymentSummaryService::CONDITION_ORDER` — hoje `private`, passa a
`public` junto com o método de fatia. Colunas: total, pago, saldo. Linhas do
balde B3 têm `due_condition` nulo e caem num grupo "Custos do embarque" ao final.

E o fecho:

- **Total faturado deste embarque** = subtotal da seção 2 + subtotal da seção 3
- **Total em cronograma** = soma da coluna `fatia` da seção 4
- **Pago**
- **Saldo em aberto** = total em cronograma − pago
- **Vencido** = saldo das parcelas com `due_date` no passado e status não resolvido

Quando *Total faturado* e *Total em cronograma* divergirem — cronograma que não
cobre 100%, parcela dispensada — as duas linhas aparecem mesmo assim, com uma
nota. Divergência escondida é pior que divergência visível.

## Moeda

Tudo na moeda do embarque. Se uma PI embarcada tiver moeda diferente — hoje não
ocorre em nenhum dos 23 embarques multi-PI, mas o schema permite — a linha dela
aparece na seção 2 com a própria moeda e a marca *não incluída nos totais*, e
fica de fora de todos os subtotais. Mesmo padrão que o extrato da PI já usa para
Debit Notes em moeda estrangeira (`in_totals` + `.currency-note`). Blocos
separados por moeda seriam mais completos, mas repetiriam as seis seções para um
caso que não existe em produção. **Moedas diferentes nunca são somadas.**

## Wiring no menu Documents

Quarto `ActionGroup` em `ShipmentHeaderActions::documentsActionGroup()`, rótulo
`forms.labels.financial_statement`, ícone `heroicon-o-banknotes`, cor `gray`:

- `GeneratePdfAction::make(ShipmentFinancialStatementPdfTemplate::class)` → `generateFinancialStatementPdf`
- `GeneratePdfAction::download('shipment_financial_statement_pdf')` → `downloadFinancialStatementPdf`
- `GeneratePdfAction::preview(ShipmentFinancialStatementPdfTemplate::class)` → `previewFinancialStatementPdf`
- `SendDocumentByEmailAction::make('shipment_financial_statement_pdf', 'email_default_message_financial_statement')` → `sendFinancialStatementByEmail`

Sem `formSchema` em nenhuma das três primeiras.

A chave de e-mail exige três edições: propriedade
`email_default_message_financial_statement` em `CompanySettings` (o
`SendDocumentByEmailAction` faz `property_exists`, então sem ela a mensagem
padrão sai vazia), `Textarea` correspondente em `ManageCompanySettings`, e as
traduções do rótulo.

## Testes

`tests/Feature/Shipments/ShipmentFinancialStatementPdfTest.php`, no molde de
`tests/Feature/Financial/PaymentStatementPdfTest.php` — asserções sobre o array
de `getData()`, sem renderizar PDF:

1. mercadoria sai com uma linha por PI e o valor embarcado correto num embarque
   de duas PIs;
2. custo `billable_to = company` e `supplier` não aparecem na seção 3 nem na 4;
3. perna `[forwarder-payable]` não aparece;
4. embarque parcial: parcela de nível documento sai com `valor na PI` cheio e
   `fatia` proporcional, e o total usa a fatia;
5. linha `[remaining]` não aparece;
6. pagamento aprovado alocado aparece na seção 5; pagamento pendente não;
7. PI em moeda diferente da do embarque sai marcada fora dos totais e não entra
   em nenhum subtotal;
8. o payload não contém nenhuma chave de custo/margem — asserção explícita de
   ausência, para que uma regressão futura não vaze margem para o cliente.

Um teste de wiring em `tests/Feature/Shipments/` garantindo que as quatro ações
existem no header do `ViewShipment`.
