# Nomenclatura da contraparte nos documentos — design

Data: 2026-08-24

## Problema

`ProductIdentityResolver` é a regra única de como uma contraparte identifica um
produto num documento. Hoje ela é fixa: o pivot da contraparte sempre vence.

    código:    pivot.external_code > product.model_number > product.sku
    nome:      pivot.external_name > nome da linha > product.name
    descrição: descrição digitada na linha > pivot.external_description

Para a Deep Fitness isso produz uma Commercial Invoice inconsistente. Medição
das 272 linhas do cadastro do cliente:

| Coluna do relatório | Campo | Preenchida | Em português |
|---|---|---|---|
| C Product Name | `product.name` | 272 | 14% |
| H Client Product Name | `pivot.external_name` | 205 | 88% |
| I Invoice Description | `pivot.external_description` | 204 | 0% |
| E Original Description | `product.description` | 270 | 100% |

A CI sai com 205 nomes em português e 67 em inglês, porque a coluna C só é
impressa quando a H está vazia. O nome bom em inglês está no sistema; a
descrição boa em inglês está no cliente. As duas decisões são opostas, então
não podem compartilhar uma chave.

Não existe hoje forma de escolher, e o único contorno seria apagar
`external_name` — que destrói o nome que o cliente escolheu.

## Decisões

| Eixo | Decisão |
|---|---|
| Código (MODEL NO) | fonte: contraparte \| sistema |
| Nome | fonte: contraparte \| sistema |
| Descrição | exibir \| ocultar **e** fonte: contraparte \| sistema |
| NCM | sempre do pivot do cliente; nunca afetado pelos toggles |
| Onde mora | padrão por empresa, toggle no modal sobrepõe |
| Alcance | CI (PDF+Excel), Packing List, Proforma, PI do embarque, PO, RFQ (PDF+Excel) |
| Grids da tela | intocados |

Descrição digitada de propósito numa linha continua vencendo qualquer fonte: o
toggle escolhe apenas o fallback. Isso preserva `isDeliberate()`, que existe
para o texto digitado à mão não ser anulado pela descrição cadastrada.

`document_show_description = false` zera a descrição da linha, incluindo o
fallback para `specifications` que a CI faz hoje. A view já trata vazio com
`@if($item['description'])`.

Aviso para quem for usar: `product.description` está hoje 100% em português
(270 linhas importadas em 2026-08-24). Escolher fonte `system` para descrição
põe português no documento — inclusive num PO para fornecedor chinês. O default
`counterparty` evita isso; a fonte `system` só serve depois que essas
descrições tiverem versão em inglês.

## Modelo de dados

Quatro colunas em `companies`, com defaults que preservam exatamente o
comportamento atual — nenhum documento muda até alguém trocar um valor.

```
document_code_source         enum('counterparty','system')  default 'counterparty'
document_name_source         enum('counterparty','system')  default 'counterparty'
document_description_source  enum('counterparty','system')  default 'counterparty'
document_show_description    boolean                        default true
```

Um conjunto de colunas serve os dois papéis: a consulta ao banco em 2026-08-24
não encontrou nenhuma empresa com papel de cliente e de fornecedor ao mesmo
tempo. Se aparecer uma que precise de tratamento diferente por papel, separar é
migration aditiva.

## Componentes

### `NamingPreference` (novo value object)

Carrega as quatro decisões. Construtores nomeados: `default()` devolve o
comportamento de hoje; `fromCompany(Company $c)` lê as colunas;
`withOverrides(array $options)` aplica o que veio do modal.

Value object em vez de quatro parâmetros soltos porque atravessa três camadas
(ação → template → resolver) e cresceria a assinatura de todas.

### `ProductIdentityResolver` (alterado)

`forClient()` e `forSupplier()` passam a aceitar `?NamingPreference`, com
`null` significando `NamingPreference::default()` — os chamadores que não
passarem nada seguem funcionando.

`resolve()` consulta o pivot campo a campo conforme a preferência:

- código: `system` pula `external_code` e vai direto para `model_number > sku`
- nome: `system` pula `external_name`; a linha e `product.name` seguem na ordem
- descrição: `isDeliberate()` primeiro; depois `external_description` ou
  `product.description` conforme a fonte; `show_description=false` devolve `null`

`ncm` continua saindo de `pivot.external_ncm` e só do lado do cliente,
independentemente de qualquer toggle. NCM é classificação fiscal do importador,
não nomenclatura — juntar os dois faria um documento sair sem NCM só porque
alguém quis o nome em inglês.

### Modal

`commercialInvoiceOptions()` ganha quatro controles com default vindo da
empresa. Ele já é compartilhado por cinco ações (CI PDF, preview, e-mail, Excel
e PI do embarque), então as cinco herdam. PO e RFQ recebem os mesmos controles
nos seus próprios formulários.

### NCM na Commercial Invoice

A coluna já existe no template e no Excel, controlada por `show_ncm`, que liga
sozinha quando algum item tem NCM. Nada a construir: ela aparece assim que
`external_ncm` for preenchido.

Única mudança: imprimir os 4 primeiros dígitos. O banco guarda os 8 que o
despachante enviou, para a DI/DUIMP; o documento mostra a posição de 4. A
formatação é do template, não do dado.

## Testes

- `NamingPreference`: default preserva o comportamento atual; overrides do
  modal vencem a empresa.
- Resolver, por campo: cada fonte com pivot cheio e vazio; descrição digitada
  vencendo as duas fontes; `show_description=false` zerando; NCM intacto nas
  oito combinações.
- Regressão: com preferência default, `resolve()` devolve exatamente o que
  devolvia antes — é o teste que protege todos os documentos existentes.
- CI: NCM impresso com 4 dígitos a partir de 8 guardados.
- Um teste por documento afetado confirmando que o toggle chega ao PDF.

## Fora de escopo

- Grids e relation managers continuam mostrando a nomenclatura da contraparte.
- Traduzir os 38 nomes em português na coluna C: com nome vindo do sistema a CI
  fica majoritariamente em inglês, mas não 100% até esses serem corrigidos no
  cadastro. É limpeza de dado, não código.
- Persistir as opções de geração na versão do documento. `document_versions`
  não guarda com que opções um PDF foi gerado — vale para `include_freight` e
  `price_formula` tanto quanto para estes toggles, e resolver isso é um
  trabalho próprio.
