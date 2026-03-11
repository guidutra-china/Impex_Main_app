# Roadmap: Impex Trading System

## Overview

Este milestone entrega o controle de produção planejado vs. realizado como funcionalidade central,
seguido de cobertura de testes para as áreas críticas de importação e fluxos financeiros, e por
fim a estabilização do sistema com correções de performance e limpeza de tech debt. O resultado é
um sistema com rastreabilidade financeira completa do pedido ao pagamento, com qualidade e
resiliência para suportar crescimento.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

- [ ] **Phase 1: Production Control** - Fornecedor registra produção realizada; sistema alimenta embarques e pagamentos automaticamente
- [ ] **Phase 2: Test Coverage** - Cobertura automatizada para importações em massa e fluxos financeiros/state machine críticos
- [ ] **Phase 3: Stability & Cleanup** - Correções de performance, race conditions e limpeza de tech debt

## Phase Details

### Phase 1: Production Control
**Goal**: Fornecedores podem registrar produção diária via Supplier Portal, e o sistema automaticamente calcula quantidades prontas para embarque e atualiza o planejamento de pagamentos
**Depends on**: Nothing (first phase)
**Requirements**: PROD-01, PROD-02, PROD-03, PROD-04
**Success Criteria** (what must be TRUE):
  1. Fornecedor acessa o Supplier Portal e registra quantidade produzida por item por dia sem intervenção da equipe interna
  2. Tela de cronograma de produção exibe side-by-side planejado vs. realizado com indicação visual de atraso ou adiantamento
  3. Sistema calcula e exibe quantidade acumulada pronta para embarque baseada na produção realizada registrada
  4. Planejamento de pagamentos reflete automaticamente a produção pronta para embarque sem edição manual
**Plans**: 3 plans

Plans:
- [ ] 01-01-PLAN.md — Migration + model updates + Supplier Portal resource for production actuals (PROD-01)
- [ ] 01-02-PLAN.md — Admin planned vs. actual comparison view and shipment-ready display (PROD-02, PROD-03)
- [ ] 01-03-PLAN.md — Auto-update payment schedule from production actuals (PROD-04)

### Phase 2: Test Coverage
**Goal**: Áreas de alto risco (importações em massa e fluxos financeiros críticos) têm cobertura automatizada que previne regressões
**Depends on**: Phase 1
**Requirements**: IMPT-01, IMPT-02, IMPT-03, TEST-01, TEST-02, TEST-03
**Success Criteria** (what must be TRUE):
  1. Suite de testes valida importação de produtos via Excel sem erros silenciosos (linhas inválidas reportadas, linhas válidas persistidas)
  2. Suite de testes valida importação de itens em Inquiries e dados de clientes/fornecedores cobrindo casos de borda (duplicatas, campos obrigatórios ausentes)
  3. Testes cobrem o ciclo completo de pagamento: alocação, schedule e reconciliação com Money value object
  4. Testes cobrem transições de state machine em todos os modelos principais (PI, PO, Shipment, ShipmentPlan)
  5. Testes cobrem conversão de câmbio multi-moeda incluindo triangulação
**Plans**: TBD

Plans:
- [ ] 02-01: Import tests — products catalog (IMPT-01)
- [ ] 02-02: Import tests — inquiry items and CRM data (IMPT-02, IMPT-03)
- [ ] 02-03: Financial flow tests — payments, allocations, exchange rates (TEST-01, TEST-03)
- [ ] 02-04: State machine transition tests (TEST-02)

### Phase 3: Stability & Cleanup
**Goal**: Sistema em produção opera sem N+1 queries, sem race conditions em geração de referências, e sem tech debt que crie confusão futura
**Depends on**: Phase 2
**Requirements**: PERF-01, PERF-02, PERF-03, DEBT-01, DEBT-02, DEBT-03
**Success Criteria** (what must be TRUE):
  1. Dashboards financeiros e listagens de PIs carregam sem queries N+1 (verificado via Laravel Debugbar ou query log)
  2. Widgets financeiros respondem em tempo aceitável sob carga com caching implementado
  3. Geração de referências sequenciais é atômica — nenhuma duplicata ocorre mesmo sob requisições concorrentes
  4. Codebase tem namespace único Finance/ consolidado (sem Finance/ e Financial/ paralelos) e namespace Purchasing/ removido
  5. Arquivos de patch e comando DropPiSqPivot removidos do repositório
**Plans**: TBD

Plans:
- [ ] 03-01: Fix N+1 queries in dashboards and PI listings (PERF-01)
- [ ] 03-02: Implement caching and lazy loading for financial widgets (PERF-02)
- [ ] 03-03: Fix reference generation race condition with atomic locking (PERF-03)
- [ ] 03-04: Merge Finance/ → Financial/, remove Purchasing/ namespace and patch files (DEBT-01, DEBT-02, DEBT-03)

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Production Control | 2/3 | In Progress|  |
| 2. Test Coverage | 0/4 | Not started | - |
| 3. Stability & Cleanup | 0/4 | Not started | - |
