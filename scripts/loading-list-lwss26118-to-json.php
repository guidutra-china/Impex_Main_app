<?php

/**
 * Monta o JSON de loading list do SH-2026-00044 (BL LWSCN26118 / manifest
 * NGP4118602, 1×40HQ TIIU7167062).
 *
 * O embarque já tinha packing list montado no sistema, com os 1573 volumes
 * certos — batem volume a volume com o manifest, grupo a grupo. O que estava
 * errado era peso e cubagem: 843,97 kg e 9,49 m³ a menos que o BL, porque
 * três grupos estavam sem peso nenhum (solares, street lights e os notebooks
 * de brinde) e a Agnes estava com o peso do CADASTRO (7,15 kg/caixa) em vez do
 * peso do packing list da fábrica (6,5).
 *
 * Por isso o conversor lê de dois lugares:
 *
 *  - o estado atual do embarque (retrato em JSON, veja "Uso"): a ordem das
 *    caixas, quantas peças em cada uma, o que tem dentro e como o item é
 *    identificado (reference_code, ou a descrição quando o item não tem
 *    produto — é o caso de quase tudo aqui);
 *  - os packing lists dos fornecedores, na pasta do embarque no Drive, cujos
 *    números estão transcritos abaixo em $DOCS:
 *      · PL-DG.xls — Agnes AG-N-7150-D4: 1348 caixas de 2 peças, 6,5 bruto /
 *        5,55 líquido, 0,041895 m³ (66,5×30×21);
 *      · PL-BM263002-with carton size.xlsx (Danyang Bright-Moon) — os 89
 *        volumes NOVA e as 6 caixas de lente;
 *      · "PL 8pcs by sea.xlsx" — os 8 volumes do Stadium 1000W;
 *      · "Packing list for SEA.xls" — os 17 solares e os 45 street lights,
 *        paletizados em 4 estrados, com peso e cubagem por estrado.
 *
 * O manifest manda no total de cada grupo (é o que o BL soma), então a cubagem
 * e o bruto de cada grupo são escalados para fechar com ele; a sobra de
 * arredondamento fica numa caixa da linha, para o grupo fechar ao grama.
 *
 * Decidido com o Gui (2026-09-30):
 *  - notebooks de brinde não têm packing list; líquido = 90% do bruto, que é a
 *    proporção de todo o resto do embarque;
 *  - solares e street lights viajam em 4 estrados, mas o BL conta as 62 peças
 *    como volumes, então continuam como caixas soltas (senão o embarque teria
 *    1515 volumes, não 1573);
 *  - peso por estrado dividido igualmente entre as caixas dele; o líquido do
 *    grupo é repartido entre os estrados na proporção do bruto (o documento só
 *    dá o líquido do grupo).
 *
 * Inferência registrada: no arquivo dos solares os modelos são 30/40/50/60/70/
 * 80W e no sistema são PV5-40/50/60/100/120/80; casam pelo número de peças
 * (3+3+3 no primeiro estrado, 3+3+2 no segundo). O mesmo para os street lights
 * (o "1094A" do arquivo são os 6 ZG-1094A e o "1098B" os 5 ZG-1094B do sistema,
 * juntos no 3º estrado; PT1 17 e SE 17 no 4º).
 *
 * Uso:
 *   php artisan tinker --execute '...'   # grava o retrato (veja README abaixo)
 *   php scripts/loading-list-lwss26118-to-json.php <SH-ref> <retrato.json>
 *
 * O retrato sai de:
 *   $s = Shipment::where('reference', 'SH-2026-00044')->first();
 *   ... uma entrada por caixa: id, label, gw, nw, cbm, l, w, h e contents
 *   [{code, name, pieces}] — o mesmo formato que este script espera.
 */
require __DIR__.'/../vendor/autoload.php';

$shipment = $argv[1] ?? null;
$snapshotPath = $argv[2] ?? null;

if ($shipment === null || $snapshotPath === null || ! is_file($snapshotPath)) {
    fwrite(STDERR, "Uso: php scripts/loading-list-lwss26118-to-json.php <SH-ref> <retrato.json>\n");
    exit(1);
}

// BL LWSCN26118 + manifest NGP4118602 — são eles que mandam.
$BL = ['container' => 'TIIU7167062', 'seal' => 'J2314476', 'type' => '40HQ',
    'packages' => 1573, 'gross_weight' => 11430.1, 'volume' => 69.55];

