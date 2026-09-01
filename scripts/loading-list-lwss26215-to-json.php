<?php

/**
 * Converte o loading list do LWSS26215 (SH-2026-00049) no JSON que
 * `shipments:import-loading-list` consome.
 *
 * A fonte é a aba 分柜明细 do arquivo do despachante, que lista por contêiner o
 * modelo, quantos volumes, o peso bruto e a cubagem da linha. Ela cobre os dois
 * fornecedores do embarque: Yinqian (194 volumes) e Shandong Luslud/DHZ (34, que
 * são o saldo do LWSS26214).
 *
 * Três coisas que o documento esconde e que estão resolvidas aqui:
 *
 *  - "FTA" não existe em packing list nenhum. Peso 566 kg e cubagem 2,616174
 *    batem na casa decimal com a 1ª linha de LT013 do Yinqian: é digitação.
 *  - O grupo de 12 volumes de LT013 leva 10 máquinas mais as 2 caixas de
 *    acessório que o packing list declara à parte (peso e cubagem zero) e que o
 *    BL não conta como volume próprio. Quais dos 12 são acessório o documento
 *    não diz — as 10 peças são distribuídas proporcionalmente aos volumes de
 *    cada contêiner.
 *  - O packing list da DHZ traz peso por modelo que não bate com o loading list;
 *    o líquido vem do packing list do LWSS26214, que o loading list reproduz.
 *
 * Uso:
 *   php scripts/loading-list-lwss26215-to-json.php
 */
require '/Users/guidutra/PhpstormProjects/Impex_Main_app/vendor/autoload.php';
use PhpOffice\PhpSpreadsheet\IOFactory;

$D = '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26215 - DeepFitness (Yinqian:TBC)/';
$open = function ($f) {
    $r = IOFactory::createReaderForFile($f);
    $r->setReadDataOnly(true);

    return $r->load($f);
};

// Ordem e totais do BL (folha anexa do LWSS26215).
$BL = [
    'FFAU3302460' => ['seq' => 1, 'packages' => 47, 'gross_weight' => 13211.000, 'volume' => 68.720],
    'OOCU9653877' => ['seq' => 2, 'packages' => 43, 'gross_weight' => 7675.500, 'volume' => 37.230],
    'TGBU5013449' => ['seq' => 3, 'packages' => 46, 'gross_weight' => 13268.920, 'volume' => 65.810],
    'GCXU5103827' => ['seq' => 4, 'packages' => 45, 'gross_weight' => 10542.500, 'volume' => 51.570],
    'TGCU5060980' => ['seq' => 5, 'packages' => 47, 'gross_weight' => 13323.080, 'volume' => 66.080],
];

// ── Razão líquido/bruto por modelo, dos dois packing lists ──
$ratio = [];
foreach ($open($D.'（GYM）YQ260629GW-1 CI& PL.xlsx')->getSheet(1)->toArray(null, true, false, false) as $row) {
    $m = trim((string) ($row[1] ?? ''));
    if ($m === '') {
        continue;
    }
    $ratio[$m]['nw'] = ($ratio[$m]['nw'] ?? 0) + (float) $row[6];
    $ratio[$m]['gw'] = ($ratio[$m]['gw'] ?? 0) + (float) $row[7];
}
// O packing list da DHZ deste embarque traz peso por modelo que não bate com o
// loading list (e o U3045 chega a declarar líquido maior que o bruto), embora o
// total feche. O loading list reproduz exatamente os pesos unitários do packing
// list do LWSS26214 — o embarque anterior, de onde estes 34 volumes sobraram —
// então o líquido vem de lá.
$prev = '/Users/guidutra/Library/CloudStorage/GoogleDrive-Guilherme@impex.ltd/Other computers/Meu computador/Impex Drive/Shippings/2026/LWSS26214 - DeepFintness (DHZ:TBC)/LWSS26214 DHZ - LOADING LIST (GUILHERME).xlsx';
foreach ($open($prev)->getSheet(0)->toArray(null, true, false, false) as $row) {
    $m = trim((string) ($row[2] ?? ''));
    if (! preg_match('/^U\d+$/', $m)) {
        continue;
    }
    $ratio[$m] = ['nw' => (float) $row[10], 'gw' => (float) $row[11]];   // K e L: unitários
}

// ── Loading list: aba 分柜明细 ──
// "FTA" não existe em packing list nenhum; peso 566 kg e cubagem 2,616174 batem
// na casa decimal com a 1ª linha de LT013 do Yinqian (2 peças). É digitação.
$ALIAS = ['FTA' => 'LT013'];
// O grupo de 12 volumes de LT013 leva 10 máquinas mais as 2 caixas de acessório
// que o packing list declara à parte (2 peças, peso e cubagem zero) e que o BL
// não conta como volume próprio. Quais dos 12 são acessório o documento não diz;
// distribuímos as 10 peças proporcionalmente aos volumes de cada contêiner.
$LT013_PIECES = ['TGBU5013449' => 2, 'GCXU5103827' => 4, 'TGCU5060980' => 4];

