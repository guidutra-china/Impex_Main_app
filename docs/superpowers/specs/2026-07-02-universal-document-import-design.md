# Import universal de documentos via IA — destino escolhido após o upload

**Data:** 2026-07-02
**Escopo:** generalizar o pipeline de import do Assistant (hoje hard-coded para SupplierQuotation) em torno de um contrato `ImportTarget`, adicionar o destino **Inquiry** (nova ou existente), classificação automática do documento com confirmação do usuário, e um atalho "Importar com IA" dentro do módulo Inquiries.

## Contexto

- **Inquiries → Items → Import from Excel** (`app/Filament/Actions/PasteItemsFromSpreadsheetAction.php`): wizard determinístico (upload → mapear colunas → preview → confirmar), só xlsx/xls/csv, sem IA. Não aceita PDF por depender de colunas. **Continua existindo, intocado.**
- **Import AI no Assistant** (`app/Filament/Pages/Concerns/HandlesSupplierQuotationImport.php` + `app/Domain/AI/Import/*`): pipeline maduro — upload (xlsx/xls/pdf) → `DocumentExtractor` (content blocks) + `DocumentImageExtractor` (fotos) → `SupplierQuotationExtractor` (tool forçada, schema) → `ResolveSupplierQuotationDraft` (match determinístico) → revisão editável inline (form + pool de fotos) → chat-edit (`EditSupplierQuotationDraft`) → Confirmar → `ImportSupplierQuotationAction` (transação + gates shield). O spec de 2026-06-24 já listava "outros alvos (Inquiry/PI direto)" como próxima leva.
- Modelo `Inquiry`: `HasReference` (referência auto), `HasDocuments` (anexos), `HasStateMachine`; `company_id` = cliente, `contact_id`, `currency_code`, `deadline`, `notes`, status inicial RECEIVED. `InquiryItem`: `product_id` (nullable), `description`, `quantity`, `unit`, `target_price` (int centavos), `specifications`, `notes`, `sort_order`.

## Decisões (aprovadas no brainstorming)

- **Destinos v1:** Inquiry + SupplierQuotation (SQ refatorado para o novo contrato, comportamento idêntico).
- **Modo Inquiry:** ambos — criar Inquiry nova **ou** adicionar itens a uma Inquiry existente (usuário escolhe na revisão).
- **Ponto de entrada extra:** botão "Importar com IA" na tela de itens da Inquiry, abrindo o Assistant com o destino já travado naquela Inquiry.
- **Escolha do destino:** IA sugere (classificação barata pós-upload), usuário confirma ou troca antes da extração completa.
- **Abordagem:** A — generalizar via `ImportTarget` (rejeitadas: B pipeline paralelo duplicado; C fluxo agêntico com escrita pelo LLM, inseguro).
- **Inquiry não cria produtos:** produtos são casados por `reference_code`/`model_number` só para vincular; sem match → `product_id` null + descrição (paridade com o Import from Excel). Fotos ficam fora do destino Inquiry na v1.

## Componentes

### 1. Contrato e registry (`app/Domain/AI/Import/Targets/`)

- **`ImportTarget`** (interface): `key()`, `label()`, tool de extração (nome + JSON schema + prompt), tool de edição via chat, `resolve(array $draft): array` (preview), montagem do form editável a partir do preview e conversão de volta para o shape da Action, `authorize(User): bool` (gates shield do fluxo), `supportsImages(): bool`, e a Action de confirmação (`confirm(array $preview, User $user, string $filePath, array $images): Model`).
- **`ImportTargetRegistry`**: mapa `key → target`; alimenta o classificador (enum de tipos) e o seletor da UI.
- **`SupplierQuotationTarget`**: refatoração do código atual (schema, resolver, editor, `ImportSupplierQuotationAction`) para dentro do contrato. Sem mudança de comportamento; testes existentes seguem valendo.
- **`InquiryTarget`**: novo (ver §3).
- **Extractor/editor genéricos:** `SupplierQuotationExtractor`/`EditSupplierQuotationDraft` viram (ou delegam para) classes genéricas parametrizadas pelo target (tool name, schema, prompt). `DocumentExtractor` e `DocumentImageExtractor` permanecem compartilhados como estão.

### 2. Classificação (`app/Domain/AI/Import/DocumentClassifier.php`)

- Uma chamada com tool forçada `classificar_documento` → `{tipo: <keys do registry>|desconhecido, confianca: alta|media|baixa, motivo: string}`.
- Modelo barato (`services.anthropic.model`, Haiku) — o documento vai como content blocks do `DocumentExtractor` já gerado.
- Chat mostra: "Isso parece uma **cotação de fornecedor** — importar como Supplier Quotation?" + botões **Confirmar** / **Trocar destino** (seletor com os labels do registry).
- `desconhecido`, confiança baixa ou erro na chamada → seletor manual direto, sem bloquear o fluxo.
- A extração completa (chamada cara, Opus) só roda **depois** da confirmação do destino.

### 3. Destino Inquiry

- **Schema de extração** (`InquiryDraftSchema`) — nota: na extração, os campos de header vivem dentro de `cliente` (espelhando o schema de SQ, onde tudo vive em `fornecedor`); o resolver normaliza para um bloco `cabecalho` no preview:
  ```
  cliente: { nome: string, contato?: string, currency_code?: string, deadline?: date, notes?: string }
  itens:   [ { part_no?: string, description: string, quantity: int, unit?: string,
               target_price?: number, specifications?: string, notes?: string, source_row?: int } ]
  → preview: cliente {status, company_id, nome} + cabecalho {currency_code, deadline, notes} + itens + resumo
  ```
