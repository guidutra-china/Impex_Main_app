<?php

/**
 * Monta o JSON de loading list do SH-2026-00052 (LWSS26218 / LWSCN26218).
 *
 * O packing list da JingGong é de uma linha só: 36 esteiras JG-9800A em 74
 * volumes. A numeração dos volumes conta a história da embalagem — M01-M36 é o
 * corpo, Z01-Z36 são os acessórios de cada uma (o item já está dividido em
 * Part 1 / Part 2 no embarque), e Z37 e B01 são dois volumes avulsos, sem
 * máquina, que o packing list lista com peso próprio e sem cubagem.
 *
 * A planilha dá peso e cubagem do conjunto M+Z, não de cada caixa — o corpo
 * pesa mais que o pacote de acessórios, mas o documento não separa. Os 72
 * volumes do conjunto dividem igualmente; os totais do contêiner ficam exatos
 * de qualquer forma.
 *
 * Uso:
 *   php scripts/loading-list-lwss26218-to-json.php
 */
require '/Users/guidutra/PhpstormProjects/Impex_Main_app/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$file = '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26218 - DeepFitness - (JG - TBC)/（JG ） JGYAN-20260728PL(2).xls';
$reader = IOFactory::createReaderForFile($file);
$reader->setReadDataOnly(true);
$rows = $reader->load($file)->getSheet(0)->toArray(null, true, false, false);

$ITEM = 'Treadmill JG-9800A — LED Screen Treadmil (taiwan inverter)';
// Totais do draft HBL LWSCN26218.
$BL = ['container' => 'KOCU4015896', 'seal' => '26H0287299', 'packages' => 74, 'gross_weight' => 9066.000, 'volume' => 65.000];

// Lê as quatro linhas de volume da planilha pela faixa de numeração.
$lines = [];
foreach ($rows as $row) {
    $range = trim((string) ($row[0] ?? ''));
    if (! preg_match('/^[MZB]\d+/', $range)) {
        continue;
    }
    $lines[$range] = [
        'packages' => (int) $row[4],
        'net_weight' => $row[5] === null || $row[5] === '' ? null : (float) $row[5],
        'gross_weight' => $row[6] === null || $row[6] === '' ? null : (float) $row[6],
        'volume' => $row[7] === null || $row[7] === '' ? null : (float) $row[7],
    ];
}

// A linha M01-M36 traz o peso e a cubagem do conjunto: as 36 caixas de corpo
// mais as 36 de acessório. A linha Z01-Z36 vem sem número justamente por isso.
$body = $lines['M01-M36'];
$accessories = $lines['Z01-Z36'];
$pairPackages = $body['packages'] + $accessories['packages'];

$packages = [];
$share = fn (float $total) => $total / $pairPackages;

foreach ([['Part 1', $body['packages'], 'M'], ['Part 2', $accessories['packages'], 'Z']] as [$part, $count, $prefix]) {
    for ($i = 1; $i <= $count; $i++) {
        $packages[] = [
            'type' => 'carton',
            'gross_weight' => round($share($body['gross_weight']), 3),
            'net_weight' => round($share($body['net_weight']), 3),
            'volume' => round($share($body['volume']), 6),
            'contents' => [['description' => $ITEM, 'pieces' => 1, 'part' => $part]],
            'notes' => sprintf('%s%02d', $prefix, $i),
        ];
    }
}

// Z37 e B01: volume sem máquina, com peso próprio e sem cubagem no documento.
foreach (['Z37', 'B01'] as $label) {
    $packages[] = [
        'type' => 'carton',
        'gross_weight' => $lines[$label]['gross_weight'],
        'net_weight' => $lines[$label]['net_weight'],
        'volume' => 0,
        'contents' => [],
        'notes' => "{$label} — volume avulso do packing list, sem linha na proforma",
    ];
}

// Acerta o arredondamento da cubagem no último volume do conjunto.
$sum = array_sum(array_column($packages, 'volume'));
$packages[$pairPackages - 1]['volume'] = round($packages[$pairPackages - 1]['volume'] + $BL['volume'] - $sum, 6);

$out = [
    'shipment' => 'SH-2026-00052',
    'source' => '（JG ） JGYAN-20260728PL(2).xls — aba TOTAL',
    'notes' => [
        'O cabeçalho do packing list da JG cita o contêiner UETU5422150; o BL e o embarque usam KOCU4015896.',
        'M01-M36 é o corpo da esteira e Z01-Z36 os acessórios — as duas partes do multi-box do item.',
        'A planilha dá peso e cubagem do conjunto M+Z, não de cada caixa; os 72 volumes dividem igualmente.',
    ],
    'containers' => [[
        'label' => 'CONT-001',
        'container_number' => $BL['container'],
        'type' => '40HQ',
        'seal_number' => $BL['seal'],
        'sort_order' => 1,
        'declared' => ['packages' => $BL['packages'], 'gross_weight' => $BL['gross_weight'], 'volume' => $BL['volume']],
        'packages' => $packages,
    ]],
];

file_put_contents('/Users/guidutra/PhpstormProjects/Impex_Main_app/database/data/loading-lists/SH-2026-00052.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$g = array_sum(array_column($packages, 'gross_weight'));
$n = array_sum(array_column($packages, 'net_weight'));
$v = array_sum(array_column($packages, 'volume'));
printf("volumes %d/%d  GW %s/%s  NW %s (planilha 7.228)  CBM %s/%s  %s\n",
    count($packages), $BL['packages'], number_format($g, 3), number_format($BL['gross_weight'], 3),
    number_format($n, 3), number_format($v, 3), number_format($BL['volume'], 3),
    count($packages) === $BL['packages'] && abs($g - $BL['gross_weight']) < 0.0005 && abs($v - $BL['volume']) < 0.0000005 ? 'CONFERE' : '*** DIVERGE ***');
