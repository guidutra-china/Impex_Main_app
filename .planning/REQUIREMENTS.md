# Requirements: Impex Trading System

**Defined:** 2026-03-11
**Core Value:** Rastreabilidade financeira completa com controle de produção planejado vs. realizado alimentando automaticamente embarques e pagamentos.

## v1 Requirements

### Production Control

- [ ] **PROD-01**: Fornecedor pode registrar quantidade produzida diária por item via Supplier Portal
- [ ] **PROD-02**: Sistema compara planejado vs. realizado com visualização clara (dashboard/tabela)
- [ ] **PROD-03**: Sistema calcula automaticamente quantidade pronta para embarque baseado em produção realizada
- [ ] **PROD-04**: Sistema gera/atualiza planejamento de pagamento baseado em produção pronta para embarque

### Import Testing

- [ ] **IMPT-01**: Testes automatizados para importação em massa de produtos no catálogo
- [ ] **IMPT-02**: Testes automatizados para importação de itens em Inquiries
- [ ] **IMPT-03**: Testes automatizados para importação de dados de clientes/fornecedores

### Performance & Stability

- [ ] **PERF-01**: Eliminar N+1 queries nos dashboards financeiros e listagens de PIs
- [ ] **PERF-02**: Implementar caching e lazy loading nos widgets financeiros
- [ ] **PERF-03**: Corrigir race condition na geração de referências sequenciais

### Test Coverage

- [ ] **TEST-01**: Testes para fluxos de pagamento (alocação, schedule, reconciliação)
- [ ] **TEST-02**: Testes para state machine transitions em todos os modelos
- [ ] **TEST-03**: Testes para conversão de câmbio (triangulação multi-moeda)

### Tech Debt Cleanup

- [ ] **DEBT-01**: Merge namespace Finance/ → Financial/
- [ ] **DEBT-02**: Remover namespace vazio Purchasing/
- [ ] **DEBT-03**: Remover comando DropPiSqPivot e patch files

## v2 Requirements

### Production Control (Advanced)

- **PROD-05**: Notificações automáticas quando produção atrasa vs. planejado
- **PROD-06**: Forecast de embarque baseado em tendência de produção
- **PROD-07**: Histórico de versões de cronograma de produção com diff visual

### Automation

- **AUTO-01**: Fetch automático de câmbio via schedule (ECB)
- **AUTO-02**: Alertas de câmbio quando taxa ultrapassa threshold

### Portal Enhancements

- **PORT-01**: Portal do fornecedor: upload de documentos (certificados, inspeção)
- **PORT-02**: Portal do cliente: aprovação de embarque online

## Out of Scope

| Feature | Reason |
|---------|--------|
| App mobile nativo | Web-first, portais Filament já são responsivos |
| Integração com ERPs (SAP, etc.) | Complexidade alta, sem demanda imediata |
| Chat/messaging real-time | Comunicação continua via email/WhatsApp |
| Migração de stack (React, etc.) | Sistema em produção em Laravel+Filament, sem justificativa |

## Traceability

| Requirement | Phase | Status |
|-------------|-------|--------|
| PROD-01 | Phase 1 | Pending |
| PROD-02 | Phase 1 | Pending |
| PROD-03 | Phase 1 | Pending |
| PROD-04 | Phase 1 | Pending |
| IMPT-01 | Phase 2 | Pending |
| IMPT-02 | Phase 2 | Pending |
| IMPT-03 | Phase 2 | Pending |
| TEST-01 | Phase 2 | Pending |
| TEST-02 | Phase 2 | Pending |
| TEST-03 | Phase 2 | Pending |
| PERF-01 | Phase 3 | Pending |
| PERF-02 | Phase 3 | Pending |
| PERF-03 | Phase 3 | Pending |
| DEBT-01 | Phase 3 | Pending |
| DEBT-02 | Phase 3 | Pending |
| DEBT-03 | Phase 3 | Pending |

**Coverage:**
- v1 requirements: 16 total
- Mapped to phases: 16
- Unmapped: 0 ✓

---
*Requirements defined: 2026-03-11*
*Last updated: 2026-03-11 after roadmap creation — all requirements mapped*