$rows = $open($D.'LWSS26215 LOADING LIST (GUILHERME).xlsx')->getSheet(2)->toArray(null, true, false, false);
$lines = [];
$cur = null;
foreach ($rows as $row) {
    $head = trim((string) ($row[0] ?? ''));
    if ($head !== '' && preg_match('#^([A-Z]{4}\d+)/#', $head, $m)) {
        $cur = $m[1];
    }
    $model = trim((string) ($row[1] ?? ''));
    if ($model === '' || $cur === null) {
        continue;
    }
    $lines[] = ['container' => $cur, 'raw' => $model, 'model' => $ALIAS[$model] ?? $model,
        'pkgs' => (int) $row[2], 'gw' => (float) $row[3], 'cbm' => (float) $row[4]];
}

$pkgs = [];
foreach ($lines as $l) {
    $n = $l['pkgs'];
    $gwEach = $l['gw'] / $n;
    $cbmEach = $l['cbm'] / $n;
    $r = $ratio[$l['model']] ?? null;
    $nwEach = $r && $r['gw'] > 0 ? $gwEach * $r['nw'] / $r['gw'] : null;

    // Só o grupo grande de LT013 tem volume sem peça; as linhas "FTA" são 1:1.
    $pieces = ($l['model'] === 'LT013' && $l['raw'] === 'LT013') ? $LT013_PIECES[$l['container']] : $n;

    for ($i = 0; $i < $n; $i++) {
        $hasPiece = $i < $pieces;
        $pkgs[$l['container']][] = [
            'type' => 'carton',
            'gross_weight' => round($gwEach, 3),
            'net_weight' => $nwEach === null ? null : round($nwEach, 3),
            'volume' => round($cbmEach, 4),
            'contents' => $hasPiece ? [['model' => $l['model'], 'pieces' => 1]] : [],
            'notes' => $hasPiece
                ? ($l['raw'] === $l['model'] ? null : "Loading list traz o modelo como \"{$l['raw']}\"")
                : 'Caixa de acessório do LT013 — sem linha na proforma',
        ];
    }
}

// Cubagem e peso: acertar o arredondamento para o contêiner fechar com o BL.
$containers = [];
foreach ($BL as $cnt => $bl) {
    foreach (['volume' => 'volume', 'gross_weight' => 'gross_weight'] as $key => $field) {
        $sum = array_sum(array_column($pkgs[$cnt], $field));
        $last = count($pkgs[$cnt]) - 1;
        $pkgs[$cnt][$last][$field] = round($pkgs[$cnt][$last][$field] + $bl[$key] - $sum, $field === 'volume' ? 4 : 3);
    }

    $p = count($pkgs[$cnt]);
    $g = array_sum(array_column($pkgs[$cnt], 'gross_weight'));
    $v = array_sum(array_column($pkgs[$cnt], 'volume'));
    $ok = $p === $bl['packages'] && abs($g - $bl['gross_weight']) < 0.0005 && abs($v - $bl['volume']) < 0.00005;
    printf("%-13s volumes %3d/%-3d  GW %12s / %-12s  CBM %8s / %-8s  %s\n", $cnt, $p, $bl['packages'],
        number_format($g, 3), number_format($bl['gross_weight'], 3),
        number_format($v, 3), number_format($bl['volume'], 3), $ok ? 'CONFERE' : '*** DIVERGE ***');

    $containers[] = ['label' => 'CONT-'.str_pad((string) $bl['seq'], 3, '0', STR_PAD_LEFT), 'container_number' => $cnt,
        'type' => '40HQ', 'sort_order' => $bl['seq'],
        'declared' => ['packages' => $bl['packages'], 'gross_weight' => $bl['gross_weight'], 'volume' => $bl['volume']],
        'packages' => $pkgs[$cnt]];
}

$out = ['shipment' => 'SH-2026-00049', 'source' => 'LWSS26215 LOADING LIST (GUILHERME).xlsx — aba 分柜明细', 'containers' => $containers];
file_put_contents('/Users/guidutra/PhpstormProjects/Impex_Main_app/database/data/loading-lists/SH-2026-00049.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

$all = array_merge(...array_values($pkgs));
printf("\nTOTAL volumes %d  GW %s  NW %s  CBM %s\n", count($all),
    number_format(array_sum(array_column($all, 'gross_weight')), 3),
    number_format(array_sum(array_column($all, 'net_weight')), 3),
    number_format(array_sum(array_column($all, 'volume')), 3));

$byItem = [];
foreach ($all as $p) {
    foreach ($p['contents'] as $c) {
        $byItem[$c['model']] = ($byItem[$c['model']] ?? 0) + $c['pieces'];
    }
}
ksort($byItem);
printf("peças %d em %d modelos\n", array_sum($byItem), count($byItem));
file_put_contents('/tmp/plan49.json', json_encode($byItem));