/**
 * Grupos do manifest (as partidas NGP4118602A..E), com o total que cada um tem
 * de fechar e como reconhecer as caixas dele no retrato.
 */
$GROUPS = [
    'solar' => ['manifest' => 'A · LED SOLAR LIGHT', 'cartons' => 17, 'gross' => 367.2, 'volume' => 3.3, 'net' => 274.6],
    'street' => ['manifest' => 'A · LED STREET LIGHT', 'cartons' => 45, 'gross' => 424.4, 'volume' => 3.88, 'net' => 316.0],
    'nova' => ['manifest' => 'B · LED FLOOD LIGHT', 'cartons' => 89, 'gross' => 670.0, 'volume' => 2.42, 'net' => 594.03],
    'lens' => ['manifest' => 'B · LIGHTING ACCESSORIES', 'cartons' => 6, 'gross' => 51.5, 'volume' => 0.26, 'net' => 46.02],
    'agnes' => ['manifest' => 'C · LED STREET LIGHT', 'cartons' => 1348, 'gross' => 8762.0, 'volume' => 56.47, 'net' => 7481.4],
    'stadium' => ['manifest' => 'D · LED FLOOD LIGHT', 'cartons' => 8, 'gross' => 225.0, 'volume' => 1.0, 'net' => 208.0],
    'notebook' => ['manifest' => 'E · NOTEBOOK', 'cartons' => 60, 'gross' => 930.0, 'volume' => 2.22, 'net' => 837.0],
];

/**
 * Subgrupos: dentro do grupo, quem divide o mesmo peso e a mesma cubagem.
 * `match` casa com o nome/descrição do item no retrato; `gross`/`volume` são os
 * do documento do fornecedor (estrado, ou linha do packing list); `net` sai do
 * rateio do grupo quando o documento não dá por linha. `dims` só entra quando
 * medida × quantidade reproduz a cubagem do documento.
 */
$DOCS = [
    // "Packing list for SEA.xls" — 4 estrados, peso e cubagem por estrado.
    ['group' => 'solar', 'match' => ['PV5-40', 'PV5-50', 'PV5-60'], 'cartons' => 9, 'gross' => 162.0, 'volume' => 1.3167],
    ['group' => 'solar', 'match' => ['PV5-80', 'PV5-100', 'PV5-120'], 'cartons' => 8, 'gross' => 205.2, 'volume' => 1.9822],
    ['group' => 'street', 'match' => ['ZG-1094A-', 'ZG-1094B-'], 'cartons' => 11, 'gross' => 125.4, 'volume' => 2.10585],
    ['group' => 'street', 'match' => ['ZG-ST20-'], 'cartons' => 34, 'gross' => 299.0, 'volume' => 1.77255],

    // PL-BM263002 (Danyang Bright-Moon): uma linha por modelo, 3 temperaturas
    // de cor por linha no sistema.
    ['group' => 'nova', 'match' => ['NOVA-S-60W'], 'cartons' => 9, 'gross' => 45.9, 'net' => 41.4, 'volume' => 0.1646685, 'dims' => [43, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-S-80W'], 'cartons' => 9, 'gross' => 46.08, 'net' => 41.58, 'volume' => 0.1646685, 'dims' => [43, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-S-100W'], 'cartons' => 9, 'gross' => 46.26, 'net' => 41.76, 'volume' => 0.1646685, 'dims' => [43, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-S-120W'], 'cartons' => 9, 'gross' => 46.44, 'net' => 41.94, 'volume' => 0.1646685, 'dims' => [43, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-M-150W'], 'cartons' => 9, 'gross' => 59.4, 'net' => 53.1, 'volume' => 0.2029635, 'dims' => [53, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-M-200W'], 'cartons' => 9, 'gross' => 59.85, 'net' => 53.55, 'volume' => 0.2029635, 'dims' => [53, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-M-250W'], 'cartons' => 9, 'gross' => 60.75, 'net' => 54.45, 'volume' => 0.2029635, 'dims' => [53, 37, 11.5]],
    ['group' => 'nova', 'match' => ['NOVA-L-300W'], 'cartons' => 9, 'gross' => 103.5, 'net' => 90.0, 'volume' => 0.39965625, 'dims' => [62.5, 49, 14.5]],
    ['group' => 'nova', 'match' => ['NOVA-L-350W'], 'cartons' => 9, 'gross' => 105.75, 'net' => 92.25, 'volume' => 0.39965625, 'dims' => [62.5, 49, 14.5]],
    ['group' => 'nova', 'match' => ['NOVA-L-400W'], 'cartons' => 8, 'gross' => 96.0, 'net' => 84.0, 'volume' => 0.35525, 'dims' => [62.5, 49, 14.5]],

    // As 6 caixas de lente do mesmo packing list. O fornecedor descreve 5
    // caixas grandes e 1 pequena; o sistema tem 3 e 3, com o conteúdo repartido
    // de outro jeito. Mantida a repartição do sistema (é ela que diz quantas
    // lentes de cada tipo vão em cada caixa) e a cubagem do grupo do documento,
    // rateada pelo tamanho das caixas — por isso as lentes ficam sem medida.
    ['group' => 'lens', 'match' => ['Lens '], 'cartons' => 6, 'gross' => 51.5, 'net' => 46.02, 'volume' => 0.26],

    // PL-DG.xls — Agnes, 2 peças por caixa.
    ['group' => 'agnes', 'match' => ['AGN-150D4'], 'cartons' => 1348, 'gross' => 8762.0, 'net' => 7481.4, 'volume' => 56.47, 'dims' => [66.5, 30, 21]],

    // "PL 8pcs by sea.xlsx" — o manifest declara 225 kg / 1,000 m³ onde o
    // fornecedor traz 226,4 / 0,9647; vale o manifest, que é o que o BL soma.
    ['group' => 'stadium', 'match' => ['Stadium Light 1000W'], 'cartons' => 8, 'gross' => 225.0, 'net' => 208.0, 'volume' => 1.0],

    // Brinde, sem packing list: só o manifest.
    ['group' => 'notebook', 'match' => ['Gift -Notebook'], 'cartons' => 60, 'gross' => 930.0, 'net' => 837.0, 'volume' => 2.22],
];

