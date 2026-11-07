<?php

/**
 * Monta o JSON de loading list do embarque Deep Fitness do BL LWSCN26258 (4×40HQ,
 * 242 volumes, 69.552 kg, 224,985 CBM) — SH-2026-00068.
 *
 * Seis fornecedores em quatro contêineres. O que manda é o loading list do
 * despachante (LWSS26258.xls), que soma exatamente o HBL: volumes, bruto e
 * cubagem de cada fornecedor em cada contêiner. Os volumes de cada fornecedor
 * levam peso e cubagem do documento dele, escalados para fechar essa linha.
 *
 * Fontes por fornecedor:
 *
 *  - DHZ / Luslud (LWSS26258B): o packing list só tem totais (#REF! nas linhas).
 *    22 peças = 22 volumes, saldo exato da PI-2026-00083. Bruto e cubagem por
 *    peça vêm do cadastro, escalados para 2.700 kg / 16,445 CBM; líquido =
 *    bruto × 2.260/2.700 (o cadastro tem líquido > bruto em U3036/U3039).
 *  - Yangrun (LWSS26258A): 51 pallets; a coluna I (A/B/C) diz o contêiner. O
 *    peso por pallet (coluna G, em toneladas, arredondado) e a cubagem (E) são
 *    escalados por contêiner. Líquido = bruto − 30 kg de estrado (estimativa: o
 *    documento não traz líquido). O conteúdo de cada pallet foi traduzido do
 *    chinês e amarrado à PI do grupo de invoice (JJW0702 = PI-63/64, JJW0802 =
 *    PI-86, JJW0707 = PI-77, JJW0906 = PI-108). Os pallets 5 e 13 só dizem
 *    "acessórios": o que sobra da PI-63 foi posto neles.
 *  - Shengqi (LWSS26258D): PI-2026-00109 (SQ-X20, SQ-X17) + PI-2026-00113 (remo,
 *    air bike, esteiras). Peso por peça do PL; cubagem 27,09 → 30,865.
 *  - Xuyu (LWSS26258D-1): PI-2026-00071. 5 cases de SQ-X17, 2 pallets com 9
 *    SQ-1023 (5 + 4) e 11 caixas de SQ-1023.
 *  - Brilliant (LWSS26258E): PI-2026-00105, A19 = A19B e SY-5 = WITH COIN. O PL
 *    vem endereçado à MARTRONE; os números valem igual.
 *  - JG / Jinggong (LWSS26258C + JGYAN-20260722-PL.xls): um PL só para as duas
 *    PIs (PI-2026-00096 = JGYAN-20260818, 40 volumes; PI-2026-00076 =
 *    JGYAN-20260722, 84 volumes), sem dizer o contêiner. O PL do OOCU diz 60
 *    volumes da 0722 (NW 5.605 / GW 6.160). A JG não tem a lista por contêiner;
 *    por decisão do Gui (2026-11-07), estimativa: CAAU = PI-96 inteira + 24
 *    volumes da PI-76 (grupos M20, M21+P23-P40, M26, Z11-Z12 e B01); OOCU = o
 *    resto da PI-76. Peso e cubagem de um grupo do PL (linhas mescladas) são
 *    repartidos igualmente entre os volumes do grupo. Volumes "P" de parte que
 *    não carregam a peça entram sem item, com nota. T02 (weight stacks das
 *    máquinas da 0818, sem linha na PI) e B01 (sem modelo) idem.
 *
 * Uso:
 *   php scripts/loading-list-lwss26258-to-json.php [SH-ref] > database/data/loading-lists/SH-2026-00068.json
 */
$shipment = $argv[1] ?? 'SH-2026-00068';

const P63 = 'PI-2026-00063';
const P64 = 'PI-2026-00064';
const P71 = 'PI-2026-00071';
const P76 = 'PI-2026-00076';
const P77 = 'PI-2026-00077';
const P83 = 'PI-2026-00083';
const P86 = 'PI-2026-00086';
const P96 = 'PI-2026-00096';
const P105 = 'PI-2026-00105';
const P108 = 'PI-2026-00108';
const P109 = 'PI-2026-00109';
const P113 = 'PI-2026-00113';

// ───────────────────────── helpers de conteúdo ─────────────────────────

function d(string $description, int $pieces, string $pi): array
{
    return ['description' => $description, 'proforma_invoice' => $pi, 'pieces' => $pieces];
}

function m(string $model, int $pieces = 1, ?string $pi = null): array
{
    return array_filter(['model' => $model, 'proforma_invoice' => $pi, 'pieces' => $pieces], fn ($v) => $v !== null);
}

