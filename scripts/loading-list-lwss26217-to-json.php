<?php

/**
 * Converte os três loading lists do LWSS26217 (SH-2026-00050) no JSON que
 * `shipments:import-loading-list` consome.
 *
 * Cada fornecedor manda um layout diferente e nenhum é confiável por inteiro,
 * então a leitura é por fornecedor e a conferência é sempre contra o BL:
 *
 *  - SHENGQI: uma aba por contêiner, coluna de embalagem correta ("1*Plywood
 *    Pallet"), quantidade em peças.
 *  - XUYU: mesma planilha, mas as colunas de embalagem e medida foram copiadas
 *    da aba mestre sem ajustar por contêiner. Só a quantidade vale — e nas
 *    linhas de pallet ela conta PALLETS, não peças. As peças saem da diferença
 *    entre o peso declarado do contêiner e o das linhas de caixa; é a única
 *    leitura em que o peso fecha exatamente nos três contêineres.
 *  - YANGRUN: uma ficha por pallet com o conteúdo aberto em chinês. Barras e
 *    racks não trazem quantidade por pallet e são resolvidos por peso (ver
 *    $YR_FIXED). O pallet 12 tem "7kg-48kg-24件", onde o 2º "kg" é 支.
 *
 * A cubagem por caixa da XUYU não fecha com o total declarado do contêiner (o
 * peso fecha). Como o BL é o documento que vale, a cubagem é escalada dentro de
 * cada bloco contêiner×fornecedor para o contêiner bater com ele.
 *
 * Uso:
 *   php scripts/loading-list-lwss26217-to-json.php
 */
require '/Users/guidutra/PhpstormProjects/Impex_Main_app/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$D = '/Users/guidutra/Library/Containers/com.tencent.xinWeChat/Data/Documents/xwechat_files/wxid_97wd41ds21pg22_452e/temp/drag/';
$open = function (string $f) {
    $r = IOFactory::createReaderForFile($f);
    $r->setReadDataOnly(true);

    return $r->load($f);
};

// ── Totais do BL: são eles que mandam ────────────────────────────────────────
$BL = [
    'CSNU7633361' => ['seq' => 1, 'packages' => 49, 'gross_weight' => 10329.600, 'volume' => 31.886],
    'CSGU6781365' => ['seq' => 2, 'packages' => 46, 'gross_weight' => 20740.500, 'volume' => 53.328],
    'TGBU9217590' => ['seq' => 3, 'packages' => 43, 'gross_weight' => 20080.900, 'volume' => 62.304],
];

// ── Peso por PEÇA (bruto, líquido) dos modelos Shengqi e XUYU ───────────────
$UNIT = [
    'SQ-X19' => [83, 62, 0.63], 'SQ-X20' => [97, 76, 1.01], 'SQ-X17' => [174, 137, 1.40],
    'SQ-X13' => [240, 190, 1.71], 'SQ-1023' => [67, 62, 0.29],
    'SQ-1043' => [35, 30, 0.3146], 'SQ-1033' => [56, 49, 0.3588], 'SQ-1005' => [170, 140, 1.038],
];
// Modelos Shengqi: os itens do embarque não têm reference_code, casam por descrição.
$DESC = [
    'SQ-1043' => 'Fitness equipment - remo (rowing machine)',
    'SQ-1033' => 'Fitness equipment - air bike',
    'SQ-1005' => 'Fitness equipment - esteira/treadmill',
];

$pkgs = [];   // contêiner => lista de volumes
$push = function (string $cnt, array $p) use (&$pkgs) {
    $pkgs[$cnt][] = $p;
};
$ref = fn (string $model) => isset($DESC[$model]) ? ['description' => $DESC[$model]] : ['model' => $model];

