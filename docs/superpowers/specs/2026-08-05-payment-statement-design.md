# Payment Statement (por PI) — Design

**Data:** 2026-08-05
**Status:** Aprovado pelo Gui (abordagem A)

## Objetivo

Relatório PDF por Proforma Invoice, irmão do Cost Statement, mostrando o lado
financeiro para o cliente: cronograma de parcelas, pagamentos recebidos,
débitos (Debit Notes + saldo em aberto) e créditos aplicados, fechando em um
Outstanding Balance.

## Contexto

- O Cost Statement (`CostStatementPdfTemplate`, view `pdf.cost-statement`) já
  cobre itens da PI + additional costs billable ao cliente. O botão de geração
  fica no header do `AdditionalCostsRelationManager`.
- A PI usa `HasPaymentSchedule` (`paymentScheduleItems()` MorphMany). Desde a
  branch `feat/supplier-payable-cost-side`, PSIs side-tagged
  (`[supplier-payable]`, `[forwarder-payable]` no `notes`) moram na própria PI
  — são contas a pagar da Impex e **não podem** aparecer num relatório para o
  cliente. Usar `withoutSideTags()`.
- Debit Notes: `debit_notes.proforma_invoice_id` (raras, mas existem);
  accessors `paid_amount` / `remaining_amount` prontos.

## Decisões (respostas do Gui)

- "Débitos" = **ambos**: Debit Notes vinculadas à PI **e** saldo devedor das
  parcelas.
- Blocos: cronograma de parcelas, pagamentos recebidos, credit notes
  aplicadas, resumo de totais — todos.
- Público: **cliente** → PDF em inglês, mesmo estilo visual do Cost Statement.

## Design

### Geração

- Novo template `App\Domain\Infrastructure\Pdf\Templates\PaymentStatementPdfTemplate`
  (padrão do `CostStatementPdfTemplate`):
  - `getView()` → `pdf.payment-statement`
  - `getDocumentTitle()` → `Payment Statement`
  - `getDocumentType()` → `payment_statement_pdf`
  - `getFilename()` → `PS-{reference}.pdf`
- Botão header **"Payment Statement"** no `PaymentScheduleRelationManager`
  (base em `app/Filament/RelationManagers/`), visível quando o owner é uma
  ProformaInvoice com parcelas não side-tagged; mesma mecânica do botão Cost
  Statement (`PdfGeneratorService::preview` + `streamDownload` inline,
  try/catch com Notification de erro).

### Seleção de dados

- PSIs: `payable = PI`, `withoutSideTags()`. (Não existe status `cancelled`
  em PaymentScheduleStatus — os valores são pending/due/paid/overdue/waived.)
  Parcelas waived aparecem com status "Waived". Parcelas `is_credit` não
  entram no cronograma (aparecem via créditos aplicados).
- Pagamentos: `PaymentAllocation` das PSIs selecionadas (excluindo aplicações
  de crédito) → `payment` (data, referência, método, valor alocado), ordenado
  por data.
- Créditos: `creditAllocations` das PSIs → item de crédito / Credit Note de
  origem, com valor aplicado.
- Debit Notes: `proforma_invoice_id = PI`, `party_type = CLIENT`, excluir
  canceladas. Mostrar `reference`, `issued_at`, `due_date`, total, pago
  (accessor `paid_amount`), saldo (`remaining_amount`), status.

### Seções do PDF (view `pdf.payment-statement`, retrato, inglês)

1. **Header** — PI reference, issue date, client, currency (layout do
   cost-statement.blade.php).
2. **Payment Schedule** — por parcela: stage/label, due date, amount, paid,
   credit applied, balance, status.
3. **Payments Received** — data, payment reference, método, valor alocado.
4. **Debit Notes** — reference, date, due date, total, paid, balance, status.
   DN em moeda ≠ moeda da PI: listada na moeda original com nota
   "not included in totals" — DebitNote não armazena valor convertido
   (diferente de AdditionalCost), e na prática DNs de uma PI compartilham a
   moeda dela. Só DNs na moeda da PI entram no Summary.
5. **Credit Notes Applied** — referência do crédito, data, valor aplicado.
6. **Summary** —
   `PI grand total (mercadoria + client costs)` `+ Debit Notes total`
   `− Payments received` `− Credits applied` `= Outstanding Balance`,
   com linha de destaque para o montante overdue (parcelas/DNs vencidas e não
   quitadas).

### Moeda

Tudo na moeda da PI. PSIs já armazenam `amount` na moeda do documento
(inteiro/minor units — formatar com o helper `Money` como nos demais
templates).

### Traduções

Labels do PDF em inglês hardcoded na view, como o Cost Statement faz hoje
(padrão vigente dos PDFs client-facing). Labels do botão Filament via
`lang/*/forms.php` nos 3 idiomas.

## Testes

Feature test do template (padrão dos testes de PDF existentes):

- PI com parcelas paga/parcial/aberta + alocações → totais e balance corretos.
- PSI side-tagged `[supplier-payable]` **não** aparece.
- Parcela cancelada não aparece; waived aparece como waived.
- DN vinculada entra nos débitos; DN cancelada não.
- Crédito aplicado reduz o balance.
- Header action visível na PI e gera download.

## Fora de escopo

- DNs do cliente não vinculadas à PI (são client-scoped; ficariam num
  statement por empresa, que já existe).
- Versão pt-BR/zh do PDF.
- Qualquer mudança no Cost Statement atual.