function hex(string $kg, int $n, string $pi): array
{
    return d("Hexagonal Dumbbell {$kg}kg", $n, $pi);
}

function db(string $kg, int $n, string $pi): array
{
    return d("Dumbbell {$kg}kg", $n, $pi);
}

function plate(string $kg, int $n, string $pi): array
{
    return d("Weight plate {$kg}kg", $n, $pi);
}

function kb(int $kg, int $n): array
{
    return d("Cast Iron Painted Kettlebell — {$kg}kg", $n, P63);
}

function barbell(int $kg, int $n, string $pi): array
{
    return d("barras com anilhas — {$kg}kg", $n, $pi);
}

const STATION = 'Estação de treino / equipamento de academia com estrutura em tubo de aço';

// ───────────────────────────── Yangrun ─────────────────────────────
// [nº, tipo, cubagem, toneladas, contêiner (col. I), resumo do PL, conteúdo]
$yangrun = [
    [1, 'pallet', 1.155, 1.28, 'A', '六角哑铃 1–10kg ×20', [hex('10', 20, P64), hex('9', 20, P64), hex('8', 20, P64), hex('7', 20, P64), hex('6', 20, P64), hex('5', 20, P64), hex('4', 20, P64), hex('3', 20, P64), hex('2', 20, P64), hex('1', 20, P64)]],
    [2, 'pallet', 0.825, 1.40, 'C', '悍马十边杠铃片 20kg ×68', [plate('20', 68, P64)]],
    [3, 'pallet', 0.77, 1.50, 'C', '杠铃片 20kg ×2, 10kg ×144', [plate('20', 2, P64), plate('10', 144, P64)]],
    [4, 'pallet', 0.825, 1.45, 'C', '杠铃片 10kg ×31, 2.5kg ×50, 5kg ×180', [plate('10', 31, P64), plate('2.5', 50, P64), plate('5', 180, P64)]],
    [5, 'pallet', 1.3125, 1.00, 'A', '能量包 5/10/15/20kg ×10, 下拉弹力绳套装, 一体档杆, 战绳 ×8', [
        d('Power bag / saco de peso para treino, cor preta — 5kg', 10, P63),
        d('Power bag / saco de peso para treino, cor preta — 10kg', 10, P63),
        d('Power bag / saco de peso para treino, cor preta — 15kg', 10, P63),
        d('Power bag / saco de peso para treino, cor preta — 20kg', 10, P63),
        d('Kit de suspensão / suspension trainer com alças amarelas', 20, P63),
        d('Par de barras curtas para halteres, cromadas', 20, P63),
        d('Corda naval / battle rope preta', 8, P63),
    ]],
    [6, 'pallet', 0.968, 1.10, 'A', '壶铃 8/10/12/20kg ×20', [kb(8, 20), kb(10, 20), kb(12, 20), kb(20, 20)]],
    [7, 'pallet', 1.43, 0.20, 'A', '木架 ×10, 蹦床 ×10', [d('Espaldar / wall bar de madeira', 10, P63), d('Cama elástica / trampolim de fitness redondo, borda azul', 10, P63)]],
    [8, 'pallet', 0.8625, 1.00, 'A', '壶铃 16kg ×20, 28kg ×10, 32kg ×10', [kb(16, 20), kb(28, 10), kb(32, 10)]],
    [9, 'pallet', 2.64, 0.32, 'A', 'A型哑铃架 ×5, 双层哑铃架 ×5, 杠铃架 ×1', [d('Vertical Dumbbell Rack', 5, P64), d('Dumbell Rack', 5, P64), d('Rack / suporte para barras', 1, P63)]],
    [10, 'pallet', 1.31, 0.20, 'A', '药球 4/6/8kg ×10', [
        d('Wall ball / slam ball preta com marcação de peso — 4kg', 10, P63),
        d('Wall ball / slam ball preta com marcação de peso — 6kg', 10, P63),
        d('Wall ball / slam ball preta com marcação de peso — 8kg', 10, P63),
    ]],
    [11, 'pallet', 1.92, 1.00, 'A', '球 6kg ×50, 4kg ×3, 8kg ×2, 瑜伽球', [
        d('Medicine ball com pegada de borracha, cor azul — 6kg', 50, P63),
        d('Medicine ball com pegada de borracha, cor azul — 4kg', 3, P63),
        d('Medicine ball com pegada de borracha, cor azul — 8kg', 2, P63),
        d('Bola de ginástica / gym ball cinza — 650mm', 20, P63),
        d('Bola de ginástica / gym ball cinza — 750mm', 20, P63),
    ]],
    [12, 'pallet', 1.35, 0.60, 'A', '杠铃杆 2.2m ×10, 1.2m ×5, 1.8m ×10, 1.5m ×10', [
        d('2.2m Olympic Bearing Barbell Bar', 10, P63),
        d('1.2m Bearing Barbell Bar', 5, P63),
        d('Barra Reta 1,8m', 10, P63),
        d('1.5m Olympic Bearing Barbell Bar', 10, P63),
    ]],
    [13, 'pallet', 0.94, 0.30, 'A', '配件: 手把件, 下拉绳配件, 把手配件, W型杆拉手', [
        d('Barra/pegador triangular para puxador de metal', 10, P63),
        d('Double-ended rope (puxador de corda com duas alças)', 20, P63),
        d('Conjunto de pegadores/handles para cabo em aço', 10, P63),
        d('Puxador triangular V-bar cromado para cabo', 20, P63),
        d('Barra "W" para Puxador', 5, P63),
    ]],
    [14, 'pallet', 1.2947, 0.40, 'A', '药球 6/8/10/12kg ×10, 平衡盘 ×20', [
        d('Wall ball / medicine ball com textura, cor cinza — 6kg', 10, P63),
        d('Wall ball / medicine ball com textura, cor cinza — 8kg', 10, P63),
        d('Wall ball / medicine ball com textura, cor cinza — 10kg', 10, P63),
        d('Wall ball / medicine ball com textura, cor cinza — 12kg', 10, P63),
        d('Disco de equilíbrio / balance board preto', 20, P63),
    ]],
    [15, 'pallet', 1.3689, 0.40, 'A', '球 8kg ×48', [d('Medicine ball com pegada de borracha, cor azul — 8kg', 48, P63)]],
    [16, 'pallet', 2.25, 0.70, 'A', '球 4kg ×47, 10kg ×50', [d('Medicine ball com pegada de borracha, cor azul — 4kg', 47, P63), d('Medicine ball com pegada de borracha, cor azul — 10kg', 50, P63)]],
    [17, 'crate', 1.0752, 0.20, 'A', '木箱 杠铃片挂架', [d(STATION, 7, P63)]],
    [18, 'pallet', 2.496, 0.50, 'A', '姑娘蹲', [d('Bench-type gym machine (steel frame training bench/machine)', 10, P64)]],
    [19, 'crate', 2.16, 0.50, 'A', '木箱 杠铃片挂架', [d(STATION, 15, P63)]],
    [20, 'pallet', 0.9, 1.25, 'A', '圆头哑铃 27.5kg ×9, 30/32.5/35kg ×10', [db('27.5', 9, P64), db('30', 10, P64), db('32.5', 10, P64), db('35', 10, P64)]],
    [21, 'pallet', 1.08, 1.20, 'A', '圆头哑铃 12.5–25kg ×10, 27.5kg ×1', [db('12.5', 10, P64), db('15', 10, P64), db('17.5', 10, P64), db('20', 10, P64), db('22.5', 10, P64), db('25', 10, P64), db('27.5', 1, P64)]],
    [22, 'pallet', 1.21, 0.30, 'A', '战绳 ×22, 杠铃 10–30kg ×2', [d('Corda naval / battle rope preta', 22, P63), barbell(10, 2, P63), barbell(15, 2, P63), barbell(20, 2, P63), barbell(25, 2, P63), barbell(30, 2, P63)]],
    // JJW0802 → PI-86
    [23, 'pallet', 3.52, 0.70, 'A', '双层十副哑铃架 ×15', [d('Dumbell Rack', 15, P86)]],
    [24, 'pallet', 1.716, 0.70, 'A', '杠铃杆 1.2m曲 ×10, 1.2m直 ×10, 2.2m ×40, 1.5m ×20, 小环形 ×5', [
        d('W - 1.2 meters Olympic bearing bar', 15, P86),
        d('1.2m Bearing Barbell Bar', 10, P86),
        d('2.2m Olympic bearing bar', 40, P86),
        d('1.5m Olympic bearing bar', 20, P86),
    ]],
    [25, 'pallet', 0.55, 0.20, 'A', 'A6哑铃架 ×15', [d('Vertical Dumbbell Rack', 15, P86)]],
    [26, 'pallet', 0.99, 1.50, 'B', '圆头哑铃 22.5kg ×5, 27.5kg ×23, 30kg ×24', [db('22.5', 5, P86), db('27.5', 23, P86), db('30', 24, P86)]],
    [27, 'pallet', 0.99, 1.70, 'B', '圆头哑铃 35kg ×30, 20kg ×30', [db('35', 30, P86), db('20', 30, P86)]],
    [28, 'pallet', 0.99, 1.20, 'B', '圆头哑铃 22.5kg ×25, 12.5kg ×30, 25kg ×10', [db('22.5', 25, P86), db('12.5', 30, P86), db('25', 10, P86)]],
    [29, 'pallet', 0.99, 1.30, 'B', '圆头哑铃 25kg ×20, 27.5kg ×7, 15kg ×30, 17.5kg ×7', [db('25', 20, P86), db('27.5', 7, P86), db('15', 30, P86), db('17.5', 7, P86)]],
    [30, 'pallet', 0.99, 1.45, 'C', '圆头哑铃 32.5kg ×30, 30kg ×6, 17.5kg ×23', [db('32.5', 30, P86), db('30', 6, P86), db('17.5', 23, P86)]],
    [31, 'pallet', 0.88, 0.90, 'A', '六角哑铃 6/4/9/10kg ×30', [hex('6', 30, P86), hex('4', 30, P86), hex('9', 30, P86), hex('10', 30, P86)]],
    [32, 'pallet', 0.88, 0.80, 'A', '六角哑铃 8/7/5/3/2/1kg ×30', [hex('8', 30, P86), hex('7', 30, P86), hex('5', 30, P86), hex('3', 30, P86), hex('2', 30, P86), hex('1', 30, P86)]],
    [33, 'pallet', 0.715, 1.30, 'B', '悍马片 10kg ×132, 20kg ×2', [plate('10', 132, P86), plate('20', 2, P86)]],
    [34, 'pallet', 0.715, 1.30, 'B', '悍马片 20kg ×66', [plate('20', 66, P86)]],
    [35, 'pallet', 0.715, 1.50, 'B', '悍马片 10kg ×156', [plate('10', 156, P86)]],
    [36, 'pallet', 0.715, 1.40, 'B', '悍马片 10kg ×112, 2.5kg ×120', [plate('10', 112, P86), plate('2.5', 120, P86)]],
    [37, 'pallet', 0.715, 1.25, 'B', '悍马片 2.5kg ×50, 5kg ×150, 20kg ×18', [plate('2.5', 50, P86), plate('5', 150, P86), plate('20', 18, P86)]],
    [38, 'pallet', 0.715, 1.30, 'C', '悍马片 20kg ×64', [plate('20', 64, P86)]],
    [39, 'pallet', 0.8064, 0.18, 'C', '杠铃片储存架 ×5', [d(STATION, 5, P86)]],
    [40, 'pallet', 2.0972, 0.16, 'A', '姑娘蹲 ×5', [d('Hip thrust / glute drive machine (seated bench)', 5, P86)]],
    // JJW0707 → PI-77
    [41, 'pallet', 0.882, 0.50, 'B', '杠铃架 ×3, 哑铃架 ×6, 五件套/双头绳/配件', [
        d('Rack / suporte para barras', 3, P77),
        d('Vertical Dumbbell Rack', 6, P77),
        d('Conjunto de pegadores/handles para cabo em aço', 3, P77),
        d('Double-ended rope (puxador de corda com duas alças)', 6, P77),
        d('Barra/pegador triangular para puxador de metal', 6, P77),
        d('Barra "W" para Puxador', 3, P77),
    ]],
    [42, 'pallet', 1.694, 0.50, 'B', '哑铃架 ×12件, 杠铃杆 1.2m曲 ×3, 1.2m直 ×6, 2.2m ×6, 1.5m ×10', [
        d('Dumbell Rack', 6, P77),
        d('W - 1.2 meters Olympic bearing bar', 3, P77),
        d('1.2m Bearing Barbell Bar', 6, P77),
        d('2.2m Olympic bearing bar', 6, P77),
        d('1.5m Olympic bearing bar', 10, P77),
    ]],
    [43, 'pallet', 0.715, 1.15, 'C', '杠铃片 20kg ×56', [plate('20', 56, P77)]],
    [44, 'pallet', 0.715, 0.85, 'B', '杠铃片 2.5kg ×60, 5kg ×60, 20kg ×19', [plate('2.5', 60, P77), plate('5', 60, P77), plate('20', 19, P77)]],
    [45, 'pallet', 0.715, 1.15, 'C', '杠铃片 10kg ×110', [plate('10', 110, P77)]],
    [46, 'pallet', 0.715, 0.65, 'B', '固定杠铃 10–30kg ×6 (直、曲各3)', [barbell(10, 6, P77), barbell(15, 6, P77), barbell(20, 6, P77), barbell(25, 6, P77), barbell(30, 6, P77)]],
    [47, 'pallet', 0.99, 1.42, 'C', '圆头哑铃 12.5–35kg ×6', [db('12.5', 6, P77), db('15', 6, P77), db('17.5', 6, P77), db('20', 6, P77), db('22.5', 6, P77), db('25', 6, P77), db('27.5', 6, P77), db('30', 6, P77), db('32.5', 6, P77), db('35', 6, P77)]],
    [48, 'pallet', 0.4928, 0.20, 'C', '杠铃片整理架 ×3', [d(STATION, 3, P77)]],
    [49, 'pallet', 0.825, 0.70, 'A', '六角哑铃 1–10kg ×12', [hex('3', 12, P77), hex('7', 12, P77), hex('8', 12, P77), hex('5', 12, P77), hex('1', 12, P77), hex('2', 12, P77), hex('6', 12, P77), hex('4', 12, P77), hex('9', 12, P77), hex('10', 12, P77)]],
    // JJW0906 → PI-108
    [50, 'pallet', 0.8064, 0.12, 'C', '杠铃片储存架', [d(STATION, 5, P108)]],
    [51, 'pallet', 0.8064, 0.12, 'C', '杠铃片储存架', [d(STATION, 5, P108)]],
];

