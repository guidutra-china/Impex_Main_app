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
        $this->line('<options=bold>Audit B — name × commercial_name</> <fg=gray>(prévia do merge sem perda)</>');

        if (! $hasCommercial) {
            $this->line('  <fg=green>✓ Coluna commercial_name já foi removida. Nada a auditar.</>');
        } else {
            // Mirror exato do merge que a migration aplica antes de dropar a coluna.
            $rows = DB::table('products')
                ->whereNotNull('commercial_name')->where('commercial_name', '<>', '')
                ->orderBy('id')
                ->get(['id', 'name', 'commercial_name']);

            $keep = 0;     // name já contém / é igual → mantém name
            $useComm = 0;  // commercial contém name → name vira commercial
            $merge = [];   // disjuntos → "name — commercial"

            foreach ($rows as $p) {
                $name = trim((string) $p->name);
                $comm = trim((string) $p->commercial_name);
                if ($comm === '') {
                    continue;
                }
                if ($name === '') {
                    $useComm++;

                    continue;
                }
                $n = $this->norm($name);
                $c = $this->norm($comm);
                if ($n === $c || str_contains($n, $c)) {
                    $keep++;
                } elseif (str_contains($c, $n)) {
                    $useComm++;
                } else {
                    $merge[] = [(string) $p->id, $this->trunc($name.' — '.$comm, 70)];
                }
            }

            $this->line('  Com commercial_name preenchido: <options=bold>'.$rows->count().'</>');
            $this->line('  → mantêm o name atual (já contém/igual): <fg=gray>'.$keep.'</>');
            $this->line('  → name passa a usar o commercial (mais completo): <fg=cyan>'.$useComm.'</>');
            $this->line('  → serão MESCLADOS "name — commercial" (disjuntos): <fg=cyan>'.count($merge).'</>');
            $this->line('  <fg=green>✓ Merge sem perda — nenhum dado é descartado.</>');

            if (! empty($merge)) {
                $this->newLine();
                $this->table(['id', 'name resultante (preview)'], array_slice($merge, 0, $limit));
                if (count($merge) > $limit) {
                    $this->line('  <fg=gray>… e mais '.(count($merge) - $limit).' merge(s). Use --show=N para ver mais.</>');
                }
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

    private function norm(string $s): string
    {
        return preg_replace('/\s+/', '', mb_strtolower($s));
    }

    private function trunc(?string $v, int $len = 40): string
    {
        $v = (string) ($v ?? '');

        return mb_strlen($v) > $len ? mb_substr($v, 0, $len - 1).'…' : $v;
    }
}
