# Impex Trading System

## What This Is

Sistema de controle financeiro e operacional para uma empresa de trading China-Brasil. Gerencia o ciclo completo de operações: desde inquiries e cotações até proforma invoices, ordens de compra, controle de produção, embarques e pagamentos. Inclui portais para clientes e fornecedores.

## Core Value

Rastreabilidade financeira completa de cada operação — desde o pedido do cliente até o pagamento ao fornecedor — com controle de produção planejado vs. realizado alimentando automaticamente o planejamento de embarque e pagamentos.

## Requirements

### Validated

<!-- Funcionalidades existentes e funcionando -->

- ✓ Gestão de Inquiries com itens e comparação de cotações de fornecedores — existing
- ✓ Criação e gestão de Quotations para clientes — existing
- ✓ Proforma Invoices com itens, custos e geração de PDF — existing
- ✓ Purchase Orders com itens vinculados a PI — existing
- ✓ Estado de máquina (state machine) para todos documentos com audit trail — existing
- ✓ Geração de referências sequenciais por tipo de documento — existing
- ✓ Shipment Plans com confirmação e execução — existing
- ✓ Shipments com Commercial Invoice e Packing List — existing
- ✓ Controle de pagamentos com schedule, alocações e reconciliação — existing
- ✓ Custos adicionais por PI — existing
- ✓ Portal do cliente (tenant-scoped por empresa) — existing
- ✓ Portal do fornecedor — existing
- ✓ Gestão de CRM (empresas, contatos) — existing
- ✓ Catálogo de produtos com importação via Excel — existing
- ✓ Geração de PDFs (PI, PO, Quotation, Commercial Invoice, Packing List, RFQ, Cost Statement) — existing
- ✓ Gestão de câmbio com conversão multi-moeda — existing
- ✓ Kanban de pipeline de operações — existing
- ✓ Dashboard financeiro com visão geral de recebíveis/pagáveis — existing
- ✓ Controle de permissões e roles — existing
- ✓ Painel de feiras com scan de cartão de visita via OpenAI — existing
- ✓ Cronograma de produção planejado (estrutura base) — existing
- ✓ Cancelamento de PI com cascata para POs e ShipmentPlans — existing

### Active

<!-- Escopo deste milestone -->

- [ ] Controle de produção: registro de realizado diário pelo fornecedor via Supplier Portal
- [ ] Controle de produção: comparação visual planejado vs. realizado
- [ ] Controle de produção: alimentação automática do planejamento de embarque baseado em produção realizada
- [ ] Controle de produção: cálculo automático de pagamentos baseado em produção pronta para embarque
- [ ] Testes de importação em massa: produtos no catálogo
- [ ] Testes de importação em massa: itens em Inquiries
- [ ] Testes de importação em massa: dados de clientes/fornecedores
- [ ] Correção de N+1 queries nos dashboards e listagens
- [ ] Correção de performance nos widgets financeiros (caching, lazy loading)
- [ ] Correção de race condition na geração de referências
- [ ] Testes para fluxos financeiros críticos (pagamentos, alocações, câmbio)
- [ ] Testes para state machine transitions
- [ ] Limpeza de tech debt (namespaces duplicados, comando órfão, patch files)

### Out of Scope

- App mobile nativo — web-first, portais já são responsivos via Filament
- Integração com ERPs externos (SAP, etc.) — complexidade alta, sem demanda imediata
- Chat/messaging em tempo real entre empresa e fornecedor — comunicação continua por email/WhatsApp
- Automação de câmbio (fetch automático com schedule) — resolução manual suficiente por agora

## Context

- Sistema brownfield em produção com Laravel 12 + Filament 4
- Quatro painéis Filament: admin, portal (cliente), supplier-portal, fair
- Arquitetura DDD com Domain Actions, State Machines, e Infrastructure compartilhada
- Testes existentes cobrem apenas: Money, Commercial Invoice Pricing, Payment Schedule, Cancel PI Action
- Importação de produtos via OpenSpout (Excel), sem cobertura de testes
- ProductionSchedule já existe com entries (item + data + quantidade) — falta o campo "realizado"
- Fornecedores já acessam o Supplier Portal para ver POs e shipments

## Constraints

- **Tech Stack**: Laravel 12 + Filament 4 + MySQL — manter stack existente
- **Backwards Compatibility**: Não quebrar funcionalidades em produção
- **Database**: Migrações devem ser aditivas (não alterar dados existentes destrutivamente)
- **Testes**: SQLite in-memory para testes, queries devem ser compatíveis
- **Monetary**: Usar Money value object existente (escala 10.000) para todos valores financeiros

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| Fornecedor atualiza produção via Supplier Portal | Elimina intermediário, dados em tempo real | — Pending |
| Produção realizada alimenta pagamentos automaticamente | Core value: rastreabilidade financeira completa | — Pending |
| Priorizar testes de importação em massa | Área crítica sem cobertura, alto risco de regressão | — Pending |
| Manter stack existente (Laravel + Filament) | Sistema em produção, sem justificativa para migração | ✓ Good |

---
*Last updated: 2026-03-11 after initialization*
