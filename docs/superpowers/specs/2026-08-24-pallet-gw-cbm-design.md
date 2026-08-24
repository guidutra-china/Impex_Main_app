# GW e CBM do pallet mandam nos totais do embarque

**Data:** 2026-08-24
**Base:** main pós `dfb45467` (pallet conta como um volume)
**Escopo:** quando caixas viajam em cima de um pallet, o peso bruto e a cubagem que entram nos totais, no packing list e em `shipments.*` passam a ser os **do pallet**, não a soma das caixas.

## Contexto / motivação

`dfb45467` acertou a **contagem**: pallet é um volume, não N caixas (SH-2026-00042 saiu de 109 para 99). Peso e cubagem ficaram de fora e continuaram somando caixa a caixa.

Isso subestima o que a companhia cobra. A caixa cubada individualmente ignora o estrado, o espaço perdido entre caixas e o overhang; o frete é cobrado pelo cubo do conjunto paletizado. No SH-2026-00042 os dois efeitos coincidem por acaso — cada pallet tem 1,725 m³ e as 6 caixas em cima somam exatamente 1,725 m³ —, então este embarque não muda de número, mas o próximo muda.

Estado verificado no código em 2026-08-24:

- `shipment_pallets` tem `length`, `width`, `height`, e **nenhuma coluna de peso**.
- `cartons` tem `gross_weight`, `net_weight`, `volume` (persistido, `L×W×H/1e6` arredondado em 6) e dimensões.
- Somam caixa a caixa hoje: `PackingListBuilder::shipmentTotals()` (SQL), `RecalculateShipmentTotalsAction` (SQL), `PackingListPdfTemplate::computeCartonSubtotals()` (coleção), `container-card` e `pallet-card` (coleção).
- Dos 2 pallets em dev, os dois têm as três dimensões preenchidas.

## Decisões confirmadas (Gui, 2026-08-24)

1. **GW do pallet vem de um campo próprio** (`gross_weight`, novo), o peso que saiu da balança — caixas mais estrado. Preenchido, ele manda; vazio, cai na soma das caixas.
2. **CBM do pallet é derivado das dimensões** (`L×W×H/1e6`), sem coluna nova. As três preenchidas, ele manda; faltando qualquer uma, cai na soma dos volumes das caixas. Assume-se que as medidas do pallet são as do **conjunto paletizado**, que é o que gera cubo cobrado.
3. **NW nunca vem do pallet:** é sempre a soma do líquido das caixas. Estrado não é mercadoria.
4. **No packing list o pallet ganha linha própria** e as colunas voltam a fechar: as caixas paletizadas mantêm produto, EQUIP QTY, NW e a medida da caixa, e deixam de exibir PKG QTY, GW e VOL; a linha do pallet traz `PKG QTY = 1`, GW, dimensões e VOL do pallet. Consequência aceita: o peso bruto por caixa some do PDF para carga paletizada.
5. **Um cálculo só** para tela e documento, em vez de cada lugar somar por conta própria. `ShippingUnitCounter` é absorvido por ele.

Rejeitado: derivar o GW do pallet de uma tara do estrado somada às caixas (nunca diverge da soma, mas também nunca reflete uma pesagem real) e mudar só a tela deixando o documento como está.

## Arquitetura

### 1. Migration + modelo

`shipment_pallets.gross_weight` decimal(10,3) nula, depois de `height`. `ShipmentPallet` ganha o cast, o campo em `$fillable` e dois acessores:

- `volume`: `L×W×H/1e6` arredondado em 6, ou `null` se faltar dimensão.
- `effectiveGrossWeight(float $cartonsGross)` / `effectiveVolume(float $cartonsVolume)`: aplicam a regra de fallback num lugar só.

O peso entra no form "Edit pallet" do builder (`editPalletForm`, `startEditPallet`, `cancelEditPallet`) e o card do pallet passa a mostrar o peso e o cubo efetivos, marcando quando são herdados das caixas.

### 2. `Logistics\Services\PackingTotalsCalculator`

Devolve sempre a mesma estrutura — `units`, `cartons`, `loose_cartons`, `pallets`, `gross`, `net`, `cbm` — em duas formas que precisam bater:

- `fromCartons(Collection $cartons)`: coleção já carregada (PDF, card do container). Exige `pallet` carregado nas caixas paletizadas.
- `fromShipment(Shipment $shipment)`: duas queries agregadas — uma nas caixas (contagens, NW, e GW/CBM só das soltas), outra nos pallets do embarque com a soma das suas caixas. Não hidrata caixa: o builder já precisou de SQL agregado por causa de embarques com milhares delas.

Regra em ambas: `gross = GW das caixas soltas + Σ (pallet.gross_weight ?? GW das suas caixas)`; `cbm` idem com o volume; `net` é a soma de todas as caixas.

Recorte por container continua correto porque todas as caixas de um pallet caem no mesmo grupo — o agrupamento do PDF usa o container **efetivo**, que para caixa paletizada é o do pallet.

### 3. Packing list (PDF e Excel)

`buildLinesFromCartons()` passa a separar as caixas paletizadas: elas são agrupadas normalmente (mesma assinatura, mesmo pallet) mas emitem linha sem `package_qty`, `gross_weight` e `volume`; logo depois do grupo de cada pallet sai a linha do pallet (`package_no` = label, `packaging_type` = "PALLET", `package_qty` = 1, GW/dimensões/VOL efetivos, produto = "Pallet · N boxes").

Subtotais e GRAND TOTAL vêm do `PackingTotalsCalculator`, então voltam a ser exatamente a soma visual das colunas. A nota de rodapé `PKG QTY = 97 CTN + 2 PLT · 109 CTN TOTAL` continua, agora como explicação da contagem de caixas que não aparece mais somada em coluna nenhuma.

### 4. Totais persistidos

`RecalculateShipmentTotalsAction` usa `fromShipment()` para `total_packages`, `total_gross_weight`, `total_net_weight` e `total_volume`. O comando `shipments:recalculate-package-totals` passa a corrigir também peso e cubo, e a tabela do dry-run ganha as colunas — mesma varredura (embarques com caixa em pallet), mesmo `--apply`.

## Testes

- `PackingTotalsCalculatorTest` (unit): fallback dos dois lados, pallet só com peso, pallet só com dimensão, pallet vazio, e **paridade** `fromCartons` × `fromShipment` sobre o mesmo embarque.
- Feature: builder (barra e card do container), PDF (linha do pallet, colunas fechando, GRAND TOTAL), Excel, `RecalculateShipmentTotalsAction`, comando.
- Regressão: embarque sem pallet nenhum tem que sair idêntico ao de hoje.

## Riscos

- Embarque com pallet sem peso nem dimensão continua igual a hoje — nenhum número regride sozinho.
- `total_gross_weight` e `total_volume` de embarques paletizados já gravados só mudam quando o comando rodar; em prod ele roda depois do deploy.
- O GW por caixa some do PDF em carga paletizada. Se incomodar, o plano B é trazê-lo entre parênteses, fora da soma.
