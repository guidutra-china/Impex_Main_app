<?php

// PI-2026-00116 — correntes ASA estavam com unidade "m" e quantidade em metros, mas custo/venda
// por peça de 5 m (total 5× maior). Converte para peças: unidade pcs, quantidade ÷ 5, preços mantidos.
// Uso: DRY=1 php artisan tinker scripts/pi116_asa_chains_prod.php   (simula)
//      DRY=0 php artisan tinker scripts/pi116_asa_chains_prod.php   (grava)

use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Support\Facades\DB;

$dry = getenv('DRY') !== '0';

// descrição exata => metros atuais
$chains = [
    'Roller Chain ASA 2050 Simplex (double pitch)' => 5000,
    'Roller Chain ASA 40-1 Simplex' => 5000,
    'Roller Chain ASA 50-1 Simplex' => 2000,
    'Roller Chain ASA 60-1 Simplex' => 2000,
    'Roller Chain ASA 80-2 Duplex' => 100,
];

$pi = ProformaInvoice::where('reference', 'PI-2026-00116')->firstOrFail();
echo "PI {$pi->reference} id={$pi->id} status=".($pi->status->value ?? $pi->status).' total antes='.number_format($pi->total / 1e4, 2)."\n";
echo $dry ? "*** DRY RUN — nada será gravado ***\n" : "*** GRAVANDO ***\n";

$plan = [];
foreach ($chains as $desc => $meters) {
    $hits = $pi->items->filter(fn ($i) => $i->description === $desc);
    if ($hits->count() !== 1) {
        throw new RuntimeException("'{$desc}': esperava 1 linha, achei {$hits->count()} — abortado, nada gravado.");
    }
    $i = $hits->first();
    $pieces = intdiv($meters, 5);

    if ($i->unit === 'pcs' && (int) $i->quantity === $pieces) {
        printf("%-46s já aplicado (%d pcs)\n", $desc, $pieces);

        continue;
    }
    if ($i->unit !== 'm' || (int) $i->quantity !== $meters) {
        throw new RuntimeException("'{$desc}': esperava {$meters} m, achei {$i->quantity} {$i->unit} — abortado, nada gravado.");
    }

    $plan[] = [$i, $pieces];
    printf("%-46s %5d m → %4d pcs | venda %.4f (mantida) | total %.2f → %.2f\n",
        $desc, $meters, $pieces, $i->unit_price / 1e4,
        $meters * $i->unit_price / 1e4, $pieces * $i->unit_price / 1e4);
}

if (! $dry && $plan) {
    DB::transaction(function () use ($plan) {
        foreach ($plan as [$i, $pieces]) {
            $i->unit = 'pcs';
            $i->quantity = $pieces;
            $i->save();
        }
    });
    echo 'OK — '.count($plan).' linhas gravadas. Total da PI agora: '.number_format($pi->fresh()->total / 1e4, 2)."\n";
}