// ── SHENGQI e XUYU ──────────────────────────────────────────────────────────
// Nas linhas de PALLET a coluna de quantidade traz o número de PALLETS, não de
// peças — é a única leitura em que o peso do contêiner fecha exatamente. As
// peças saem da diferença entre o peso declarado e o das linhas de caixa.
foreach ([['SHENGQI', 'LWSS26217A-2 SHENGQI LOADING LIST.xlsx'], ['XUYU', 'LWSS26217A-XUYU-LOADING LIST.xlsx']] as [$sup, $file]) {
    $ss = $open($D.$file);
    foreach ($ss->getSheetNames() as $i => $name) {
        if (! preg_match('/^([A-Z]{4}\d+)-/', $name, $m)) {
            continue;
        }
        $cnt = $m[1];
        $rows = $ss->getSheet($i)->toArray(null, true, false, false);

        $lines = [];
        $declaredGross = 0.0;
        foreach ($rows as $row) {
            if (trim((string) ($row[0] ?? '')) === 'TOTAL') {
                $declaredGross = (float) preg_replace('/[^0-9.]/', '', (string) $row[11]);

                continue;
            }
            $model = trim((string) ($row[1] ?? ''));
            if (! isset($UNIT[$model])) {
                continue;
            }
            $lines[] = ['model' => $model, 'n' => (int) $row[4], 'pack' => trim((string) ($row[6] ?? ''))];
        }

        // Os dois fornecedores escrevem a mesma planilha de jeitos diferentes.
        // A Shengqi mantém a coluna de embalagem correta por contêiner ("1*Plywood
        // Pallet"), então dela sai o número de volumes e a quantidade é em peças.
        // A XUYU copiou essa coluna da aba mestre sem ajustar — nela só a
        // quantidade vale, e nas linhas de pallet ela conta PALLETS, não peças.
        $plan = [];
        if ($sup === 'SHENGQI') {
            foreach ($lines as $l) {
                preg_match('/^(\d+)\s*\*/', $l['pack'], $q);
                $count = max(1, (int) ($q[1] ?? 1));
                $plan[] = ['model' => $l['model'], 'packages' => $count,
                    'pieces' => intdiv($l['n'], $count),
                    'pallet' => str_contains(mb_strtolower($l['pack']), 'pallet')];
            }
        } else {
            $cartonGross = 0.0;
            foreach ($lines as $l) {
                if (str_contains(mb_strtolower($l['pack']), 'pallet')) {
                    continue;
                }
                $cartonGross += $l['n'] * $UNIT[$l['model']][0];
                $plan[] = ['model' => $l['model'], 'packages' => $l['n'], 'pieces' => 1, 'pallet' => false];
            }
            $palletLines = array_values(array_filter($lines, fn ($l) => str_contains(mb_strtolower($l['pack']), 'pallet')));
            if ($palletLines !== []) {
                // As peças nos pallets são o que sobra do peso declarado do contêiner.
                $rest = $declaredGross - $cartonGross;
                $model = $palletLines[0]['model'];
                $pieces = (int) round($rest / $UNIT[$model][0]);
                $count = array_sum(array_column($palletLines, 'n'));
                if ($pieces % $count !== 0) {
                    fwrite(STDERR, "AVISO {$cnt}/{$sup}: {$pieces} peças em {$count} pallets não divide exato\n");
                }
                $plan[] = ['model' => $model, 'packages' => $count, 'pieces' => intdiv($pieces, $count), 'pallet' => true];
            }
        }

        foreach ($plan as $l) {
            [$gw, $nw, $cbm] = $UNIT[$l['model']];
            for ($k = 0; $k < $l['packages']; $k++) {
                $push($cnt, ['supplier' => $sup, 'type' => $l['pallet'] ? 'pallet' : 'carton',
                    'gross_weight' => $gw * $l['pieces'], 'net_weight' => $nw * $l['pieces'],
                    'volume' => $cbm * $l['pieces'],
                    'contents' => [$ref($l['model']) + ['pieces' => $l['pieces']]],
                    'notes' => $l['pallet'] ? "Pallet de compensado com {$l['pieces']} peça(s)" : null]);
            }
        }
    }
}

// ── YANGRUN: uma ficha por pallet, com o conteúdo aberto em chinês ──────────
$YR_ITEM = [
    '悍马杠铃片' => fn ($kg) => "Weight plate {$kg}kg",
    '六角哑铃' => fn ($kg) => "Dumbbell {$kg}kg",
    '圆头哑铃' => fn ($kg) => "Dumbbell {$kg}kg",
];
// Barras e racks não trazem quantidade por pallet. Barras: os pallets 26 e 27
// são ambos de 2,2m e pesam igual, então dividem as 72 em 36+36; o 25 leva as
// 24 de 1,2m e as 24 de 1,5m. Racks: a Yangrun não sabe dizer o que foi em cada
// pallet, então a separação segue o peso — 29 e 30 são idênticos (mesma
// descrição, peso e medida) e levam 12 cada do modelo de dois níveis; o 28,
// mais leve e mais baixo, leva as 24 unidades do rack vertical.
$YR_FIXED = [
    25 => [['W - 1.2 meters Olympic bearing bar', 24], ['1.5m Olympic bearing bar', 24]],
    26 => [['2.2m Olympic bearing bar', 36]],
    27 => [['2.2m Olympic bearing bar', 36]],
    28 => [['Vertical Dumbbell Rack', 24]],
    29 => [['Dumbell Rack', 12]],
    30 => [['Dumbell Rack', 12]],
];
$TARE = 22.0;

