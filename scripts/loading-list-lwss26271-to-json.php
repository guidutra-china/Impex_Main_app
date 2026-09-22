<?php

/**
 * Monta o JSON de loading list do embarque DHZ do BL LWSCN26271 (4×40HQ).
 *
 * Fonte: DHZ26080401_PL(2).xlsx, o draft packing list da Luslud (invoices
 * DHZ26080401 + DHZ26073007), que já vem aberto por contêiner com "In Total
 * (<contêiner>)" fechando cada bloco. Pesos por peça saem das colunas K/L
 * (líquido/bruto unitário), que multiplicadas pela quantidade reproduzem as
 * colunas de total.
 *
 * O que o documento não traz e foi decidido:
 *
 *  - U3016 embala 2 volumes por peça (Body/Parts), como no LWSS26214; é isso que
 *    leva o TGBU5844988 de 79 peças aos 89 volumes do BL. As duas caixas pesam
 *    igual (metade do bruto e do líquido da peça). O arquivo divide o item.
 *  - Cubagem: o packing list só dá 60 CBM por contêiner, e o BL repete. As
 *    medidas cadastradas não servem direto: só elas já somam 69,3 CBM no
 *    CMAU6898890. Por isso a cubagem de cada caixa é uma PROPORÇÃO — a cubagem
 *    cadastrada da caixa, ou uma estimativa para quem não tem cadastro — escalada
 *    contêiner a contêiner até fechar 60,000. Sem medidas nas caixas, porque
 *    medida e cubagem não bateriam.
 *  - U3041 está cadastrado com 206×178×109 cm, que é o produto montado (a DHZ
 *    lista assim no leftovers), não a caixa; entra com estimativa.
 *  - A604-G01 no packing list é o produto A604.
 *
 * Uso:
 *   php scripts/loading-list-lwss26271-to-json.php <SH-ref> [xlsx]
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$shipment = $argv[1] ?? null;
$xlsx = $argv[2] ?? '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26271 - DeepFitness DHZ/DHZ26080401_PL(2).xlsx';

if ($shipment === null || ! is_file($xlsx)) {
    fwrite(STDERR, "Uso: php scripts/loading-list-lwss26271-to-json.php <SH-ref> [xlsx]\n");
    exit(1);
}

// BL LWSCN26271 — são eles que mandam.
$BL = [
    'CMAU6898890' => ['seal' => 'M8512822', 'packages' => 78, 'gross_weight' => 14880.0, 'volume' => 60.0],
    'TCNU3227190' => ['seal' => 'M8512975', 'packages' => 55, 'gross_weight' => 11620.0, 'volume' => 60.0],
    'CMAU7875164' => ['seal' => 'M8512582', 'packages' => 60, 'gross_weight' => 10660.0, 'volume' => 60.0],
    'TGBU5844988' => ['seal' => 'M7304940', 'packages' => 89, 'gross_weight' => 12880.0, 'volume' => 60.0],
];

$ALIASES = ['A604-G01' => 'A604'];
$SPLITS = ['U3016' => ['Body', 'Parts']];

// Base de proporção da cubagem, m³ por CAIXA. Cadastro (product_packagings.carton_cbm)
// em 2026-09-22; `estimate` marca quem não tem cadastro utilizável — valor de
// embarques anteriores da Luslud (LWSS26214) quando existe, chute pelo porte senão.
$BASE = [
    'A602' => 0.452540, 'A607' => 0.878900, 'A629' => 0.785813, 'D905Z' => 1.144685,
    'D920Z' => 1.023000, 'D930Z' => 1.542327, 'D935Z' => 1.144685, 'D940Z' => 1.098418,
    'D945Z' => 1.336914, 'D950Z' => 1.542327, 'D955Z' => 1.104240, 'E6250' => 1.984400,
    'U3016' => 0.444690, 'U3036' => 0.184747, 'U3037' => 0.308992, 'U3038' => 0.537660,
    'U3039' => 0.115020, 'U3042' => 1.430016, 'U3043' => 0.114382, 'U3044' => 0.252720,
    'U3045' => 1.650416, 'U3047' => 0.535788, 'U3056S' => 0.870870, 'U3057' => 0.376065,
];
$ESTIMATE = [
    'D602' => 1.584, 'A611' => 0.69, 'U1017C' => 0.8505, 'U3063' => 1.3, 'A609' => 0.97,
    'A604' => 1.1, 'D910Z' => 1.2, 'D915Z' => 1.54742, 'D925Z' => 1.2,
    'A619' => 1.0, 'U2046' => 1.5, 'U3062' => 0.5, 'U3041' => 0.4,
];

$reader = IOFactory::createReaderForFile($xlsx);
$reader->setReadDataOnly(true);
$rows = $reader->load($xlsx)->getSheet(0)->toArray(null, true, false, false);

$containers = [];
$lines = [];

foreach ($rows as $row) {
    $no = $row[1] ?? null;

    if (is_int($no) || (is_numeric($no) && (string) (int) $no === (string) $no)) {
        $model = trim((string) $row[2]);
        $qty = (int) $row[4];
        [$unitNw, $unitGw] = [(float) $row[10], (float) $row[11]];

        if (abs($unitNw * $qty - (float) $row[7]) > 0.001 || abs($unitGw * $qty - (float) $row[8]) > 0.001) {
            fwrite(STDERR, "Linha {$no} ({$model}): unitário × quantidade não fecha com o total.\n");
            exit(1);
        }

        $lines[] = ['no' => (int) $no, 'model' => $ALIASES[$model] ?? $model, 'raw_model' => $model,
            'description' => trim((string) $row[3]), 'qty' => $qty, 'nw' => $unitNw, 'gw' => $unitGw];

        continue;
    }

    if (is_string($no) && preg_match('/In Total \((\w+)\)/', $no, $m)) {
        $containers[] = ['number' => $m[1], 'lines' => $lines, 'gw' => (float) $row[8], 'nw' => (float) $row[7]];
        $lines = [];
    }
}

$out = [
    'shipment' => $shipment,
    'source' => 'DHZ26080401_PL(2).xlsx (Luslud, invoices DHZ26080401 + DHZ26073007) + draft HBL LWSCN26271',
    'notes' => [
        'U3016 (Cable Crossover Evost) embala 2 volumes por peça (Body/Parts); as duas caixas pesam igual. É o que leva o TGBU5844988 de 79 peças aos 89 volumes do BL.',
        'Cubagem: o documento só dá 60 CBM por contêiner. Cada caixa leva a cubagem cadastrada (ou uma estimativa, sem cadastro) como proporção, escalada por contêiner para fechar 60,000. Sem medidas nas caixas.',
        'Estimativas de cubagem (sem cadastro): '.implode(', ', array_map(fn ($m, $v) => "{$m} {$v}", array_keys($ESTIMATE), $ESTIMATE)).'.',
        'U3041: o cadastro traz 206×178×109 cm, que é o produto montado, não a caixa — entrou como estimativa.',
        'A604-G01 no packing list é o produto A604.',
    ],
    'splits' => $SPLITS,
    'containers' => [],
];

foreach ($containers as $index => $container) {
    $bl = $BL[$container['number']] ?? null;

    if ($bl === null || abs($container['gw'] - $bl['gross_weight']) > 0.001) {
        fwrite(STDERR, "Contêiner {$container['number']} fora do BL ou com bruto diferente.\n");
        exit(1);
    }

    $entries = [];
    $baseTotal = 0.0;

    foreach ($container['lines'] as $line) {
        $parts = count($SPLITS[$line['model']] ?? [null]);
        $base = $BASE[$line['model']] ?? $ESTIMATE[$line['model']] ?? null;

        if ($base === null) {
            fwrite(STDERR, "Sem base de cubagem para {$line['model']}.\n");
            exit(1);
        }

        $entries[] = [
            'model' => $line['model'],
            'description' => $line['description'],
            'packages' => $line['qty'] * $parts,
            'unit_net_weight' => round($line['nw'] / $parts, 3),
            'unit_gross_weight' => round($line['gw'] / $parts, 3),
            'base_volume' => $base,
            'notes' => $line['raw_model'] !== $line['model'] ? "Modelo {$line['raw_model']} no packing list" : null,
        ];
        $baseTotal += $base * $line['qty'] * $parts;
    }

    $factor = $bl['volume'] / $baseTotal;
    $packages = 0;

    foreach ($entries as &$entry) {
        $entry['unit_volume'] = round($entry['base_volume'] * $factor, 6);
        unset($entry['base_volume']);
        $packages += $entry['packages'];
    }
    unset($entry);

    if ($packages !== $bl['packages']) {
        fwrite(STDERR, "Contêiner {$container['number']}: {$packages} volumes, BL diz {$bl['packages']}.\n");
        exit(1);
    }

    $out['containers'][] = [
        'label' => 'CONT-'.str_pad((string) ($index + 1), 3, '0', STR_PAD_LEFT),
        'container_number' => $container['number'],
        'type' => '40HQ',
        'seal_number' => $bl['seal'],
        'sort_order' => $index + 1,
        'scale_factor' => round($factor, 5),
        'declared' => [
            'packages' => $bl['packages'],
            'net_weight' => $container['nw'],
            'gross_weight' => $bl['gross_weight'],
            'volume' => $bl['volume'],
        ],
        'lines' => $entries,
    ];
}

$target = __DIR__.'/../database/data/loading-lists/'.$shipment.'.json';

if (is_file($target)) {
    $target = substr($target, 0, -5).'.new.json';
}

file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "Gravado: {$target}\n");