- **`ResolveInquiryDraft`** (determinístico): cliente casado por nome (like) → `{status: existente|novo, company_id?}`; produto por `reference_code`/`model_number` = part_no (**match-only**); `target_price_minor` via `Money::toMinor()`; resumo (nº itens, matches, total estimado).
- **Revisão:** seletor de modo — **"Criar Inquiry nova"** (campos do header editáveis: cliente, currency, deadline, notes) ou **"Adicionar a Inquiry existente"** (select das inquiries `open()`, com busca; header do documento ignorado). Tabela de itens editável (description/quantity/unit/target_price/specifications) com badge de match de produto.
- **`ImportInquiryAction`** (`app/Domain/Inquiries/Actions/ImportInquiryAction.php`), transacional:
  - Gates: modo novo → `create-inquiries` (+ `create-companies` se cliente novo); modo existente → `edit-inquiries`.
  - Modo novo: acha/cria `Company` cliente (novo: nome + role CLIENT, idempotente); cria `Inquiry` (company_id, currency_code, deadline, notes, source apropriado do enum `InquirySource`, `reference` auto) + itens.
  - Modo existente: valida que a Inquiry está aberta; adiciona itens com `sort_order` continuando do último.
  - Itens: `product_id` (se casado), `description`, `quantity`, `unit`, `target_price` (centavos), `specifications`, `notes`.
  - Anexa o arquivo original via `HasDocuments`, com um tipo de documento "fonte de import" análogo ao `supplier_quotation_source` usado pelo SQ (se o enum de tipos não tiver equivalente para inquiry, adicionar um `inquiry_source`).
  - Activity log `log_name=ai-assistant`, evento `inquiry_imported`.
- **Chat-edit:** tool `atualizar_inquiry` (paridade com `atualizar_cotacao`), re-resolve e reconstrói o form.

### 4. Ponto de entrada no módulo Inquiries

- Header action **"Importar com IA"** na tela de itens da Inquiry (relation manager / página de view), visível com as permissões do fluxo.
- Redireciona para o Assistant com `?import=inquiry&inquiry_id=N`; o `mount()` valida o id + permissão e trava o destino em "adicionar à Inquiry N" (pula classificação e seletor de modo). O restante do fluxo é idêntico.

### 5. Página do Assistant (`HandlesDocumentImport`)

- `HandlesSupplierQuotationImport` renomeado/generalizado para `HandlesDocumentImport`.
- Estado novo: `#[Locked] importSuggestedTarget` (resultado da classificação) e `#[Locked] importTarget` (destino confirmado; null = aguardando confirmação). Estado existente (arquivo, draft, preview, form, pool de imagens) inalterado.
- Blade da revisão parametrizado por target: seção de header própria (SQ: fornecedor+condições; Inquiry: modo + cliente/header), tabela de itens editável compartilhada, galeria de fotos só quando `supportsImages()`.
- Trocar de destino após extração re-extrai com o schema do novo target (draft anterior descartado; classificação não re-roda).

## Fluxo de dados

upload → `DocumentExtractor` (blocks) → `DocumentClassifier` (sugestão) → usuário confirma/troca destino → extractor genérico com schema do target (draft) → `resolve()` do target (preview) → form editável (+modo, no caso de Inquiry) → [chat-edit opcional] → Confirmar → Action do target (transação) → notificação + link → limpeza.

Entrada via `?import=inquiry&inquiry_id=N`: upload → extração direto com destino travado (sem classificação).

## Erros e segurança

- Mantidos do fluxo atual: path allowlist do arquivo, `#[Locked]` nos caminhos/draft, permissões revalidadas na Action, rollback total, limpeza no confirmar/cancelar, formato não suportado recusado antes da API.
- Classificação falhou/indefinida → seletor manual; nunca bloqueia.
- Extração vazia → mensagem, nada gravado.
- Modo "existente" com Inquiry fechada/cancelada → recusa com mensagem.
- `inquiry_id` inválido ou sem permissão no query param → ignora o parâmetro e cai no fluxo normal.

## Testes (PHPUnit)

- `DocumentClassifier`: client fake → tipo/confiança; erro → fallback `desconhecido`.
- `InquiryDraftSchema` + extractor genérico: draft no shape esperado com client fake.
- `ResolveInquiryDraft`: cliente existente vs novo; produto casado vs null; `target_price_minor` correto; resumo.
- `ImportInquiryAction`: modo novo (cria cliente+inquiry+itens, gates `create-inquiries`/`create-companies`); modo existente (append com sort_order correto, gate `edit-inquiries`, recusa inquiry fechada); anexo do arquivo; rollback.
- Página (Livewire): upload → sugestão → confirmar destino → form → confirmar grava; trocar destino re-extrai; query param trava destino; cancelar limpa tudo.
- Regressão: suíte atual `tests/Feature/AI/Import/*` verde com SQ refatorado para o target.

## Fora de escopo

Destinos PI/PO/Quotation (o contrato deixa prontos para a próxima leva); criação de produtos e fotos no destino Inquiry; substituir o Import from Excel determinístico; import de múltiplos arquivos de uma vez; rascunho persistido em banco (estado segue em memória na página).

## Critério de sucesso

- Subir um PDF/xlsx de pedido de cliente no Assistant → sugestão "Inquiry" → confirmar → revisão editável → Confirmar cria Inquiry + itens (ou adiciona à existente) com arquivo anexado, numa transação.
- Subir uma cotação de fornecedor → fluxo de SQ idêntico ao atual.
- Botão na Inquiry abre o Assistant com destino travado e importa direto para ela.
- Sem confirmação, nada é gravado. Suíte de testes verde; pint limpo.
