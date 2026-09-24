<?php

/**
 * Monta o JSON de loading list do embarque Yinqian do BL LWSCN26270 (5×40HQ).
 *
 * Fonte: "Container_PL_YQ260723GW及加单(1).xlsx", o packing list POR CONTÊINER
 * da Yinqian (uma aba por contêiner, com o número no nome da aba), das duas
 * encomendas da invoice YQ260723GW及加单 — YQ260723GW = PI-2026-00072 e o 加单
 * = PI-2026-00084. Total 215 peças, 218 volumes, NW 48.754, GW 57.100.
 *
 * Substitui o packing list sem divisão por contêiner ("YQ260723GW及加单 CI&
 * PL.xlsx"), com que este embarque chegou a ser montado por distribuição
 * inventada. Aqui não há nada inventado: a divisão é a do fornecedor.
 *
 * O que foi decidido:
 *
 *  - Cubagem: as abas somam 288,418 CBM e o BL traz 60/60/60/55/53 = 288,000,
 *    que é o arredondamento delas. Cada caixa leva a cubagem da Yinqian escalada
 *    por contêiner (fator perto de 1) para fechar o BL. Sem medidas, que o
 *    documento não traz.
 *  - LT004: 5 peças em 8 volumes. 5 caixas levam a máquina (com todo o líquido),
 *    3 são caixas de acessório sem item, com o mesmo bruto e cubagem.
 *  - Modelos que o sistema chama diferente: LT002AC = LT002A, BLT015 = LT015.
 *    Itens da PI sem produto casam pela descrição: LT012A, XVD033, XVD025, LT016A.
 *
 * Uso:
 *   php scripts/loading-list-lwss26270-to-json.php <SH-ref> [xlsx]
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$shipment = $argv[1] ?? null;
$xlsx = $argv[2] ?? '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26270 - DeepFitness GYM/Container_PL_YQ260723GW及加单(1).xlsx';

if ($shipment === null || ! is_file($xlsx)) {
    fwrite(STDERR, "Uso: php scripts/loading-list-lwss26270-to-json.php <SH-ref> [xlsx]\n");
    exit(1);
}

// BL LWSCN26270 — são eles que mandam.
$BL = [
    'FFAU7542500' => ['seal' => 'CX0954129', 'packages' => 54, 'gross_weight' => 13510.0, 'volume' => 60.0],
    'BEAU6479731' => ['seal' => 'CX0954128', 'packages' => 50, 'gross_weight' => 12530.0, 'volume' => 60.0],
    'PIDU4091228' => ['seal' => 'CX0658990', 'packages' => 54, 'gross_weight' => 14000.0, 'volume' => 60.0],
    'HPCU4830758' => ['seal' => 'CX0954126', 'packages' => 33, 'gross_weight' => 9090.0, 'volume' => 55.0],
    'BSIU8012978' => ['seal' => 'CX0954122', 'packages' => 27, 'gross_weight' => 7970.0, 'volume' => 53.0],
];

$ALIASES = ['LT002AC' => 'LT002A', 'BLT015' => 'LT015'];
// Modelos cujo item na PI não tem produto: casam pela descrição.
$BY_DESCRIPTION = ['LT012A', 'XVD033', 'XVD025', 'LT016A'];

$reader = IOFactory::createReaderForFile($xlsx);
$reader->setReadDataOnly(true);
$book = $reader->load($xlsx);

$out = [
    'shipment' => $shipment,
    'source' => 'Container_PL_YQ260723GW及加单(1).xlsx (Yinqian, packing list por contêiner; PI-2026-00072 + PI-2026-00084) + draft HBL LWSCN26270',
    'notes' => [
        'A divisão por contêiner é a do fornecedor (uma aba por contêiner), não inventada.',
        'Cubagem: as abas somam 288,418 CBM e o BL traz 288,000 (60/60/60/55/53). Cada caixa leva a cubagem da Yinqian escalada por contêiner para fechar o BL. Sem medidas.',
        'LT004: 5 peças em 8 volumes — 3 caixas de acessório sem item, com o mesmo bruto; o líquido fica todo nas 5 máquinas.',
        'LT002AC no packing list = LT002A no sistema; BLT015 = LT015. LT012A, XVD033, XVD025 e LT016A casam pela descrição (item da PI sem produto).',
    ],
    'containers' => [],
];

$grand = ['packages' => 0, 'pieces' => 0, 'net' => 0.0, 'gross' => 0.0];
$order = 0;

foreach ($book->getSheetNames() as $name) {
    if (! preg_match('/([A-Z]{4}\d{7})/', $name, $m) || ! isset($BL[$m[1]])) {
        fwrite(STDERR, "Aba \"{$name}\" não nomeia um contêiner do BL.\n");
        exit(1);
    }

    $number = $m[1];
    $bl = $BL[$number];
    $rows = [];
    $sheet = ['packages' => 0, 'pieces' => 0, 'net' => 0.0, 'gross' => 0.0, 'volume' => 0.0];
    $declared = null;

    foreach ($book->getSheetByName($name)->toArray(null, true, false, false) as $row) {
        // A linha de total traz "IN TOTAL" na coluna das marcas, não na do modelo.
        $model = trim((string) ($row[1] ?? '')) ?: trim((string) ($row[0] ?? ''));
        $pcs = $row[4] ?? null;

        if ($model === '' || ! is_numeric($pcs)) {
            continue;
        }

        [$pcs, $pkgs, $nw, $gw, $cbm] = [(int) $pcs, (int) $row[5], (float) $row[6], (float) $row[7], (float) $row[8]];

        // A linha "IN TOTAL" repete a soma da aba; serve de conferência.
        if ($model === 'IN TOTAL') {
            $declared = ['pieces' => $pcs, 'packages' => $pkgs, 'net' => $nw, 'gross' => $gw, 'volume' => $cbm];

            continue;
        }

        $rows[] = [
            'raw_model' => $model,
            'model' => in_array($model, $BY_DESCRIPTION, true) ? null : ($ALIASES[$model] ?? $model),
            'description' => trim((string) $row[2]),
            'pieces' => $pcs,
            'packages' => $pkgs,
            'net' => $nw,
            'gross' => $gw,
            'volume' => $cbm,
        ];

        $sheet['packages'] += $pkgs;
        $sheet['pieces'] += $pcs;
        $sheet['net'] += $nw;
        $sheet['gross'] += $gw;
        $sheet['volume'] += $cbm;
    }

    if ($declared === null) {
        fwrite(STDERR, "Aba \"{$name}\" sem linha IN TOTAL.\n");
        exit(1);
    }

    foreach (['pieces', 'packages'] as $field) {
        if ($sheet[$field] !== $declared[$field]) {
            fwrite(STDERR, "{$number}: {$field} soma {$sheet[$field]}, a aba declara {$declared[$field]}.\n");
            exit(1);
        }
    }

    foreach (['net', 'gross'] as $field) {
        if (abs($sheet[$field] - $declared[$field]) > 0.05) {
            fwrite(STDERR, sprintf("%s: %s soma %.2f, a aba declara %.2f.\n", $number, $field, $sheet[$field], $declared[$field]));
            exit(1);
        }
    }

    if ($sheet['packages'] !== $bl['packages'] || abs($sheet['gross'] - $bl['gross_weight']) > 0.05) {
        fwrite(STDERR, sprintf("%s: %d volumes / %.1f kg contra %d / %.1f no BL.\n",
            $number, $sheet['packages'], $sheet['gross'], $bl['packages'], $bl['gross_weight']));
        exit(1);
    }

    // A cubagem do BL é o arredondamento da do fornecedor; o fator só acerta a
    // última casa para que o embarque feche com o conhecimento.
    $factor = $bl['volume'] / $sheet['volume'];

    $lines = [];
    $packages = [];

    foreach ($rows as $row) {
        $unitGross = round($row['gross'] / $row['packages'], 3);
        $unitNet = round($row['net'] / $row['pieces'], 3);
        $unitVolume = round($row['volume'] * $factor / $row['packages'], 6);

        // O arredondamento por caixa não pode mexer no total da linha: a sobra
        // (gramas, centímetros cúbicos) fica toda numa caixa, a última máquina.
        $restGross = round($row['gross'] - $unitGross * $row['packages'], 3);
        $restNet = round($row['net'] - $unitNet * $row['pieces'], 3);
        $restVolume = round($row['volume'] * $factor - $unitVolume * $row['packages'], 6);
        $odd = abs($restGross) > 1e-9 || abs($restNet) > 1e-9 || abs($restVolume) > 1e-9;

        $note = $row['model'] !== null && $row['raw_model'] !== $row['model']
            ? "Modelo {$row['raw_model']} no packing list"
            : null;

        $machine = function (int $count, float $gross, float $net, float $volume) use ($row, $note): array {
            return [
                'model' => $row['model'],
                'description' => $row['description'],
                'packages' => $count,
                'unit_net_weight' => $net,
                'unit_gross_weight' => $gross,
                'unit_volume' => $volume,
                'notes' => $note,
            ];
        };

        if ($row['pieces'] > ($odd ? 1 : 0)) {
            $lines[] = $machine($row['pieces'] - ($odd ? 1 : 0), $unitGross, $unitNet, $unitVolume);
        }

        if ($odd) {
            $lines[] = $machine(
                1,
                round($unitGross + $restGross, 3),
                round($unitNet + $restNet, 3),
                round($unitVolume + $restVolume, 6),
            );
        }

        // Volume a mais do que peça: caixa de acessório, sem item dentro.
        for ($i = $row['pieces']; $i < $row['packages']; $i++) {
            $packages[] = [
                'type' => 'carton',
                'gross_weight' => $unitGross,
                'net_weight' => 0,
                'volume' => $unitVolume,
                'notes' => "Acessórios do {$row['raw_model']} (volume sem máquina)",
                'contents' => [],
            ];
        }
    }

    // Conferência: depois do acerto, o contêiner tem de bater com o BL ao grama.
    $builtGross = array_sum(array_map(fn (array $l) => $l['unit_gross_weight'] * $l['packages'], $lines))
        + array_sum(array_map(fn (array $p) => $p['gross_weight'], $packages));
    $builtVolume = array_sum(array_map(fn (array $l) => $l['unit_volume'] * $l['packages'], $lines))
        + array_sum(array_map(fn (array $p) => $p['volume'], $packages));

    if (abs($builtGross - $bl['gross_weight']) > 0.0005 || abs($builtVolume - $bl['volume']) > 0.0005) {
        fwrite(STDERR, sprintf("%s: depois do arredondamento sobrou %.4f kg / %.6f CBM.\n",
            $number, $builtGross - $bl['gross_weight'], $builtVolume - $bl['volume']));
        exit(1);
    }

    $out['containers'][] = [
        'label' => 'CONT-'.str_pad((string) (++$order), 3, '0', STR_PAD_LEFT),
        'container_number' => $number,
        'type' => '40HQ',
        'seal_number' => $bl['seal'],
        'sort_order' => $order,
        'scale_factor' => round($factor, 6),
        'declared' => [
            'packages' => $bl['packages'],
            'net_weight' => round($sheet['net'], 3),
            'gross_weight' => $bl['gross_weight'],
            'volume' => $bl['volume'],
        ],
        'lines' => $lines,
        'packages' => $packages,
    ];

    $grand['packages'] += $sheet['packages'];
    $grand['pieces'] += $sheet['pieces'];
    $grand['net'] += $sheet['net'];
    $grand['gross'] += $sheet['gross'];

    fwrite(STDOUT, sprintf("%s: %d volumes, %d peças, NW %.1f, GW %.1f, CBM %.3f → %.3f (fator %.6f)\n",
        $number, $sheet['packages'], $sheet['pieces'], $sheet['net'], $sheet['gross'], $sheet['volume'], $bl['volume'], $factor));
}

if ($grand['packages'] !== 218 || $grand['pieces'] !== 215 || abs($grand['gross'] - 57100) > 0.05 || abs($grand['net'] - 48754) > 0.05) {
    fwrite(STDERR, 'Totais do arquivo fora do esperado: '.json_encode($grand)."\n");
    exit(1);
}

$target = __DIR__.'/../database/data/loading-lists/'.$shipment.'.json';

if (is_file($target)) {
    $target = substr($target, 0, -5).'.new.json';
}

file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");
fwrite(STDOUT, "Gravado: {$target}\n");
