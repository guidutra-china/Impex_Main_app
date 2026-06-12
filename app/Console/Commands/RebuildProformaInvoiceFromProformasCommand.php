<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\ProformaInvoices\Models\ProformaInvoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One-off: rebuild PI-2026-00009 (production) from the two supplier proformas
 * (Gencrea PI2026GC-0008B + JG PI2026JG-0042), combined into a single client PI.
 *
 * Data source: database/data/pi-2026-00009-items.json (parsed offline from the
 * two Excel files, so production never needs PhpSpreadsheet nor the 27 MB binaries).
 *
 * Default run is a read-only verification (dry-run). Pass --apply to write.
 */
class RebuildProformaInvoiceFromProformasCommand extends Command
{
    protected $signature = 'pi:rebuild-from-proformas
                            {reference=PI-2026-00009 : PI reference to rebuild}
                            {--apply : Persist the changes (without it, read-only verification only)}
                            {--force : Proceed even if current items have downstream dependencies}';

    protected $description = 'Substitui todos os itens de uma Proforma Invoice pelos itens combinados das duas proformas de fornecedor (dataset JSON versionado).';

    /** Tables that reference proforma_invoice_items.id and would be affected by a replace-all. */
    private const DEPENDENT_TABLES = [
        'production_schedules' => 'cascadeOnDelete (apagaria a programação de produção)',
        'production_schedule_components' => 'cascadeOnDelete',
        'shipment_items' => 'restrict (bloquearia o delete)',
        'shipment_plan_items' => 'cascadeOnDelete (apagaria itens de plano de embarque)',
        'purchase_order_items' => 'nullOnDelete (apenas desvincula)',
    ];