function yangrunPackages(array $rows, string $letter): array
{
    $out = [];

    foreach ($rows as [$no, $type, $cbm, $tons, $where, $summary, $contents]) {
        if ($where !== $letter) {
            continue;
        }

        $gross = $tons * 1000;
        $out[] = [
            'type' => $type === 'pallet' ? 'pallet' : 'carton',
            'gross_weight' => $gross,
            'net_weight' => $gross - 30,
            'volume' => $cbm,
            'notes' => 'Yangrun '.($type === 'pallet' ? '托盘' : '木箱')." {$no}: {$summary}",
            'contents' => $contents,
        ];
    }

    return $out;
}

// ─────────────────────────────── DHZ ───────────────────────────────
// modelo => [peças, bruto do cadastro, cubagem do cadastro]
$dhzCatalog = [
    'D905Z' => [4, 157, 1.144685], 'D920Z' => [4, 198, 1.023], 'D930Z' => [1, 180, 1.542327],
    'U3036' => [3, 36, 0.184747], 'U3039' => [4, 26, 0.11502], 'U3038' => [2, 107, 0.53766],
    'A602' => [1, 61, 0.45254], 'U3045' => [2, 234, 1.650416], 'U3041' => [1, 82, 3.996812],
];
$dhz = [];
foreach ($dhzCatalog as $model => [$qty, $gross, $cbm]) {
    for ($i = 0; $i < $qty; $i++) {
        $dhz[] = ['type' => 'carton', 'gross_weight' => $gross, 'net_weight' => $gross, 'volume' => $cbm, 'notes' => 'DHZ26080401', 'contents' => [m($model)]];
    }
}

