# Import product matching — aliases + matcher LLM

**Data:** 2026-07-25
**Status:** aprovado (Gui)
**Problema:** o import do AI Assistant só casa produtos por `part_no` exato ou por igualdade exata de nome normalizado (escopado ao fornecedor). Documentos sem coluna de código e com descrições inferidas de foto (ex.: PIs da Hebei Yangrun) não casam nada — todos os itens viram "novo" e o import duplica o catálogo a cada requote. Registrado desde 2026-07-08 ("SQ import still duplicates products on requote"; INQ-63 nasceu com itens órfãos pelo mesmo motivo).

## Decisões de produto (aprovadas)

- Sugestões da IA chegam **pré-vinculadas** no review, com badge "Sugerido por IA"; desfazer é limpar o produto do item. Vínculo aceito vira alias permanente no confirm.
- Escopo: **SQ e Inquiry** (os dois alvos do import universal).
- **Backfill** de aliases a partir do histórico confirmado (itens de SQ/Inquiry com `product_id`).

## Arquitetura

Pipeline de matching em camadas por item — para na primeira que acertar; IA nunca sobrescreve match determinístico:

1. **`part_no`** → `reference_code` / `model_number` / `sku` (existente hoje).
2. **Nome exato normalizado**, escopado à empresa do documento (existente na SQ; novo na Inquiry, escopado aos produtos vinculados ao cliente).
3. **Alias** — lookup em `product_import_aliases` pela descrição normalizada + `company_id`.
4. **Matcher LLM em lote** — uma chamada por documento, só com os itens ainda sem match.

Cada item resolvido carrega `match_source` (`part_no` | `name` | `alias` | `ai` | `null`) até a UI e o confirm.

### Tabela `product_import_aliases`

| Coluna | Tipo | Notas |
|---|---|---|
| `id` | bigint pk | |
| `company_id` | FK companies, cascade | fornecedor (SQ) ou cliente (Inquiry) |
| `product_id` | FK products, cascade | |
| `alias` | varchar(500) | descrição original |
| `alias_normalized` | varchar(255) | chave: só letras/números, caixa alta (`NameNormalizer`) |
| `source` | varchar(20) | `backfill` \| `import_confirm` |
| `last_confirmed_at` | timestamp | |
| `created_by` | FK users, nullable | |

Único em `(company_id, alias_normalized)`. **Conflito**: a confirmação mais recente vence (upsert troca o `product_id`) — dentro de uma empresa, uma descrição aponta para um único produto.

`NameNormalizer` (classe compartilhada em `app/Domain/AI/Import/Support/`) substitui as cópias privadas de `normalizeName()` no `ResolveSupplierQuotationDraft` e no `ImportYangrunPi0706Command`.

### Matcher LLM (`ProductMatchSuggester`, `app/Domain/AI/Import/`)

- Entrada: itens sem match (índice + descrição + specs) e o catálogo da empresa (id, nome, `reference_code`, `model_number`, até 5 aliases mais recentes por produto).
- Saída via tool estruturada: `[{index, product_id|null}]`. Prompt: tokens de peso/dimensão devem bater exatamente; na dúvida, `null`; só ids do catálogo. Validação server-side descarta ids fora do catálogo.
- Mesmo padrão de SDK/config do `DraftExtractor` (Anthropic SDK já embutido).
- **Falha de API degrada silenciosamente**: itens permanecem "novo"; o import nunca trava. Erro é reportado via `report()`.

### Review UI (página Assistant)

- `match_source === 'ai'` → badge "Sugerido por IA" no item (SQ e Inquiry). Demais matches seguem com o visual atual de "existente".
- `match_source` atravessa `buildForm()` → form → `formToConfirmPayload()`; o status continua derivado do `product_id` (invariante anti-tamper existente).

### Aprendizado no confirm

- `UpsertProductImportAliasAction` (nova): grava/atualiza alias para cada item confirmado **com produto** — qualquer camada ou vínculo manual do review. Guarda: descrição normalizada com ≥ 3 caracteres.
- Chamada em `ImportSupplierQuotationAction` (empresa = fornecedor) e no confirm do alvo Inquiry (empresa = cliente). Dentro da transação existente.

### Backfill

Comando `imports:backfill-product-aliases` (dry-run default, `--apply`): varre `supplier_quotation_items` (empresa via SQ) e `inquiry_items` (empresa via inquiry) com `product_id` preenchido; upsert com `source=backfill`; em duplicata vence o item mais recente. Relatório: criados, atualizados, conflitos.

## Testes

- Unit: `NameNormalizer`; `UpsertProductImportAliasAction` (criação, atualização, conflito, guarda de tamanho).
- Feature: precedência das camadas (nome vence alias, alias vence IA); `ProductMatchSuggester` sempre **fake** em testes (worktree: nunca bater na API real); confirm grava aliases (SQ e Inquiry); backfill; degradação silenciosa em erro de API.

## Rollout

1. Deploy (migration cria a tabela).
2. `php artisan imports:backfill-product-aliases --apply` em dev e prod.
3. Próximo import Yangrun: itens devem casar nas camadas 2–3, sem IA.

Custo: ≤ 1 chamada de modelo extra por documento, decrescente conforme os aliases cobrem o histórico.

## Fora de escopo

- Match fuzzy determinístico (tokens/Jaccard) — a camada 4 cobre o caso.
- `external_name`/`external_code` do pivot como camada extra — pode entrar depois se a telemetria mostrar lacuna.
- Merge/dedupe de produtos já duplicados por imports antigos (tratado pelos comandos existentes de dedupe).