$snapshot = json_decode((string) file_get_contents($snapshotPath), true);

if (! is_array($snapshot) || $snapshot === []) {
    fwrite(STDERR, "Retrato inválido: {$snapshotPath}\n");
    exit(1);
}

// Os documentos dos fornecedores e o manifest divergem na última casa (o
// manifest arredonda: 3,2989 m³ dos dois estrados de solar viram 3,3). Quem
// manda é o manifest, que é o que o BL soma, então cada subgrupo é escalado
// para o total do seu grupo.
$sums = [];

foreach ($DOCS as $doc) {
    foreach (['gross', 'volume', 'net'] as $field) {
        if (isset($doc[$field])) {
            $sums[$doc['group']][$field] = ($sums[$doc['group']][$field] ?? 0) + $doc[$field];
        }
    }
}

foreach ($DOCS as $index => $doc) {
    foreach (['gross', 'volume', 'net'] as $field) {
        if (isset($doc[$field]) && ($sums[$doc['group']][$field] ?? 0) > 0) {
            $DOCS[$index][$field] = $doc[$field] * $GROUPS[$doc['group']][$field] / $sums[$doc['group']][$field];
        }
    }
}

$text = function (array $carton): string {
    return implode(' ', array_map(
        fn (array $c) => trim(($c['code'] ?? '').' '.($c['name'] ?? '')),
        $carton['contents'],
    ));
};

// Cada caixa do retrato cai em exatamente um subgrupo do documento.
$buckets = [];

foreach ($snapshot as $carton) {
    $haystack = $text($carton);
    $found = null;

    foreach ($DOCS as $index => $doc) {
        foreach ($doc['match'] as $needle) {
            if (str_contains($haystack, $needle)) {
                $found = $index;

                break 2;
            }
        }
    }

    if ($found === null) {
        fwrite(STDERR, "Caixa {$carton['label']} não casa com nenhum documento: {$haystack}\n");
        exit(1);
    }

    $buckets[$found][] = $carton;
}

// ── pesos e cubagem por caixa ───────────────────────────────────────────────
$lines = [];
$packages = [];
$groupTotals = [];