$yg = $open($D.'LWSS26217B YANGRUN-LOADING LIST.xls');
foreach ($yg->getSheetNames() as $i => $name) {
    if (! preg_match('/^([A-Z]{4}\d+)-\d+PLTS$/', $name, $m)) {
        continue;
    }
    foreach ($yg->getSheet($i)->toArray(null, true, false, false) as $row) {
        $num = trim((string) ($row[1] ?? ''));
        if (! ctype_digit($num)) {
            continue;
        }
        $num = (int) $num;
        $kind = trim((string) $row[3]);
        $detail = trim((string) $row[4]);

        $contents = [];
        foreach (preg_split('/\R/u', $detail) as $line) {
            // "7kg-48kg-24件" no pallet 12 é erro de digitação: o 2º "kg" é 支.
            if (preg_match('/^([\d.]+)kg-(\d+)(?:[片支]|kg)/u', trim($line), $q)) {
                $contents[] = ['description' => $YR_ITEM[$kind]($q[1]), 'pieces' => (int) $q[2]];
            }
        }
        $goods = $contents !== [];
        foreach ($YR_FIXED[$num] ?? [] as [$desc, $n]) {
            $contents[] = ['description' => $desc, 'pieces' => $n];
        }

        $gross = (float) $row[10];
        // Placa e haltere pesam o nominal; barra e rack saem do bruto menos o estrado.
        $net = $goods
            ? array_sum(array_map(fn ($c) => (float) preg_replace('/[^\d.]/', '', $c['description']) * $c['pieces'], $contents))
            : $gross - $TARE;

        $push($m[1], ['supplier' => 'YANGRUN', 'type' => 'pallet', 'gross_weight' => $gross,
            'net_weight' => round($net, 3), 'volume' => (float) $row[9],
            'contents' => $contents, 'notes' => "Pallet {$num} — {$detail} — ".trim((string) $row[11])]);
    }
}

// ── Cubagem: ajustar para o contêiner fechar com o BL ───────────────────────
// O peso fecha exatamente linha a linha; a cubagem não. A da XUYU é nominal por
// caixa e o BL traz o que foi declarado ao armador. Escala dentro de cada bloco
// contêiner×fornecedor e joga o resíduo do arredondamento no último volume.
$report = [];
foreach ($pkgs as $cnt => $list) {
    $target = $BL[$cnt]['volume'];
    $palletCbm = 0.0;
    $others = [];
    foreach ($list as $k => $p) {
        if ($p['supplier'] === 'YANGRUN') {
            $palletCbm += $p['volume'];
        } else {
            $others[] = $k;
        }
    }
    $nominal = array_sum(array_map(fn ($k) => $list[$k]['volume'], $others));
    $want = $target - $palletCbm;
    $factor = $nominal > 0 ? $want / $nominal : 1.0;
    $sum = 0.0;
    foreach ($others as $j => $k) {
        $v = $j === count($others) - 1 ? round($want - $sum, 4) : round($list[$k]['volume'] * $factor, 4);
        $sum += $v;
        $pkgs[$cnt][$k]['volume'] = $v;
    }
    $report[$cnt] = ['factor' => $factor, 'nominal' => $nominal, 'ajustado' => $want];
}

// ── Conferência contra o BL ─────────────────────────────────────────────────
$containers = [];
foreach ($BL as $cnt => $bl) {
    $list = $pkgs[$cnt];
    $g = array_sum(array_column($list, 'gross_weight'));
    $v = array_sum(array_column($list, 'volume'));
    $ok = count($list) === $bl['packages'] && abs($g - $bl['gross_weight']) < 0.05 && abs($v - $bl['volume']) < 0.0005;
    printf("%-13s volumes %3d/%-3d  GW %12s / %-12s  CBM %8s / %-8s  %s   (XUYU+SHENGQI ×%.5f)\n",
        $cnt, count($list), $bl['packages'], number_format($g, 3), number_format($bl['gross_weight'], 3),
        number_format($v, 3), number_format($bl['volume'], 3), $ok ? 'CONFERE' : '*** DIVERGE ***', $report[$cnt]['factor']);

    usort($list, fn ($a, $b) => [$a['supplier'], $a['type']] <=> [$b['supplier'], $b['type']]);
    $containers[] = ['label' => 'CONT-'.str_pad((string) $bl['seq'], 3, '0', STR_PAD_LEFT), 'container_number' => $cnt,
        'type' => '40HQ', 'sort_order' => $bl['seq'],
        'declared' => ['packages' => $bl['packages'], 'gross_weight' => $bl['gross_weight'], 'volume' => $bl['volume']],
        'packages' => array_map(fn ($p) => array_diff_key($p, ['supplier' => 1]) + ['supplier' => $p['supplier']], $list)];
}

$out = ['shipment' => 'SH-2026-00050', 'source' => 'Loading lists LWSS26217 — Shengqi, XUYU e Yangrun', 'containers' => $containers];
file_put_contents('/Users/guidutra/PhpstormProjects/Impex_Main_app/database/data/loading-lists/SH-2026-00050.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$all = array_merge(...array_values($pkgs));
printf("\nTOTAL volumes %d  GW %s  NW %s  CBM %s\n", count($all),
    number_format(array_sum(array_column($all, 'gross_weight')), 3),
    number_format(array_sum(array_column($all, 'net_weight')), 3),
    number_format(array_sum(array_column($all, 'volume')), 3));

$byItem = [];
foreach ($all as $p) {
    foreach ($p['contents'] as $c) {
        $byItem[$c['description'] ?? $c['model']] = ($byItem[$c['description'] ?? $c['model']] ?? 0) + $c['pieces'];
    }
}
ksort($byItem);
echo "\npeças por item: ".array_sum($byItem)."\n";
file_put_contents('/tmp/plan_items.json', json_encode($byItem, JSON_UNESCAPED_UNICODE));