// ───────────────────────── Shengqi / Xuyu / Brilliant ─────────────────────────
function repeat(int $n, array $package): array
{
    return array_fill(0, $n, $package);
}

$shengqi = array_merge(
    repeat(13, ['type' => 'carton', 'gross_weight' => 97, 'net_weight' => 76, 'volume' => 1.01, 'notes' => 'Shengqi 20261021 — plywood case', 'contents' => [m('SQ-X20')]]),
    repeat(8, ['type' => 'carton', 'gross_weight' => 174, 'net_weight' => 137, 'volume' => 1.4, 'notes' => 'Shengqi 20261021 — plywood case', 'contents' => [m('SQ-X17', 1, P109)]]),
    [['type' => 'carton', 'gross_weight' => 35, 'net_weight' => 30, 'volume' => 0.32, 'notes' => 'Shengqi SQ-1043 air rower', 'contents' => [d('Fitness equipment - remo (rowing machine)', 1, P113)]]],
    [['type' => 'carton', 'gross_weight' => 56, 'net_weight' => 49, 'volume' => 0.36, 'notes' => 'Shengqi SQ-1033 air bike', 'contents' => [d('Fitness equipment - air bike', 1, P113)]]],
    repeat(2, ['type' => 'carton', 'gross_weight' => 190, 'net_weight' => 160, 'volume' => 1.04, 'notes' => 'Shengqi SQ-1005 unpowered treadmill — plywood case', 'contents' => [d('Fitness equipment - esteira/treadmill', 1, P113)]]),
);

