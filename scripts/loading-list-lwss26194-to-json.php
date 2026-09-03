<?php

/**
 * Monta o JSON de loading list do SH-2026-00047 (LWSS26194 / LWSCN26194).
 *
 * Diferente dos outros embarques, este já tinha packing list montado à mão no
 * sistema, com os volumes certos (64, batendo com o BL) e as medidas de cada
 * caixa e pallet digitadas — mas sem peso líquido nos 8 pallets e com o peso
 * bruto distribuído errado entre fornecedores. Por isso o conversor lê de
 * três lugares:
 *
 *  - o estado atual do embarque (snapshot em JSON): ordem das caixas, medidas,
 *    cubagem, conteúdo, e os valores das 16 cadeiras de massagem da Briliant,
 *    que não têm documento na pasta e ficam como estavam;
 *  - o packing list da DHZ (DHZ26061119): líquido e bruto por modelo das 40
 *    caixas de máquina. É daqui que o U3016 volta a 902 kg (estava 1.258) e o
 *    E6250 a 1.040 (estava 1.080);
 *  - o packing list da Yangrun (YR-JJW-0609, 2026-07-03): líquido e bruto por
 *    grupo dos 8 pallets. É daqui que as anilhas voltam a 2.796 kg brutos nos
 *    dois pallets (estavam 1.200 + 1.200) — os 396 kg que faltavam eram
 *    exatamente os que sobravam nas caixas da DHZ.
 *
 * Inferências que o documento não traz (ver $PALLETS): o bruto dos dois pallets
 * de anilha divide a tara igualmente (73 kg cada); as duas partes do U3016
 * pesam igual, como a Luslud declara no LWSS26214; o líquido de racks e barras
 * é o do grupo da Yangrun, não o do cadastro (342 e 492 contra 354 e 516).
 *
 * Uso:
 *   php scripts/loading-list-lwss26194-to-json.php <snapshot.json>
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$snapshotPath = $argv[1] ?? null;

if ($snapshotPath === null || ! is_file($snapshotPath)) {
    fwrite(STDERR, "Uso: php scripts/loading-list-lwss26194-to-json.php <snapshot.json>\n");
    exit(1);
}

$D = '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26194 - DeepFitness (DHZ:Yangrun:Briliant)/';
$open = function (string $file) {
    $reader = IOFactory::createReaderForFile($file);
    $reader->setReadDataOnly(true);

    return $reader->load($file);
};

$snap = json_decode(file_get_contents($snapshotPath), true);

// Totais do BL LWSCN26194 — são eles que mandam.
$BL = ['container' => 'TXGU8515952', 'seal' => '26H0305252', 'packages' => 64, 'gross_weight' => 15160.000, 'volume' => 52.980];

// ── DHZ: líquido e bruto por PACOTE de cada modelo ──────────────────────────
$dhz = [];
$dhzTotals = ['pcs' => 0, 'pkgs' => 0, 'nw' => 0.0, 'gw' => 0.0];

foreach ($open($D.'DHZ26061119_PL(2).xlsx')->getSheet(0)->toArray(null, true, false, false) as $row) {
    $model = trim((string) ($row[2] ?? ''));

    if (! preg_match('/^[A-Z]\d{3,4}[A-Z]?$/', $model)) {
        continue;
    }

    $pkgs = (int) $row[6];
    $dhz[$model] = ['nw' => (float) $row[7] / $pkgs, 'gw' => (float) $row[8] / $pkgs];
    $dhzTotals['pcs'] += (int) $row[4];
    $dhzTotals['pkgs'] += $pkgs;
    $dhzTotals['nw'] += (float) $row[7];
    $dhzTotals['gw'] += (float) $row[8];
}

if ($dhzTotals != ['pcs' => 38, 'pkgs' => 40, 'nw' => 5280.0, 'gw' => 6420.0]) {
    fwrite(STDERR, 'DHZ não fecha com o rodapé do packing list: '.json_encode($dhzTotals)."\n");
    exit(1);
}

// ── Yangrun: líquido e bruto por GRUPO, para conferir os pallets ────────────
$yangrun = [];

foreach ($open($D.'PL(1).xlsx')->getSheet(0)->toArray(null, true, false, false) as $row) {
    $group = trim((string) ($row[1] ?? ''));

    if ($group === '' || $group === 'Description of goods') {
        continue;
    }

    $yangrun[$group]['nw'] = ($yangrun[$group]['nw'] ?? 0) + (float) $row[4];
    $yangrun[$group]['gw'] = ($yangrun[$group]['gw'] ?? 0) + (float) $row[5];
}

// Pallet a pallet: bruto e líquido, com a origem de cada número.
$PALLETS = [
    'PLT-001' => ['gw' => 358.0, 'nw' => 342.0, 'group' => 'Dumbbell Rack'],
    'PLT-002' => ['gw' => 1523.0, 'nw' => 1450.0, 'group' => 'Weight Plate'],   // 2,5/5/10 kg
    'PLT-003' => ['gw' => 1273.0, 'nw' => 1200.0, 'group' => 'Weight Plate'],   // 20 kg
    'PLT-004' => ['gw' => 725.0, 'nw' => 660.0, 'group' => 'Dumbbell'],         // hexagonais 1–10 kg
    'PLT-005' => ['gw' => 1104.0, 'nw' => 1050.0, 'group' => 'Dumbbell'],       // redondos 12,5–22,5
    'PLT-006' => ['gw' => 1014.0, 'nw' => 990.0, 'group' => 'Dumbbell'],        // redondos 25–30
    'PLT-007' => ['gw' => 1175.0, 'nw' => 1120.0, 'group' => 'Dumbbell'],       // redondos 32,5–40
    'PLT-008' => ['gw' => 528.0, 'nw' => 492.0, 'group' => 'Barbell Bar'],
];

foreach ($yangrun as $group => $t) {
    $mine = array_filter($PALLETS, fn ($p) => $p['group'] === $group);
    $gw = array_sum(array_column($mine, 'gw'));
    $nw = array_sum(array_column($mine, 'nw'));

    if (abs($gw - $t['gw']) > 0.01 || abs($nw - $t['nw']) > 0.01) {
        fwrite(STDERR, sprintf("Yangrun %s: pallets somam GW %s / NW %s, packing list diz %s / %s\n", $group, $gw, $nw, $t['gw'], $t['nw']));
        exit(1);
    }
}

// ── Referência de item: modelo quando o produto tem código, senão descrição ──
$ref = function (int $itemId) use ($snap): array {
    $item = $snap['items'][$itemId];

    return filled($item['ref'] ?? null) ? ['model' => $item['ref']] : ['description' => $item['desc']];
};

$packages = [];

// Caixas soltas, na ordem em que estão, com a medida e a cubagem que já tinham.
foreach ($snap['cartons'] as $carton) {
    if ($carton['pallet'] !== null) {
        continue;
    }

    $content = $carton['contents'][0];
    $model = $snap['items'][$content['item']]['ref'] ?? null;
    $weights = $model !== null && isset($dhz[$model])
        ? $dhz[$model]
        : ['nw' => (float) $carton['nw'], 'gw' => (float) $carton['gw']];   // Briliant: sem documento, fica como estava

    $packages[] = [
        'type' => 'carton',
        'gross_weight' => round($weights['gw'], 3),
        'net_weight' => round($weights['nw'], 3),
        'volume' => (float) $carton['vol'],
        'dimensions' => [(float) $carton['L'], (float) $carton['W'], (float) $carton['H']],
        'contents' => array_map(
            fn ($c) => $ref($c['item']) + ['pieces' => $c['pieces']] + ($c['part'] !== null ? ['part' => $c['part']] : []),
            $carton['contents'],
        ),
        'notes' => null,
    ];
}

// Pallets: um volume cada, com tudo que estava nas caixas em cima dele.
foreach ($snap['pallets'] as $pallet) {
    $label = $pallet['label'];
    $contents = [];

    foreach ($snap['cartons'] as $carton) {
        if ($carton['pallet'] !== $label) {
            continue;
        }

        foreach ($carton['contents'] as $c) {
            $contents[] = $ref($c['item']) + ['pieces' => $c['pieces']];
        }
    }

    $dims = [(float) $pallet['L'], (float) $pallet['W'], (float) $pallet['H']];

    $packages[] = [
        'type' => 'pallet',
        'gross_weight' => $PALLETS[$label]['gw'],
        'net_weight' => $PALLETS[$label]['nw'],
        'volume' => round($dims[0] * $dims[1] * $dims[2] / 1_000_000, 6),
        'dimensions' => $dims,
        'contents' => $contents,
        'notes' => 'Yangrun YR-JJW-0609 — '.$PALLETS[$label]['group'],
    ];
}

$out = [
    'shipment' => $snap['shipment'],
    'source' => 'DHZ26061119_PL(2).xlsx + PL(1).xlsx (Yangrun) + packing list anterior do sistema (medidas e cadeiras Briliant)',
    'notes' => [
        'Os 396 kg que faltavam nos pallets de anilha estavam somados nas caixas da DHZ (U3016 +356, E6250 +40); voltam para onde a Yangrun declarou.',
        'Bruto dos dois pallets de anilha: 2.796 kg da Yangrun com a tara dividida igualmente (73 kg cada).',
        'U3016: as duas partes pesam igual (225,5 kg brutos / 174,25 líquidos por caixa), como a Luslud declara no LWSS26214.',
        'Cadeiras de massagem: sem documento da Briliant na pasta; ficam com os pesos já cadastrados (65 kg brutos; 30 e 27 líquidos).',
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

file_put_contents(
    __DIR__.'/../database/data/loading-lists/SH-2026-00047.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
);

$gross = array_sum(array_column($packages, 'gross_weight'));
$net = array_sum(array_column($packages, 'net_weight'));
$volume = array_sum(array_column($packages, 'volume'));
$ok = count($packages) === $BL['packages'] && abs($gross - $BL['gross_weight']) < 0.0005 && abs($volume - $BL['volume']) < 0.005;

printf(
    "volumes %d/%d  GW %s/%s  NW %s  CBM %s/%s  %s\n",
    count($packages), $BL['packages'],
    number_format($gross, 3), number_format($BL['gross_weight'], 3),
    number_format($net, 3),
    number_format($volume, 3), number_format($BL['volume'], 3),
    $ok ? 'CONFERE' : '*** DIVERGE ***',
);
