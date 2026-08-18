<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\CompanyProduct;
use App\Domain\Catalog\Models\Product;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Reparo dos dados de identidade do produto depois da unificação da regra
 * (código da contraparte > model number > SKU) nos documentos.
 *
 * O problema histórico: a importação de cotação gravava o part number do
 * FORNECEDOR em products.model_number, que é global e é o 2º nível da regra do
 * CLIENTE — então o código do fornecedor aparecia na fatura do cliente. A
 * importação já foi corrigida; este comando cuida do que ficou no banco.
 *
 * Dry-run por padrão; --apply grava.
 */
class RepairProductIdentityCommand extends Command
{
    protected $signature = 'catalog:repair-product-identity
                            {--apply : Grava as alterações (o padrão é apenas relatar)}
                            {--mode=audit : audit | supplier-codes | clear-model-number | client-ncm}
                            {--company= : Restringe a uma empresa}
                            {--skip= : IDs de pivot separados por vírgula que não devem ser tocados}';

    protected $description = 'Audita e repara a identidade dos produtos (códigos de fornecedor, model_number vazado e NCM do cliente)';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');
        $apply = (bool) $this->option('apply');

        if (! in_array($mode, ['audit', 'supplier-codes', 'clear-model-number', 'client-ncm'], true)) {
            $this->error("Modo inválido: {$mode}");

            return self::FAILURE;
        }

        if (! $apply) {
            $this->warn('DRY-RUN — nada será gravado. Use --apply para persistir.');
            $this->newLine();
        }