$xuyu = array_merge(
    repeat(5, ['type' => 'carton', 'gross_weight' => 174, 'net_weight' => 137, 'volume' => 1.4, 'notes' => 'Xuyu PIXY20260727002 — plywood case', 'contents' => [m('SQ-X17', 1, P71)]]),
    // 9 bikes em 2 pallets: 603/558 kg e 0,29 CBM por bike.
    [['type' => 'pallet', 'gross_weight' => 603 * 5 / 9, 'net_weight' => 558 * 5 / 9, 'volume' => 0.29 * 5, 'notes' => 'Xuyu export pallet (5 bikes)', 'contents' => [m('SQ-1023', 5)]]],
    [['type' => 'pallet', 'gross_weight' => 603 * 4 / 9, 'net_weight' => 558 * 4 / 9, 'volume' => 0.29 * 4, 'notes' => 'Xuyu export pallet (4 bikes)', 'contents' => [m('SQ-1023', 4)]]],
    repeat(11, ['type' => 'carton', 'gross_weight' => 67, 'net_weight' => 62, 'volume' => 0.29, 'notes' => 'Xuyu PIXY20260727002 — carton', 'contents' => [m('SQ-1023')]]),
);

$brilliant = [
    ['type' => 'carton', 'gross_weight' => 85, 'net_weight' => 57, 'volume' => 1.22, 'notes' => 'Brilliant HSH20260912-1 — A19', 'contents' => [d('MASSAGE CHAIR - A19B', 1, P105)]],
    ['type' => 'carton', 'gross_weight' => 100, 'net_weight' => 70, 'volume' => 1.53, 'notes' => 'Brilliant HSH20260912-1 — SY-5', 'contents' => [d('MASSAGE CHAIR - WITH COIN', 1, P105)]],
];

