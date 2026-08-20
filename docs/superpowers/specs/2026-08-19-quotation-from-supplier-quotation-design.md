# Quotation ao cliente direto da Supplier Quotation

**Data:** 2026-08-19 (executado 2026-08-19/20)
**Base:** main pós bank-fee-on-payment (`35804d0c`+)
**Escopo:** ação na Supplier Quotation que gera (ou atualiza) a Quotation do cliente com **todos** os itens da SQ — inclusive os que o fornecedor ofereceu por conta própria e que não estavam na Inquiry — com as opções gerais da cotação (comissão, moeda, incoterm, condição de pagamento, validade, exibição de fornecedores) definidas no próprio modal.

## Contexto / motivação

O fluxo é Inquiry → SQ → Quotation, e a geração da Quotation era **dirigida pela Inquiry**: `CreateOrUpdateQuotationFromInquiryAction::syncItems()` itera `$inquiry->items` e, ao final, apaga os `QuotationItem` cujo produto não está mais na Inquiry. Consequência prática: quando o fornecedor mandava opções extras (produto novo, variante, upsell), **não existia caminho** para levar isso ao cliente sem editar a Inquiry na mão, item a item.

Estado verificado no código e no banco de dev em 2026-08-19:

- `quotations.inquiry_id` é nullable (27 quotations, todas com inquiry).
- `quotation_items.product_id` é **NOT NULL**; `supplier_quotation_items.product_id` é nullable — **182 de 1.780** itens de SQ sem produto (resíduo das importações por IA).
- `supplier_quotation_items` **não** guarda part number: só `description`, `specifications`, `quantity`, `unit`, `unit_cost`, `moq`, `lead_time_days`.
- 2 de 116 SQs não têm `inquiry_id`.
- 134 de 1.780 itens de SQ têm `unit_cost <= 0`, e 34 pares `(sq, product)` já têm mais de uma linha.

## Decisões confirmadas (Gui, 2026-08-19)

1. **Sincroniza a Inquiry** em vez de criar quotation solta: os itens extras do fornecedor entram na Inquiry antes de gerar a Quotation; se a SQ não tem Inquiry, uma é criada para o cliente escolhido. A cadeia Inquiry → Quotation → PI fica íntegra e o `regenerate` existente para de apagar item.
2. **Todos os itens entram**, sem seleção no modal; o ajuste fino é no Items Relation Manager da Quotation.
3. **Item sem produto vira produto rascunho automaticamente.**
4. **A SQ de origem manda no custo**; as demais SQs da Inquiry entram como alternativas (`quotation_item_suppliers`), preservando comparação e "promover fornecedor".
5. **Quotation existente:** DRAFT é mesclada; travada (SENT+) exige o toggle "criar nova versão", com snapshot.
6. **Modal traz:** cliente/contato, comissão (tipo + taxa), moeda, incoterm, condição de pagamento, validade e `show_suppliers`. Descrição e notas ficam para o form da Quotation.
7. **Transições automáticas conservadoras:** Inquiry `RECEIVED → QUOTING` e SQ `RECEIVED → UNDER_ANALYSIS`. A SQ **não** vai para `SELECTED`.
8. Abordagem rejeitada: action independente que recria conversão de FX, comissão, versionamento e alternativas.

## Arquitetura entregue

### 1. `Catalog\Actions\CreateDraftProductForSupplierAction`

`execute(string $description, Company $supplier, ?string $externalCode = null, ?int $categoryId = null): Product` — produto `status=draft`, nome truncado em 250, SKU draft, e pivot `role=supplier`. Extraída de `ImportSupplierQuotationAction`, que passou a chamá-la: uma única forma de nascer produto vindo de fornecedor.

`linkSupplier()` escreve por `$product->suppliers()->updateExistingPivot(...)`. **Não** use `$existing->pivot->update()`: `companies()` não lista `id` no `withPivot()`, então o update cai em `WHERE product_id AND company_id` e atinge também a linha `client` da mesma empresa. Bug real, provado por execução, que existia no import.

Dedup de `reference_code` (UNIQUE) é responsabilidade do chamador — o import faz a busca em `resolveProduct()`.

### 2. `SupplierQuotations\Actions\SyncInquiryFromSupplierQuotationAction`

`execute(SupplierQuotation $sq, int $companyId, ?int $contactId, string $currencyCode): Inquiry`