        return match ($mode) {
            'audit' => $this->audit(),
            'supplier-codes' => $this->backfillSupplierCodes($apply),
            'clear-model-number' => $this->clearLeakedModelNumbers($apply),
            'client-ncm' => $this->backfillClientNcm($apply),
        };
    }

    /**
     * Relatório de revisão antes de qualquer escrita.
     */
    private function audit(): int
    {
        $leaking = $this->productsLeakingSupplierCode();

        $this->info('1) Produtos que hoje imprimem um part number de fornecedor para o cliente');
        $this->line('   (model_number = reference_code, com fornecedor vinculado e sem código do cliente)');
        $this->line('   Total: '.$leaking->count());

        if ($leaking->isNotEmpty()) {
            $this->table(
                ['ID', 'SKU', 'Model number', 'Produto'],
                $leaking->take(20)->map(fn (Product $p) => [
                    $p->id, $p->sku, $p->model_number, \Illuminate\Support\Str::limit($p->name, 40),
                ])->all(),
            );

            if ($leaking->count() > 20) {
                $this->line('   … e mais '.($leaking->count() - 20).'.');
            }
        }

        $this->newLine();

        $backfillable = $this->backfillableSupplierPivots();
        $conflicts = $this->supplierPivotConflicts();

        $this->info('2) Pivots de fornecedor preenchíveis a partir do reference_code');
        $this->line('   Total: '.$backfillable->count().' | Conflitos (produto com 2+ fornecedores): '.$conflicts->count());

        $this->newLine();

        $ncmCandidates = $this->clientNcmCandidates();

        $this->info('3) Produtos com hs_code que poderiam virar NCM do cliente');
        $this->line('   Total: '.$ncmCandidates->count());
        $this->line('   ATENÇÃO: os hs_code atuais costumam ser HS de 6 dígitos (origem), não NCM.');
        $this->line('   Revise antes de rodar --mode=client-ncm --apply.');

        return self::SUCCESS;
    }

    /**
     * Preenche o external_code em branco do pivot do fornecedor a partir do
     * reference_code do produto — só quando existe exatamente um fornecedor,
     * para não adivinhar de quem é o código.
     */
    private function backfillSupplierCodes(bool $apply): int
    {
        $skip = $this->skipIds();
        $pivots = $this->backfillableSupplierPivots()->reject(fn ($row) => in_array((int) $row->pivot_id, $skip, true));
        $conflicts = $this->supplierPivotConflicts();

        $this->info('Pivots a preencher: '.$pivots->count());

        foreach ($pivots->take(20) as $row) {
            $this->line("  pivot #{$row->pivot_id}: {$row->reference_code}");
        }

        if ($conflicts->isNotEmpty()) {
            $this->warn('Ignorados por conflito (produto com 2+ fornecedores): '.$conflicts->count());
            foreach ($conflicts->take(10) as $row) {
                $this->line("  produto #{$row->product_id} ({$row->reference_code})");
            }
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($pivots as $row) {
            $updated += CompanyProduct::where('id', $row->pivot_id)
                ->whereNull('external_code')
                ->update(['external_code' => $row->reference_code]);
        }

        $this->info("Gravado: {$updated} pivots.");

        return self::SUCCESS;
    }

    /**
     * Limpa o model_number que na verdade é código de fornecedor.
     *
     * NÃO rodar antes de preencher os códigos do CLIENTE: sem eles, o documento
     * do cliente cai do part number do fornecedor (que ele já viu) para o nosso
     * SKU interno — pior do que está. Ordem: supplier-codes →
     * inquiries:backfill-client-codes → clear-model-number.
     */
    private function clearLeakedModelNumbers(bool $apply): int
    {
        $products = $this->productsLeakingSupplierCode()
            ->filter(fn (Product $p) => $p->companies
                ->where('pivot.role', 'supplier')
                ->contains(fn ($c) => filled($c->pivot->external_code)));

        $this->info('Produtos cujo model_number já está registrado no pivot do fornecedor: '.$products->count());

        if (! $apply) {
            $this->warn('Confirme antes que os clientes desses produtos já têm external_code.');

            return self::SUCCESS;
        }

        $updated = Product::whereIn('id', $products->pluck('id'))->update(['model_number' => null]);

        $this->info("Gravado: {$updated} produtos.");

        return self::SUCCESS;
    }

    /**
     * Copia products.hs_code para o external_ncm do cliente quando o produto
     * tem exatamente um cliente vinculado. Entregue mas não recomendado sem
     * revisão: os hs_code atuais costumam ser HS de 6 dígitos.
     */
    private function backfillClientNcm(bool $apply): int
    {
        $candidates = $this->clientNcmCandidates();
        $skip = $this->skipIds();
        $candidates = $candidates->reject(fn ($row) => in_array((int) $row->pivot_id, $skip, true));

        $this->info('Vínculos de cliente a receber NCM: '.$candidates->count());

        foreach ($candidates->take(20) as $row) {
            $this->line("  pivot #{$row->pivot_id}: {$row->hs_code}");
        }

        if (! $apply) {
            return self::SUCCESS;
        }

        $updated = 0;
        foreach ($candidates as $row) {
            $updated += CompanyProduct::where('id', $row->pivot_id)
                ->whereNull('external_ncm')
                ->update(['external_ncm' => preg_replace('/\D/', '', (string) $row->hs_code)]);
        }

        $this->info("Gravado: {$updated} vínculos.");

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function productsLeakingSupplierCode(): \Illuminate\Support\Collection
    {
        return Product::query()
            ->with('companies')
            ->whereNotNull('model_number')
            ->whereColumn('model_number', 'reference_code')
            ->whereHas('companies', fn ($q) => $q->where('company_product.role', 'supplier'))
            // Sem código do cliente é onde o vazamento aparece de fato: com
            // código do cliente, o model_number nunca chega a ser impresso.
            ->whereDoesntHave('companies', fn ($q) => $q->where('company_product.role', 'client')
                ->whereNotNull('company_product.external_code'))
            ->when($this->option('company'), fn ($q, $companyId) => $q->whereHas(
                'companies',
                fn ($sub) => $sub->where('companies.id', $companyId)
            ))
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function backfillableSupplierPivots(): \Illuminate\Support\Collection
    {
        return DB::table('company_product as cp')
            ->join('products as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.role', 'supplier')
            ->whereNull('cp.external_code')
            ->whereNotNull('p.reference_code')
            ->when($this->option('company'), fn ($q, $companyId) => $q->where('cp.company_id', $companyId))
            // Só produtos com exatamente um fornecedor: com dois, não dá para
            // saber de quem é o reference_code.
            ->whereRaw('(select count(*) from company_product c2 where c2.product_id = cp.product_id and c2.role = ?) = 1', ['supplier'])
            ->select('cp.id as pivot_id', 'cp.company_id', 'cp.product_id', 'p.reference_code')
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function supplierPivotConflicts(): \Illuminate\Support\Collection
    {
        return DB::table('company_product as cp')
            ->join('products as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.role', 'supplier')
            ->whereNull('cp.external_code')
            ->whereNotNull('p.reference_code')
            ->whereRaw('(select count(*) from company_product c2 where c2.product_id = cp.product_id and c2.role = ?) > 1', ['supplier'])
            ->select('cp.product_id', 'p.reference_code')
            ->distinct()
            ->get();
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function clientNcmCandidates(): \Illuminate\Support\Collection
    {
        return DB::table('company_product as cp')
            ->join('products as p', 'p.id', '=', 'cp.product_id')
            ->where('cp.role', 'client')
            ->whereNull('cp.external_ncm')
            ->whereNotNull('p.hs_code')
            ->where('p.hs_code', '!=', '')
            ->when($this->option('company'), fn ($q, $companyId) => $q->where('cp.company_id', $companyId))
            ->whereRaw('(select count(*) from company_product c2 where c2.product_id = cp.product_id and c2.role = ?) = 1', ['client'])
            ->select('cp.id as pivot_id', 'cp.company_id', 'cp.product_id', 'p.hs_code')
            ->get();
    }

    /**
     * @return array<int, int>
     */
    private function skipIds(): array
    {
        $skip = (string) ($this->option('skip') ?? '');

        return collect(explode(',', $skip))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->all();
    }
}
