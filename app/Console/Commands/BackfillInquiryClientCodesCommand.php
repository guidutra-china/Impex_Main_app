<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Inquiries\Models\Inquiry;
use Illuminate\Console\Command;

/**
 * Grava os códigos de cliente das descrições dos inquiry items como
 * external_code no pivot company_product (role client) do cliente da inquiry.
 *
 * Itens extraídos de documentos carregam o código do cliente na descrição
 * (ex: "DPF-LT012"). Depois de vinculados a produtos, esse código vira o
 * MODEL NO oficial do cliente nos documentos (CI/PL) quando registrado como
 * external_code no pivot. Regras:
 *  - pivot inexistente            → cria com o external_code
 *  - pivot com external_code vazio → preenche
 *  - pivot com o mesmo código      → ignora
 *  - pivot com código DIFERENTE    → reporta conflito e NÃO sobrescreve
 *
 * Dry-run por default; use --apply para gravar.
 */
class BackfillInquiryClientCodesCommand extends Command
{
    protected $signature = 'inquiries:backfill-client-codes
        {inquiry : Referência da inquiry (ex: INQ-2026-00063)}
        {--apply : Persiste as mudanças (senão, dry-run)}';

    protected $description = 'Grava as descrições dos inquiry items vinculados como external_code do cliente no pivot company_product';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $inquiry = Inquiry::query()->where('reference', $this->argument('inquiry'))->first();

        if (! $inquiry) {
            $this->error('Inquiry não encontrada: '.$this->argument('inquiry'));

            return self::FAILURE;
        }

        $items = $inquiry->items()
            ->whereNotNull('product_id')
            ->with('product:id,name,sku')
            ->orderBy('sort_order')
            ->get();

        if ($items->isEmpty()) {
            $this->info('Nenhum item com produto vinculado nessa inquiry.');

            return self::SUCCESS;
        }

        $creates = [];
        $fills = [];
        $unchanged = 0;
        $conflicts = [];
        $skipped = 0;

        foreach ($items as $item) {
            $code = $this->normalizeCode((string) $item->description);

            if ($code === '') {
                $skipped++;

                continue;
            }

            $pivot = CompanyProduct::query()
                ->where('company_id', $inquiry->company_id)
                ->where('product_id', $item->product_id)
                ->where('role', 'client')
                ->first();

            if ($pivot === null) {
                $creates[] = [$item, $code];
            } elseif (blank($pivot->external_code)) {
                $fills[] = [$item, $code, $pivot];
            } elseif ($this->normalizeCode($pivot->external_code) === $code) {
                $unchanged++;
            } else {
                $conflicts[] = [$item, $code, $pivot->external_code];
            }
        }

        $this->info(sprintf(
            'Itens vinculados: %d — pivots a criar: %d | a preencher: %d | já corretos: %d | conflitos: %d | sem código: %d',
            $items->count(),
            count($creates),
            count($fills),
            $unchanged,
            count($conflicts),
            $skipped,
        ));

        $rows = collect($creates)->map(fn (array $row) => [
            'criar',
            $row[0]->product->sku,
            mb_strimwidth($row[0]->product->name, 0, 35, '…'),
            $row[1],
        ])->concat(collect($fills)->map(fn (array $row) => [
            'preencher',
            $row[0]->product->sku,
            mb_strimwidth($row[0]->product->name, 0, 35, '…'),
            $row[1],
        ]));

        if ($rows->isNotEmpty()) {
            $this->table(['Ação', 'SKU', 'Produto', 'external_code'], $rows->all());
        }

        foreach ($conflicts as [$item, $code, $existing]) {
            $this->warn(sprintf(
                'Conflito: produto %s já tem external_code "%s" (item sugere "%s") — deixado como está.',
                $item->product->sku,
                $existing,
                $code,
            ));
        }

        if ($creates === [] && $fills === []) {
            $this->info('Nada a gravar.');

            return self::SUCCESS;
        }

        if (! $apply) {
            $this->warn('DRY-RUN: nada foi gravado. Rode com --apply para persistir.');

            return self::SUCCESS;
        }

        foreach ($creates as [$item, $code]) {
            CompanyProduct::create([
                'company_id' => $inquiry->company_id,
                'product_id' => $item->product_id,
                'role' => 'client',
                'external_code' => $code,
                'unit_price' => 0,
            ]);
        }

        foreach ($fills as [$item, $code, $pivot]) {
            $pivot->external_code = $code;
            $pivot->save();
        }

        $this->info(sprintf('✓ Pivots criados: %d | preenchidos: %d.', count($creates), count($fills)));

        return self::SUCCESS;
    }

    protected function normalizeCode(string $code): string
    {
        return mb_strtoupper(preg_replace('/\s+/', '', trim($code)) ?? '');
    }
}