// ─────────────────────────────── JG ───────────────────────────────
// Grupo do PL = linhas mescladas: [NW, GW, CBM, volumes]. Volume = [rótulo,
// conteúdo, tipo]; conteúdo vazio = parte que viaja sem a peça.
$jgGroups = [
    // PI-2026-00096 (JGYAN-20260818)
    0 => [451, 461, 2.8, [['M01', [m('JG-BM021A'), m('JG-1639', 2), m('JG-1611'), m('JG-1613'), m('JG-1608', 3)]]]],
    1 => [652, 706, 4.6, [['P11', [d('Hack Slide', 1, P96)]], ['P12', []], ['M02', [m('JG-S6760'), m('JG-S6758')]], ['P07', [m('JG-1648')]], ['P08', []]]],
    2 => [311, 361, 2.0, [['M03', [m('JG-S6710'), m('JG-S6761')]]]],
    3 => [286, 336, 2.0, [['M04', [m('JG-S6738', 2)]]]],
    4 => [289, 339, 2.5, [['M05', [m('JG-S6709'), m('JG-S6726')]]]],
    5 => [260, 310, 2.5, [['M06', [m('JG-S6737', 2)]]]],
    6 => [501, 551, 2.5, [['M07', [m('JG-1908'), m('JG-1901'), m('JG-1906')]]]],
    7 => [618, 668, 2.0, [['M08', [m('JG-1665'), m('JG-1612', 2), m('JG-S6704', 2)]]]],
    8 => [376, 426, 2.0, [['M09', [m('JG-1904'), m('JG-1910')]]]],
    9 => [442, 492, 2.0, [['M10', [m('JG-S6727', 3)]]]],
    10 => [324, 375, 1.5, [['M11', [m('JG-1910A')]], ['P10', []]]],
    11 => [561, 661, 3.6, [['M12', [m('JG-6890')]], ['M13', [m('JG-6890')]]]],
    12 => [352, 358, 5.0, [['P01', [m('JG-1614')]], ['P02', []], ['P03', []], ['P04', [m('JG-1614')]], ['P05', []], ['P06', []]]],
    13 => [456, 506, 2.8, [['M14', [m('JG-1629', 3), m('JG-S6733'), m('JG-S6759')]]]],
    14 => [308, 358, 2.2, [['M15', [m('JG-S6720'), m('JG-1907')]]]],
    15 => [324, 375, 1.5, [['M16', [m('JG-1910A')]], ['P09', []]]],
    16 => [1400, 1420, 2.0, [['T01', [
        m('JG-PJ-25KG', 24),
        d('Weight plate JG-PJ-5 — Three-hole Weight Plate', 20, P96),
        d('Weight plate JG-PJ-10 — Three-hole Weight Plate', 70, P96),
        d('Weight plate JG-PJ-20 — Three-hole Weight Plate', 27, P96),
    ], 'pallet']]],
    17 => [200, 210, 0.5, array_map(
        fn (int $i) => [sprintf('Z%02d', $i), [d('Bars JG-Z22700 — Electroplated bar for men bearing capacity of 500 pound', 1, P96)]],
        range(1, 10),
    )],
    18 => [2450, 2470, 3.0, [['T02', [], 'pallet', 'weight stacks das máquinas da JGYAN-20260818 (S6727/S6737/S6758/…/1614/6890A) — sem linha própria na PI']]],
    // PI-2026-00076 (JGYAN-20260722)
    19 => [1180, 1263, 10.0, array_merge(
        parts('P', 11, 12, m('JG-6848'), 6),
        [['M17', [d('Hack squating', 1, P76)]]],
        parts('P', 1, 10, m('JG-6847'), 5),
    )],
    20 => [436, 486, 2.8, [['M18', [d('Hack squating', 2, P76)]]]],
    21 => [436, 486, 2.8, [['M19', [d('Hack squating', 2, P76)]]]],
    22 => [220, 270, 2.8, [['M20', [d('Lateral Raise + Squating Hard Pull', 1, P76), m('JG-6842', 3)]]]],
    23 => [977, 1055, 6.0, array_merge([['M21', [m('JG-6842', 4)]]], parts('P', 23, 18, m('JG-6848'), 9))],
    24 => [863, 935, 6.0, array_merge(
        [['M22', [d('Pendulum Squating', 1, P76), d('Lateral Raise + Squating Hard Pull', 1, P76), m('JG-6813A')]]],
        parts('P', 51, 12, m('JG-6849'), 6),
    )],
    25 => [994, 1062, 12.0, array_merge(
        parts('P', 41, 10, m('JG-6848'), 5), // o PL rotula P41-P48 mas conta 10 volumes
        [['M23', [d('Multi Roman chair', 1, P76), m('JG-6842')]]],
        parts('P', 63, 8, m('JG-6849'), 4),
    )],
    26 => [490, 600, 3.6, [['M24', [m('JG-6842', 6)]], ['M25', [m('JG-6842', 6)]]]],
    27 => [195, 245, 1.3, [['M26', [d('Abdominal crunch', 1, P76)]]]],
    28 => [9, 10, 0.1, [['Z11', [d('Selection Bar', 3, P76)]], ['Z12', [d('Selection Bar', 3, P76)]]]],
    29 => [19, 20, 0.1, [['B01', [], 'carton', 'volume sem modelo no PL da JG']]],
    30 => [435, 455, 0.5, [['T03', [d('Weight Stacks', 360, P76)], 'pallet']]],
];

