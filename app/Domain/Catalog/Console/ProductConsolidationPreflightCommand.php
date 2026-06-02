<?php

namespace App\Domain\Catalog\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * READ-ONLY pre-flight audit for the Product field consolidation migrations
 * (drop `commercial_name`, make `reference_code` unique).
 *
 * This command NEVER writes to the database. It reports exactly what the
 * migrations will do and whether any blockers exist, so the consolidation can
 * be run on production safely. Run it BEFORE `php artisan migrate`.
 *
 * Usage:
 *   php artisan products:consolidation-preflight
 *   php artisan products:consolidation-preflight --show=50   (rows to list per section)
 */
class ProductConsolidationPreflightCommand extends Command
{
    protected $signature = 'products:consolidation-preflight
                            {--show=20 : Max rows to list per detail section}';

    protected $description = 'Read-only audit before the Product name/reference_code consolidation migrations (GO/NO-GO)';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('show'));
        $hasCommercial = Schema::hasColumn('products', 'commercial_name');

        $this->newLine();
        $this->line('<options=bold>══ Product Consolidation — Pre-flight (read-only) ══</>');
        $this->line('<fg=gray>Connection: '.config('database.default').' · DB: '.config('database.connections.'.config('database.default').'.database').'</>');
        $this->newLine();

        $total = DB::table('products')->count();
        $this->line("Total de produtos na tabela: <options=bold>{$total}</>");
        $this->line('Coluna commercial_name presente: '.($hasCommercial ? '<fg=yellow>sim</>' : '<fg=green>já removida</>'));
        $this->newLine();

        $blockers = [];
        $warnings = [];

        // ── AUDIT A: duplicate reference_code (blocks the UNIQUE migration) ──
        $this->line('<options=bold>Audit A — Duplicatas de reference_code</> <fg=gray>(bloqueia o índice único)</>');

        $dupes = DB::table('products')
            ->select('reference_code', DB::raw('COUNT(*) as c'))
            ->whereNotNull('reference_code')
            ->where('reference_code', '<>', '')
            ->groupBy('reference_code')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('c')
            ->get();

        if ($dupes->isEmpty()) {
            $this->line('  <fg=green>✓ Nenhuma duplicata. Seguro para o índice único.</>');
        } else {
            $blockers[] = 'reference_code possui '.$dupes->count().' valor(es) duplicado(s) — o índice único FALHARÁ.';
            $this->line('  <fg=red>✗ '.$dupes->count().' valor(es) de reference_code duplicado(s):</>');
            $rows = [];
            foreach ($dupes->take($limit) as $d) {
                $ids = DB::table('products')
                    ->where('reference_code', $d->reference_code)
                    ->orderBy('id')
                    ->pluck('id')
                    ->implode(', ');
                $rows[] = [$d->reference_code, $d->c, $ids];
            }
            $this->table(['reference_code', 'qtd', 'product ids'], $rows);
            if ($dupes->count() > $limit) {
                $this->line('  <fg=gray>… e mais '.($dupes->count() - $limit).' valor(es). Use --show=N para ver mais.</>');
            }
            $this->line('  <fg=yellow>→ Ação: renomear/mesclar até cada reference_code ficar único (ou esvaziar nos duplicados).</>');
        }
        $this->newLine();

        // ── AUDIT B: name vs commercial_name (only while column exists) ──
        $this->line('<options=bold>Audit B — name × commercial_name</> <fg=gray>(prévia do backfill)</>');

        if (! $hasCommercial) {
            $this->line('  <fg=green>✓ Coluna commercial_name já foi removida. Nada a auditar.</>');
        } else {
            // Backfill targets: name vazio/auto-gerado (== 'New Product' ou == nome da categoria)
            $backfillTargets = $this->backfillTargetsQuery()->count();

            // No-op: commercial presente mas name já é igual (nada muda)
            $noop = DB::table('products as p')
                ->whereNotNull('p.commercial_name')->where('p.commercial_name', '<>', '')
                ->whereColumn('p.name', '=', 'p.commercial_name')
                ->count();

            // Manual review: divergência real — name significativo E diferente de commercial_name,
            // e NÃO é alvo de backfill. Dropar a coluna PERDE o commercial_name desses produtos.
            $manualQuery = $this->manualReviewQuery();
            $manual = (clone $manualQuery)->count();

            $this->line("  Produtos com commercial_name preenchido que o backfill vai <options=bold>sobrescrever</> em name: <fg=cyan>{$backfillTargets}</>");
            $this->line("  Produtos onde name já == commercial_name (sem mudança): <fg=gray>{$noop}</>");
            $this->line('  Produtos com divergência real (revisão manual): '.($manual > 0 ? "<fg=yellow>{$manual}</>" : '<fg=green>0</>'));

            if ($manual > 0) {
                $warnings[] = $manual.' produto(s) têm name E commercial_name distintos e significativos — ao dropar a coluna, o commercial_name desses será PERDIDO.';
                $sample = (clone $manualQuery)
                    ->orderBy('p.id')
                    ->limit($limit)
                    ->get(['p.id', 'p.name', 'p.commercial_name']);
                $rows = $sample->map(fn ($p) => [
                    (string) $p->id,
                    $this->trunc($p->name),
                    $this->trunc($p->commercial_name),
                ])->toArray();
                $this->newLine();
                $this->table(['id', 'name (mantido)', 'commercial_name (será perdido)'], $rows);
                if ($manual > $limit) {
                    $this->line('  <fg=gray>… e mais '.($manual - $limit).' produto(s). Use --show=N para ver mais.</>');
                }
                $this->line('  <fg=yellow>→ Ação: para cada um, decida qual valor vira o name canônico (UPDATE manual) ANTES de migrar.</>');
            }
        }
        $this->newLine();

        // ── VERDICT ──
        $this->line('<options=bold>══ Veredito ══</>');

        if (! empty($blockers)) {
            $this->newLine();
            $this->line('<bg=red;fg=white> NO-GO </> Resolva os bloqueios antes de migrar:');
            foreach ($blockers as $b) {
                $this->line('  <fg=red>•</> '.$b);
            }
        }

        if (! empty($warnings)) {
            $this->newLine();
            $this->line('<bg=yellow;fg=black> ATENÇÃO </> Revisão recomendada antes de migrar:');
            foreach ($warnings as $w) {
                $this->line('  <fg=yellow>•</> '.$w);
            }
        }

        $this->newLine();
        if (empty($blockers) && empty($warnings)) {
            $this->line('<bg=green;fg=white> GO </> Banco pronto. Pode rodar as migrations com segurança.');
            $this->newLine();

            return self::SUCCESS;
        }

        if (empty($blockers)) {
            $this->line('<fg=yellow>Sem bloqueios rígidos. Reveja os avisos acima; se aceitos, pode migrar.</>');
            $this->newLine();

            return self::SUCCESS;
        }

        $this->newLine();

        return self::FAILURE;
    }

    /**
     * Rows the backfill UPDATE will overwrite (mirror of the migration's WHERE).
     */
    private function backfillTargetsQuery()
    {
        return DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNotNull('p.commercial_name')->where('p.commercial_name', '<>', '')
            ->where(function ($q) {
                $q->whereNull('p.name')
                    ->orWhere('p.name', '=', '')
                    ->orWhere('p.name', '=', 'New Product')
                    ->orWhereColumn('p.name', '=', 'c.name');
            });
    }

    /**
     * Rows with a genuine name/commercial_name divergence that the backfill will
     * NOT touch — these need a manual decision before the column is dropped.
     */
    private function manualReviewQuery()
    {
        return DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->whereNotNull('p.commercial_name')->where('p.commercial_name', '<>', '')
            ->whereNotNull('p.name')->where('p.name', '<>', '')
            ->where('p.name', '<>', 'New Product')
            ->whereColumn('p.name', '<>', 'p.commercial_name')
            ->where(function ($q) {
                $q->whereNull('c.name')->orWhereColumn('p.name', '<>', 'c.name');
            });
    }

    private function trunc(?string $v, int $len = 40): string
    {
        $v = (string) ($v ?? '');

        return mb_strlen($v) > $len ? mb_substr($v, 0, $len - 1).'…' : $v;
    }
}
