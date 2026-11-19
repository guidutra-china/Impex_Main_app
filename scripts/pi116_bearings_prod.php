<?php

// PI-2026-00116 — novos custos KRB (BEARINGS.xlsx, 2026-11-19); venda mantém a margem atual de cada linha.
// Uso: DRY=1 php artisan tinker scripts/pi116_bearings_prod.php   (simula)
//      DRY=0 php artisan tinker scripts/pi116_bearings_prod.php   (grava)

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Facades\DB;

$dry = getenv('DRY') !== '0';

// trecho da descrição => [quantidade esperada, novo custo em escala 10000]
$new = [
    '6204-2RS' => [10000, 2150],
    'Bearing 30204' => [10000, 3500],
    '6206-2RS' => [10000, 3400],
    '6202-2RS' => [10000, 1330],
    'Bearing 30203' => [1000, 3300],
    '6006-2RS' => [5000, 2700],
    '6203-2RS' => [5000, 1660],
    'Ball Bearing 6005-2RS' => [1000, 2300],
    'LM67048' => [1000, 5600],
    '205KRRBAH02' => [2000, 7300],
    'Square Bore 25x47x12' => [1000, 5000],
];

$pi = ProformaInvoice::where('reference', 'PI-2026-00116')->firstOrFail();
echo "PI {$pi->reference} id={$pi->id} status=".($pi->status->value ?? $pi->status)." moeda={$pi->currency_code}\n";
echo $dry ? "*** DRY RUN — nada será gravado ***\n" : "*** GRAVANDO ***\n";

$plan = [];
foreach ($new as $key => [$qty, $cost]) {
    $hits = $pi->items->filter(fn ($i) => str_contains($i->description, $key));
    if ($hits->count() !== 1) {
        throw new RuntimeException("'{$key}': esperava 1 linha, achei {$hits->count()} — abortado, nada gravado.");
    }
    $i = $hits->first();
    if ((int) $i->quantity !== $qty) {
        throw new RuntimeException("'{$key}': qtd {$i->quantity} ≠ {$qty} — abortado, nada gravado.");
    }
    if ((int) $i->unit_cost <= 0) {
        throw new RuntimeException("'{$key}': custo atual zero — abortado, nada gravado.");
    }
    $price = (int) round($cost * $i->unit_price / $i->unit_cost);
    $plan[] = [$i, $cost, $price];
    printf("%-55s custo %.4f → %.4f | venda %.4f → %.4f | margem %.2f%% → %.2f%%%s\n",
        mb_substr($i->description, 0, 55), $i->unit_cost / 1e4, $cost / 1e4, $i->unit_price / 1e4, $price / 1e4,
        ($i->unit_price / $i->unit_cost - 1) * 100, ($price / $cost - 1) * 100,
        ((int) $i->unit_cost === $cost && (int) $i->unit_price === $price) ? '  (já aplicado)' : '');
}

if (! $dry) {
    DB::transaction(function () use ($plan) {
        foreach ($plan as [$i, $cost, $price]) {
            $i->unit_cost = $cost;
            $i->unit_price = $price;
            $i->save(); // hook do model recalcula unit_cost_in_document_currency
        }
    });
    echo 'OK — '.count($plan)." linhas gravadas.\n";
}