/**
 * $count volumes de parte rotulados a partir de $first, com $pieces peças da
 * referência espalhadas entre eles; os demais viajam sem a peça.
 */
function parts(string $prefix, int $first, int $count, array $content, int $pieces): array
{
    $out = [];

    for ($i = 0; $i < $count; $i++) {
        $carries = intdiv(($i + 1) * $pieces, $count) > intdiv($i * $pieces, $count);
        $out[] = [sprintf('%s%02d', $prefix, $first + $i), $carries ? [$content] : []];
    }

    return $out;
}

function jgPackages(array $groups, array $ids): array
{
    $out = [];

    foreach ($ids as $id) {
        [$nw, $gw, $cbm, $volumes] = $groups[$id];
        $n = count($volumes);
        $invoice = $id <= 18 ? 'JGYAN-20260818' : 'JGYAN-20260722';

        foreach ($volumes as $volume) {
            [$label, $contents] = $volume;
            $note = "JG {$label} ({$invoice})";

            if ($contents === []) {
                $note .= ' — '.($volume[3] ?? 'parte, sem a peça');
            }

            $out[] = [
                'type' => $volume[2] ?? 'carton',
                'gross_weight' => $gw / $n,
                'net_weight' => $nw / $n,
                'volume' => $cbm / $n,
                'notes' => $note,
                'contents' => $contents,
            ];
        }
    }

    return $out;
}

$jgCaau = jgPackages($jgGroups, [...range(0, 18), 22, 23, 27, 28, 29]);
$jgOocu = jgPackages($jgGroups, [19, 20, 21, 24, 25, 26, 30]);