- **Reuso da Inquiry:** a da SQ quando pertence ao cliente escolhido; senão a que esta SQ já gerou para esse cliente (`inquiries.source_supplier_quotation_id`); senão cria. Exposto como `findExistingInquiry()` para a UI usar a mesma regra.
- **Proveniência (`source_supplier_quotation_id`, coluna nova):** sem ela a ação não é idempotente no caso "cliente diferente" — o vínculo original da SQ não pode ser roubado, então nada registraria a Inquiry criada e cada clique geraria outra Inquiry e outra cadeia de Quotation na versão 1.
- **Itens:** cria `InquiryItem` só para produto ainda ausente, copiando `quantity`, `unit`, `description` (truncada em 255), `specifications`. Mesmo produto em várias linhas da SQ: vence a de **menor `unit_cost`**, com linha sem preço (`<= 0`) demovida — só entra se for a única oferta.
- **Itens sem produto** são deduplicados por descrição (trim, case-insensitive) antes de criar o rascunho.
- **Produto soft-deleted:** decide por `$sqItem->product_id === null`, não `$product === null` — o `belongsTo` esconde o produto na lixeira, e substituí-lo destruiria o vínculo real.
- **Backfill do `product_id`** no item da SQ, senão reexecutar cria produto novo a cada rodada.
- **Nunca remove nem reescreve item existente** da Inquiry: a quantidade pedida é do cliente.

### 3. `CreateOrUpdateQuotationFromInquiryAction` — dois parâmetros novos

- `?int $preferredSupplierQuotationId` — a SQ de origem vence a eleição quando cota o item (`unit_cost > 0`); senão, menor custo. Lança `InvalidArgumentException` se o id preferido não estiver na lista considerada.
- `?string $currencyCode` — moeda do cabeçalho, aplicada **antes** do FX dos itens; a Inquiry nunca é reescrita. Rejeita moeda inexistente ou inativa (sem isso o resolver caía para taxa 1.0 em silêncio).

**Comissão ao reexecutar:** mudar taxa **ou tipo** no cabeçalho vale como "aplicar em toda a cotação" e os itens são repreçados; manter ambos preserva ajustes item a item. Sem a parte do tipo, `SEPARATE → EMBEDDED` com a mesma taxa apagava a margem inteira em silêncio. A regra **não** alcança o modal antigo da Inquiry (lá os `itemOverrides` sobrescrevem a decisão depois).

### 4. `Quotations\Actions\CreateQuotationFromSupplierQuotationAction` (orquestrador)

Uma `DB::transaction`: sincroniza a Inquiry → monta as SQs cotáveis da Inquiry sempre incluindo a de origem → delega ao construtor com `preferredSupplierQuotationId` e `currencyCode` → aplica `payment_term_id` e `validity_days` (recalculando `valid_until`) → avança a SQ. A transição da SQ é sempre a **última** escrita: é isso que mantém inofensivo o auto-advance do `TransitionStatusAction`, que engole `Throwable`.

**Status da SQ de origem:** allow-list `RECEIVED`, `UNDER_ANALYSIS`, `SELECTED`, `REJECTED`, exposta como `canBeSource()` e usada também pela UI. `REQUESTED`/`EXPIRED` são recusados no domínio, não só escondidos na tela — preço não confirmado não vira cotação ao cliente. `REJECTED` segue permitido: retomar SQ rejeitada é caso de uso real.

### 5. UI — `SupplierQuotationHeaderActions::workflowActionGroup()`

`Action::make('createClientQuotation')`, visível quando `canBeSource()` e há item com `unit_cost > 0`. Modal com cliente/contato, comissão, moeda (só ativas), incoterm, condição de pagamento, validade, `show_suppliers` e `force_new_version`.

Dois aprendizados que valem para o projeto inteiro:

- **Toggle escondido não é desidratado** (`HasState::isDehydrated()` → `Arr::forget`). O `force_new_version` era descartado do payload mesmo quando enviado. Qualquer campo escondido-porém-necessário tem essa armadilha.
- **`$action->halt()` nos catch**, senão o Filament considera a ação bem-sucedida, desmonta o modal e o trader perde os dez campos.

Mensagens de erro vão para o toast traduzidas; a exceção crua vai para o log.

## Casos de borda conhecidos e aceitos

- **Item manual da Inquiry sem `product_id`:** a descrição acaba duplicada como linha nova de rascunho — o dedup compara por produto, e casar linha manual por descrição é frágil demais.
- **Duas linhas da SQ com a mesma descrição e produtos diferentes** colapsam num produto só; incluir `specifications` na chave quebraria o dedup de faixas de MOQ.
- **Quantidade da faixa eleita:** eleger a linha de menor custo importa a quantidade dela. Faixa mais barata com MOQ 500 faz a Inquiry dizer 500. **Pendente de confirmação do Gui.**
- **Incoterm e condição de pagamento do cliente** vêm pré-preenchidos com os termos **do fornecedor** na SQ. Aprovado no desenho do modal, mas para trading eles costumam divergir. **Pendente de confirmação do Gui.**

## Fora de escopo

- Mesclar/deduplicar produtos parecidos (merge tool do plano de duplicidades).
- Alterar a página `CompareSupplierQuotations`.
- Seleção item a item no modal.
- Objeto de opções para as assinaturas (o construtor tem 10 parâmetros, o orquestrador 11; todos os chamadores usam argumentos nomeados). Recomendação da revisão: quando `QuotationBuildOptions` nascer, o orquestrador recebe o mesmo objeto em vez de um segundo DTO.