foreach ($DOCS as $index => $doc) {
    $cartons = $buckets[$index] ?? [];

    if (count($cartons) !== $doc['cartons']) {
        fwrite(STDERR, sprintf("%s (%s): o retrato tem %d caixas, o documento %d.\n",
            $doc['group'], implode('/', $doc['match']), count($cartons), $doc['cartons']));
        exit(1);
    }

    $group = $GROUPS[$doc['group']];

    // O líquido do subgrupo, quando o documento só dá o do grupo, sai do rateio
    // pelo bruto.
    $net = $doc['net'] ?? $group['net'] * $doc['gross'] / $group['gross'];

    $count = count($cartons);
    $unitGross = round($doc['gross'] / $count, 3);
    $unitNet = round($net / $count, 3);
    $unitVolume = round($doc['volume'] / $count, 6);

    // Caixa com mais de um item dentro não cabe no formato enxuto: vira volume
    // explícito. É o caso das lentes, que dividem a caixa entre dois modelos.
    // Como elas não têm o mesmo tamanho das outras, a cubagem do subgrupo é
    // rateada pelo tamanho que cada caixa tem hoje.
    $mixed = array_values(array_filter($cartons, fn (array $c) => count($c['contents']) > 1));
    $plain = array_values(array_filter($cartons, fn (array $c) => count($c['contents']) === 1));
    $base = array_sum(array_map(fn (array $c) => (float) $c['cbm'], $cartons));

    $volumeOf = fn (array $carton) => $mixed === [] || $base <= 0
        ? $unitVolume
        : round((float) $carton['cbm'] / $base * $doc['volume'], 6);

    // A sobra do arredondamento fica numa caixa só — a última das simples —
    // para o subgrupo fechar com o documento ao grama.
    $assigned = [
        'gross' => $unitGross * $count,
        'net' => $unitNet * $count,
        'volume' => array_sum(array_map($volumeOf, $cartons)),
    ];
    $rest = [
        'gross' => round($doc['gross'] - $assigned['gross'], 3),
        'net' => round($net - $assigned['net'], 3),
        'volume' => round($doc['volume'] - $assigned['volume'], 6),
    ];
    $oddCarton = array_filter($rest, fn ($v) => abs($v) > 1e-9) !== [] ? array_pop($plain) : null;

    if ($oddCarton === null) {
        $rest = ['gross' => 0.0, 'net' => 0.0, 'volume' => 0.0];
    }

    $ref = fn (array $content): array => [
        'model' => $content['code'] ?? null,
        'description' => $content['name'],
    ];

    foreach ($mixed as $carton) {
        $packages[] = [
            'type' => 'carton',
            'gross_weight' => $unitGross,
            'net_weight' => $unitNet,
            'volume' => $volumeOf($carton),
            'contents' => array_map(fn (array $c) => $ref($c) + ['pieces' => (int) $c['pieces']], $carton['contents']),
        ];
    }

    // Caixas simples iguais viram uma linha por (item, peças e cubagem).
    $grouped = [];

    foreach ($plain as $carton) {
        $content = $carton['contents'][0];
        $volume = $volumeOf($carton);
        $key = ($content['code'] ?? $content['name']).'|'.$content['pieces'].'|'.$volume;
        $grouped[$key] ??= ['content' => $content, 'volume' => $volume, 'packages' => 0];
        $grouped[$key]['packages']++;
    }

    foreach ($grouped as $entry) {
        $lines[] = $ref($entry['content']) + [
            'packages' => $entry['packages'],
            'pieces' => (int) $entry['content']['pieces'],
            'unit_net_weight' => $unitNet,
            'unit_gross_weight' => $unitGross,
            'unit_volume' => $entry['volume'],
            'dimensions' => $doc['dims'] ?? null,
        ];
    }

    if ($oddCarton !== null) {
        $content = $oddCarton['contents'][0];
        $lines[] = $ref($content) + [
            'packages' => 1,
            'pieces' => (int) $content['pieces'],
            'unit_net_weight' => round($unitNet + $rest['net'], 3),
            'unit_gross_weight' => round($unitGross + $rest['gross'], 3),
            'unit_volume' => round($volumeOf($oddCarton) + $rest['volume'], 6),
            'dimensions' => $doc['dims'] ?? null,
        ];
    }

    // O que o subgrupo realmente ficou valendo, para conferir contra o manifest.
    $groupTotals[$doc['group']]['cartons'] = ($groupTotals[$doc['group']]['cartons'] ?? 0) + $count;
    $groupTotals[$doc['group']]['gross'] = ($groupTotals[$doc['group']]['gross'] ?? 0) + $assigned['gross'] + $rest['gross'];
    $groupTotals[$doc['group']]['net'] = ($groupTotals[$doc['group']]['net'] ?? 0) + $assigned['net'] + $rest['net'];
    $groupTotals[$doc['group']]['volume'] = ($groupTotals[$doc['group']]['volume'] ?? 0) + $assigned['volume'] + $rest['volume'];
}

