<?php

/**
 * Converte a aba de LOADING LIST da planilha do fornecedor no JSON normalizado
 * que `shipments:import-loading-list` consome.
 *
 * O layout esperado é o que a Shandong Luslud manda (colunas em chinês):
 *
 *   A 唛头 (modelo)  B 件数 (volumes)     C 品名 (descrição)
 *   D 单件净重 (NW unit.)  E 单件毛重 (GW unit.)  F 单件体积 (CBM unit.)
 *   G 净重  H 毛重  I 体积  J resumo do contêiner  K observações
 *
 * Cada contêiner é um bloco: linha de cabeçalho com "唛头" (e o número do
 * contêiner em J, ex. "3柜"), linhas de modelo, e rodapé "合计" com os totais.
 * A primeira linha de dados do bloco traz em J o resumo
 * "NUMERO/NNPACKAGES/ NN.NNNKGS/ NN.NNNCBM".
 *
 * O script confere a soma das linhas contra os dois totais declarados e avisa
 * em STDERR quando algo não fecha — a planilha é digitada à mão.
 *
 * Uso:
 *   php scripts/loading-list-to-json.php <planilha.xlsx> <SH-2026-000NN> [aba=1]
 */
require __DIR__.'/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

[$script, $path, $reference] = array_pad($argv, 3, null);
$sheetIndex = (int) ($argv[3] ?? 1);

if ($path === null || $reference === null) {
    fwrite(STDERR, "Uso: php scripts/loading-list-to-json.php <planilha.xlsx> <SH-2026-000NN> [aba=1]\n");
    exit(1);
}

$reader = IOFactory::createReaderForFile($path);
$reader->setReadDataOnly(true);
$rows = $reader->load($path)->getSheet($sheetIndex)->toArray(null, true, false, false);

$cell = fn (array $row, int $i) => trim((string) ($row[$i] ?? ''));
$fnum = fn (string $s) => (float) preg_replace('/[^0-9.]/', '', $s);

$blocks = [];
$cur = null;

foreach ($rows as $row) {
    $a = $cell($row, 0);

    if ($a === '唛头') {                                    // cabeçalho: J traz "3柜"
        $blocks[] = ['seq' => (int) $fnum($cell($row, 9)), 'lines' => []];
        $cur = count($blocks) - 1;

        continue;
    }

    if ($a === '合计') {                                    // rodapé: totais declarados
        $blocks[$cur]['declared'] = [
            'packages' => (int) $cell($row, 1),
            'net_weight' => (float) $cell($row, 6),
            'gross_weight' => (float) $cell($row, 7),
            'volume' => (float) $cell($row, 8),
        ];

        continue;
    }

    if (! preg_match('/^[A-Z][A-Z0-9]{2,9}$/', $a)) {       // total geral / notas livres
        continue;
    }

    if (($j = $cell($row, 9)) !== '') {                     // 1ª linha de dados: resumo do contêiner
        [$number, $packages] = array_map('trim', explode('/', $j));
        $blocks[$cur] += ['container_number' => $number, 'summary_packages' => (int) $fnum($packages)];
    }

    $blocks[$cur]['lines'][] = [
        'model' => $a,
        'description' => $cell($row, 2),
        'packages' => (int) $cell($row, 1),
        'unit_net_weight' => (float) $cell($row, 3),
        'unit_gross_weight' => (float) $cell($row, 4),
        'unit_volume' => (float) $cell($row, 5),
        'notes' => $cell($row, 10) ?: null,
    ];
}

usort($blocks, fn ($x, $y) => $x['seq'] <=> $y['seq']);

$containers = [];

foreach ($blocks as $block) {
    $sums = [
        'packages' => array_sum(array_column($block['lines'], 'packages')),
        'net_weight' => array_sum(array_map(fn ($l) => $l['packages'] * $l['unit_net_weight'], $block['lines'])),
        'gross_weight' => array_sum(array_map(fn ($l) => $l['packages'] * $l['unit_gross_weight'], $block['lines'])),
        'volume' => array_sum(array_map(fn ($l) => $l['packages'] * $l['unit_volume'], $block['lines'])),
    ];

    foreach (['packages' => 0.5, 'net_weight' => 0.5, 'gross_weight' => 0.5, 'volume' => 0.01] as $key => $tolerance) {
        if (abs($sums[$key] - $block['declared'][$key]) > $tolerance) {
            fwrite(STDERR, sprintf(
                "DIVERGE %s %s: linhas=%s declarado=%s\n",
                $block['container_number'], $key, $sums[$key], $block['declared'][$key],
            ));
        }
    }

    if ($sums['packages'] !== $block['summary_packages']) {
        fwrite(STDERR, "DIVERGE {$block['container_number']}: volumes das linhas ≠ resumo da coluna J\n");
    }

    $containers[] = [
        'label' => 'CONT-'.str_pad((string) $block['seq'], 3, '0', STR_PAD_LEFT),
        'container_number' => $block['container_number'],
        'type' => '40HQ',
        'sort_order' => $block['seq'],
        'declared' => $block['declared'],
        'lines' => $block['lines'],
    ];
}

$out = [
    'shipment' => $reference,
    'source' => basename($path).' — aba '.$sheetIndex,
    'containers' => $containers,
];

$dir = __DIR__.'/../database/data/loading-lists';
@mkdir($dir, 0755, true);

// O JSON versionado pode ter recebido anotações à mão depois da extração
// (traduções das observações do fornecedor, o bloco "notes"). Nunca sobrescreve:
// gera um .new.json ao lado para você comparar antes de substituir.
$target = $dir.'/'.$reference.'.json';

if (is_file($target)) {
    $target = $dir.'/'.$reference.'.new.json';
    fwrite(STDERR, "Já existe {$reference}.json — gravando em {$reference}.new.json; compare antes de substituir.\n");
}

file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

printf(
    "%s — %d contêineres, %d volumes, GW %.3f, NW %.3f, CBM %.3f\n",
    $target,
    count($containers),
    array_sum(array_column(array_column($containers, 'declared'), 'packages')),
    array_sum(array_column(array_column($containers, 'declared'), 'gross_weight')),
    array_sum(array_column(array_column($containers, 'declared'), 'net_weight')),
    array_sum(array_column(array_column($containers, 'declared'), 'volume')),
);