    public function handle(): int
    {
        $reference = (string) $this->argument('reference');
        $apply = (bool) $this->option('apply');
        $force = (bool) $this->option('force');

        // --- Load dataset -----------------------------------------------------
        $path = database_path('data/pi-2026-00009-items.json');
        if (! is_file($path)) {
            $this->error("Dataset não encontrado: {$path}");

            return self::FAILURE;
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || empty($data['items'])) {
            $this->error('Dataset inválido ou vazio.');

            return self::FAILURE;
        }
        $items = $data['items'];
        $expectedTotal = (float) ($data['expected_total'] ?? 0);
        $currency = (string) ($data['currency'] ?? 'USD');

        // --- Locate PI --------------------------------------------------------
        $pi = ProformaInvoice::where('reference', $reference)->first();
        if (! $pi) {
            $this->error("Proforma Invoice '{$reference}' não encontrada neste banco.");
            $this->line('Confirme que está rodando no ambiente de produção (onde a PI existe).');

            return self::FAILURE;
        }

        $currentCount = $pi->items()->count();
        $currentTotalMinor = (int) $pi->items()->sum(DB::raw('unit_price * quantity'));

        // --- Product matching preview ----------------------------------------
        $codes = collect($items)->pluck('primary_code')->map(fn ($c) => strtoupper(trim((string) $c)))
            ->filter()->unique()->values();
        $existing = $this->existingProductMap($codes);
        $toMatch = $codes->filter(fn ($c) => isset($existing[$c]))->count();
        $toCreate = $codes->count() - $toMatch;

        $proposedTotal = round(collect($items)->sum(fn ($i) => $i['quantity'] * $i['unit_price']), 2);

        // --- Verification report ---------------------------------------------
        $statusLabel = $pi->status instanceof \BackedEnum ? $pi->status->value : (string) $pi->status;

        $this->info("==== Verificação: {$reference} (PI #{$pi->id}) ====");
        $this->table(['Campo', 'Atual', 'Proposto'], [
            ['Status', $statusLabel, '(inalterado)'],
            ['Currency', $pi->currency_code, $currency],
            ['Itens', (string) $currentCount, (string) count($items)],
            ['Total (USD)', number_format(Money::toMajor($currentTotalMinor), 2), number_format($proposedTotal, 2)],
        ]);
        $this->line("Produtos distintos no dataset: {$codes->count()}  |  já no catálogo: {$toMatch}  |  a criar: {$toCreate}");
        if (abs($proposedTotal - $expectedTotal) > 0.011) {
            $this->warn("Atenção: total calculado ({$proposedTotal}) difere do expected_total ({$expectedTotal}) do dataset.");
        }
        if (strtoupper($pi->currency_code) !== strtoupper($currency)) {
            $this->warn("A moeda da PI ({$pi->currency_code}) será ajustada para {$currency}.");
        }

        // --- Downstream dependency safeguard ---------------------------------
        $deps = $this->dependencyCounts($pi->id);
        if (! empty($deps)) {
            $this->newLine();
            $this->warn('Itens atuais possuem dependências downstream:');
            foreach ($deps as $table => $count) {
                $this->line("  - {$table}: {$count} registro(s)  [".self::DEPENDENT_TABLES[$table].']');
            }
            if (! $force) {
                $this->error('Abortando: a substituição apagaria/bloquearia esses registros. Reveja e, se intencional, use --force.');

                return self::FAILURE;
            }
            $this->warn('--force ativo: prosseguindo apesar das dependências.');
        }

        // --- Dry-run stops here ----------------------------------------------
        if (! $apply) {
            $this->newLine();
            $this->warn('Dry-run: nada foi gravado. Revise acima e rode com --apply para aplicar.');

            return self::SUCCESS;
        }

        // --- Apply ------------------------------------------------------------
        $this->newLine();
        $this->info('Aplicando alterações...');

        $created = 0;
        $matched = 0;

        DB::transaction(function () use ($pi, $items, $currency, &$created, &$matched) {
            if (strtoupper($pi->currency_code) !== strtoupper($currency)) {
                $pi->forceFill(['currency_code' => $currency])->save();
            }

            // Resolve/create products once per distinct code (case-insensitive).
            $resolved = []; // UPPER(code) => product_id
            foreach ($items as $it) {
                $code = strtoupper(trim((string) $it['primary_code']));
                if ($code === '' || isset($resolved[$code])) {
                    continue;
                }
                $product = Product::whereRaw('UPPER(reference_code) = ?', [$code])->first();
                if ($product) {
                    $matched++;
                } else {
                    $product = Product::create([
                        'name' => $code,
                        'reference_code' => $code,
                        'description' => $this->buildItemDescription($it),
                    ]);
                    $created++;
                }
                $resolved[$code] = $product->id;
            }

            // Replace all items.
            $pi->items()->delete();

            $sort = 0;
            foreach ($items as $it) {
                $sort++;
                $code = strtoupper(trim((string) $it['primary_code']));
                $spec = trim((string) ($it['description'] ?? ''));
                $pi->items()->create([
                    'product_id' => $resolved[$code] ?? null,
                    // description is VARCHAR(255): keep it to the part number; the long
                    // weight/application text goes into specifications (TEXT).
                    'description' => mb_substr(trim((string) ($it['part_no_raw'] ?? '')), 0, 255),
                    'specifications' => $spec !== '' ? $spec : null,
                    'quantity' => (int) $it['quantity'],
                    'unit' => 'pcs',
                    'unit_price' => Money::toMinor((float) $it['unit_price']),
                    'sort_order' => $sort,
                    'notes' => 'Origem: '.($it['source'] ?? ''),
                ]);
            }
        });

        $pi->refresh();
        $newCount = $pi->items()->count();
        $newTotalMinor = (int) $pi->items()->sum(DB::raw('unit_price * quantity'));

        $this->newLine();
        $this->info('Concluído.');
        $this->line("  Itens: {$newCount}");
        $this->line("  Produtos casados: {$matched}  |  criados: {$created}");
        $this->line('  Total (USD): '.number_format(Money::toMajor($newTotalMinor), 2));
        $this->line('  Confira no painel: PI '.$pi->reference.' deve ter '.count($items).' itens, total ~'.number_format($proposedTotal, 2).' USD.');

        return self::SUCCESS;
    }

    /** Map of UPPER(reference_code) => product id, for the given codes. */
    private function existingProductMap($codes): array
    {
        $map = [];
        Product::whereRaw('UPPER(reference_code) IN ('.$codes->map(fn () => '?')->join(',').')', $codes->all())
            ->get(['id', 'reference_code'])
            ->each(function ($p) use (&$map) {
                $map[strtoupper(trim((string) $p->reference_code))] = $p->id;
            });

        return $map;
    }

    /** Count current PI items referenced by each dependent table. */
    private function dependencyCounts(int $piId): array
    {
        $itemIds = DB::table('proforma_invoice_items')->where('proforma_invoice_id', $piId)->pluck('id');
        if ($itemIds->isEmpty()) {
            return [];
        }
        $out = [];
        foreach (array_keys(self::DEPENDENT_TABLES) as $table) {
            // Schema differs across environments; only probe tables/columns that exist.
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'proforma_invoice_item_id')) {
                continue;
            }
            $count = DB::table($table)->whereIn('proforma_invoice_item_id', $itemIds)->count();
            if ($count > 0) {
                $out[$table] = $count;
            }
        }

        return $out;
    }

    /** Build the item description: raw part number + (optional) application/spec text. */
    private function buildItemDescription(array $it): string
    {
        $raw = trim((string) ($it['part_no_raw'] ?? ''));
        $desc = trim((string) ($it['description'] ?? ''));

        return $desc !== '' ? ($raw."\n".$desc) : $raw;
    }
}