// ── conferência contra o manifest e o BL ────────────────────────────────────
foreach ($GROUPS as $key => $group) {
    $built = $groupTotals[$key] ?? ['cartons' => 0, 'gross' => 0, 'net' => 0, 'volume' => 0];

    foreach (['cartons', 'gross', 'volume'] as $field) {
        if (abs($built[$field] - $group[$field]) > 0.0005) {
            fwrite(STDERR, sprintf("%s: %s soma %.4f, o manifest declara %.4f.\n",
                $group['manifest'], $field, $built[$field], $group[$field]));
            exit(1);
        }
    }
}

$builtGross = array_sum(array_map(fn (array $l) => $l['unit_gross_weight'] * $l['packages'], $lines))
    + array_sum(array_map(fn (array $p) => $p['gross_weight'], $packages));
$builtNet = array_sum(array_map(fn (array $l) => $l['unit_net_weight'] * $l['packages'], $lines))
    + array_sum(array_map(fn (array $p) => $p['net_weight'], $packages));
$builtVolume = array_sum(array_map(fn (array $l) => $l['unit_volume'] * $l['packages'], $lines))
    + array_sum(array_map(fn (array $p) => $p['volume'], $packages));
$builtCartons = array_sum(array_map(fn (array $l) => $l['packages'], $lines)) + count($packages);

if ($builtCartons !== $BL['packages']
    || abs($builtGross - $BL['gross_weight']) > 0.0005
    || abs($builtVolume - $BL['volume']) > 0.0005) {
    fwrite(STDERR, sprintf("Fora do BL: %d volumes, %.4f kg, %.4f CBM.\n", $builtCartons, $builtGross, $builtVolume));
    exit(1);
}

$out = [
    'shipment' => $shipment,
    'source' => 'Packing lists da pasta do LWSS26118 (PL-DG, PL-BM263002, PL 8pcs by sea, Packing list for SEA) + manifest NGP4118602 + draft HBL LWSCN26118',
    'notes' => [
        'Os 1573 volumes já estavam certos no sistema e batem com o manifest grupo a grupo; o que foi refeito é peso e cubagem.',
        'A Agnes estava com o peso do cadastro (7,15 kg/caixa); o packing list da fábrica (PL-DG) declara 6,5 bruto e 5,55 líquido por caixa de 2 peças, que é o que o manifest usa.',
        'Solares (17), street lights (45) e notebooks de brinde (60) estavam sem peso nenhum; vieram dos packing lists e, no caso dos notebooks, do manifest.',
        'Notebooks: sem packing list, o líquido é 90% do bruto — a proporção do resto do embarque (decisão do Gui).',
        'Solares e street lights viajam em 4 estrados, mas o BL conta as 62 peças como volumes; ficam como caixas soltas para o embarque continuar com 1573.',
        'Stadium 1000W: o fornecedor declara 226,4 kg / 0,965 m³ e o manifest 225 / 1,000; vale o manifest.',
        'Lentes: o fornecedor descreve 5 caixas grandes e 1 pequena, o sistema tem 3 e 3; a repartição do sistema foi mantida e a cubagem do grupo rateada pelo tamanho — por isso ficam sem medida.',
    ],
    'containers' => [[
        'label' => 'CONT-001',
        'container_number' => $BL['container'],
        'type' => $BL['type'],
        'seal_number' => $BL['seal'],
        'sort_order' => 1,
        'declared' => [
            'packages' => $BL['packages'],
            'net_weight' => round($builtNet, 3),
            'gross_weight' => $BL['gross_weight'],
            'volume' => $BL['volume'],
        ],
        'lines' => $lines,
        'packages' => $packages,
    ]],
];

$target = __DIR__.'/../database/data/loading-lists/'.$shipment.'.json';

if (is_file($target)) {
    $target = substr($target, 0, -5).'.new.json';
}

file_put_contents($target, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

fwrite(STDOUT, sprintf("%d volumes, GW %.3f, NW %.3f, CBM %.3f\nGravado: %s\n",
    $builtCartons, $builtGross, $builtNet, $builtVolume, $target));
