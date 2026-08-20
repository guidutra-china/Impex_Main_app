# Quotation ao cliente direto da Supplier Quotation — registro de execução

> O plano original era passo-a-passo com o código literal de cada task. Ele foi
> perdido antes do commit (arquivo não rastreado, removido por uma limpeza de
> working tree durante a execução) e **não** foi reconstituído em forma de
> passos: as 9 tasks já estão executadas e commitadas, então o valor agora está
> no registro do que foi feito e por quê. O desenho vive no spec ao lado.

**Spec:** `docs/superpowers/specs/2026-08-19-quotation-from-supplier-quotation-design.md`
**Execução:** 2026-08-19/20, direto em `main`, 32 commits (`db631514`..`f2db36c3`)
**Suíte ao fim:** 1403 passaram, 3 skipped, 0 falhas. Pint limpo.

## Tasks e commits

| # | Task | Commits |
|---|---|---|
| 1 | `CreateDraftProductForSupplierAction` | `db631514`, `3d6e4da6` |
| 2 | Import de SQ delega para a action extraída | `f6a6a17d`, `6b45b9f0` |
| 3 | Parâmetro `preferredSupplierQuotationId` | `c5c95dbd`, `504732ea` |
| 4 | Parâmetro `currencyCode` | `a059a74a`, `4aaaf5d2` |
| 5 | `SyncInquiryFromSupplierQuotationAction` | `93ddeddb`, `cb81310e`, `a4002a0f`, `61406ab7`, `37045bae` |
| 6 | `CreateQuotationFromSupplierQuotationAction` | `f5c3a3bb`, `8b6e115c`, `484207fb`, `d1f0e64e`, `a35c779c`, `c76d35de`, `ec0e6358` |
| 7 | Traduções (pt_BR, en, zh_CN) | `d82f561e`, `99264266` |
| 8 | Ação no header da SQ | `0f2c2484`, `1eadaabb`, `cc0a4ddf`, `cbf8e1bb`, `cab2ebd9`, `66b55cc5`, `be1c11b3`, `7a94a17f`, `29fed1a5`, `f2db36c3` |
| 9 | Verificação final | suíte completa + Pint |

Cada task passou por revisão de conformidade com o spec e revisão de qualidade,
ambas executando o código em vez de apenas lê-lo. Os bugs que isso pegou estão
registrados no spec, na seção da peça correspondente.

## Migration

`2026_08_19_132926_add_source_supplier_quotation_id_to_inquiries_table.php` —
nullable, indexada, FK `nullOnDelete`. **Rodada em dev; pendente em produção.**

## Pendências

- Verificação manual na UI (não executada: exige login no painel).
- Duas confirmações de negócio com o Gui — quantidade da faixa de MOQ eleita, e
  incoterm/payment term do cliente herdados do fornecedor. Ver spec.
- Bug gêmeo em `ImportInquiryAction::linkClientCode()`: mesmo `pivot->update()`
  não escopado por papel, na relação `clients()`. Aberto em sessão separada.