// ───────────────────── escala por fornecedor no contêiner ─────────────────────
/**
 * Escala bruto e cubagem para a linha do loading list do despachante; o
 * líquido acompanha o bruto, ou fecha num total próprio quando o documento traz.
 * O último volume absorve o arredondamento, para a soma bater no grama.
 */
function scale(array $packages, int $count, float $gross, float $volume, ?float $net = null): array
{
    if (count($packages) !== $count) {
        throw new RuntimeException('Volumes: '.count($packages)." ≠ {$count}");
    }

    $sum = fn (string $k) => array_sum(array_column($packages, $k));
    $fg = $gross / $sum('gross_weight');
    $fv = $volume / $sum('volume');
    $fn = $net !== null ? $net / $sum('net_weight') : $fg;

    $acc = ['gross_weight' => 0.0, 'net_weight' => 0.0, 'volume' => 0.0];
    $targets = ['gross_weight' => $gross, 'net_weight' => $net ?? $sum('net_weight') * $fg, 'volume' => $volume];
    $last = count($packages) - 1;

    foreach ($packages as $i => &$p) {
        foreach (['gross_weight' => [$fg, 3], 'net_weight' => [$fn, 3], 'volume' => [$fv, 6]] as $k => [$f, $dec]) {
            $p[$k] = $i === $last ? round($targets[$k] - $acc[$k], $dec) : round($p[$k] * $f, $dec);
            $acc[$k] += $p[$k];
        }
        $p = ['type' => $p['type'], 'gross_weight' => $p['gross_weight'], 'net_weight' => $p['net_weight'], 'volume' => $p['volume'], 'notes' => $p['notes'], 'contents' => $p['contents']];
    }

    return $packages;
}

// Por contêiner: número, lacre, totais do HBL e, por fornecedor, a linha do
// loading list do despachante (LWSS26258.xls) — volumes, bruto, cubagem, líquido.
$containers = [
    ['CSGU7464107', 'CY396841', [48, 18996, 54.476], [
        scale($dhz, 22, 2700, 16.445, 2260),
        scale(yangrunPackages($yangrun, 'A'), 26, 16296, 38.031),
    ]],
    ['CSGU7414678', 'CY396994', [58, 20824, 58.144], [
        scale(yangrunPackages($yangrun, 'B'), 13, 15305, 11.573),
        scale($shengqi, 25, 3124, 30.865),
        scale($xuyu, 18, 2210, 13, 1925),
        scale($brilliant, 2, 185, 2.706, 127),
    ]],
    ['OOCU9598321', 'CY396846', [72, 17559, 56.695], [
        scale(yangrunPackages($yangrun, 'C'), 12, 11399, 11.457),
        scale($jgOocu, 60, 6160, 45.238, 5605),
    ]],
    ['CAAU6143570', 'CY396842', [64, 12173, 55.67], [
        scale($jgCaau, 64, 12173, 55.67),
    ]],
];

$out = [
    'shipment' => $shipment,
    'source' => 'LWSS26258.xls (loading list do despachante = HBL LWSCN26258) + PLs LWSS26258A/B/C/D/D-1/E + JGYAN-20260722-PL.xls',
    'notes' => [
        'Pesos e cubagens escalados por fornecedor em cada contêiner para fechar o loading list do despachante, que soma o HBL.',
        'JG: divisão OOCU × CAAU estimada (a JG não tem a lista por contêiner): CAAU = PI-2026-00096 + 24 volumes da PI-2026-00076.',
        'Yangrun: líquido = bruto − 30 kg por pallet (o documento não traz líquido); pallets 5 e 13 ("acessórios") receberam o resto da PI-2026-00063.',
        'DHZ: packing list sem linhas (#REF!); bruto e cubagem por peça do cadastro, escalados.',
    ],
    'containers' => [],
];

foreach ($containers as $i => [$number, $seal, [$hblPackages, $hblGross, $hblVolume], $blocks]) {
    $packages = array_merge(...$blocks);
    $out['containers'][] = [
        'label' => sprintf('CONT-%03d', $i + 1),
        'container_number' => $number,
        'type' => '40HQ',
        'seal_number' => $seal,
        'sort_order' => $i + 1,
        // HBL LWSCN26258 — o importador confere a soma dos volumes contra ele.
        'declared' => ['packages' => $hblPackages, 'gross_weight' => $hblGross, 'volume' => $hblVolume],
        'packages' => $packages,
    ];
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION)."\n";
