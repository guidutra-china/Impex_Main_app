<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use App\Domain\Infrastructure\Support\Money;
use App\Domain\SupplierQuotations\Actions\ImportSupplierQuotationWithInquiryAction;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * One-off: importa o PI YR-jjw-0706 (Hebei Yangrun, 2026/7/23 — "4th Order"
 * Daxiong/DeepFitness) como Inquiry + Supplier Quotation vinculadas, pelo mesmo
 * caminho do fluxo combinado do Assistant (ImportSupplierQuotationWithInquiryAction).
 *
 * Todos os produtos já existem: o mapa abaixo usa o NOME exato do produto,
 * resolvido dentro do pool de produtos do fornecedor (nunca por id — ids podem
 * divergir entre dev e produção). Qualquer nome sem match único aborta o import.
 *
 * Itens 10 e 11 do PDF (barras com anilhas retas e "W") apontam para os MESMOS
 * produtos "barras com anilhas — Xkg" — o cadastro não distingue reta/W; a
 * distinção fica na descrição da linha.
 *
 * Preços por peça derivados do line total do PDF (unit price do documento é
 * por kg nos halteres/anilhas). Total do documento: USD 7,371.73.
 *
 * Dry-run por default; use --apply para gravar.
 */
class ImportYangrunPi0706Command extends Command
{
    private const SUPPLIER_NAME = 'Hebei Yangrun Sports Equipment';

    private const CLIENT_NAME = 'DEEP FITNESS';

    private const SUPPLIER_REFERENCE = 'YR-jjw-0706';

    private const DOCUMENT_TOTAL_MINOR = 73_717_300; // USD 7,371.73

    protected $signature = 'supplier-quotations:import-yangrun-pi-0706
        {--file= : Caminho do PDF fonte (anexado à SQ criada)}
        {--user=guilherme@impex.ltd : E-mail do usuário que assina o import}
        {--apply : Persiste as mudanças (senão, dry-run)}';

    protected $description = 'Importa o PI YR-jjw-0706 (Hebei Yangrun / Deep Fitness) como Inquiry + SQ vinculadas';

    /**
     * Linhas do PDF: [nome exato do produto, descrição da linha, quantidade, custo unitário em minor units (4 casas)].
     *
     * @return list<array{0:string,1:string,2:int,3:int}>
     */
    private function lines(): array
    {
        return [
            // Item 1 — hex dumbbells, USD 0.70/kg
            ['Hexagonal Dumbbell 1kg', 'Hex rubber dumbbell — 1kg', 12, 7000],
            ['Hexagonal Dumbbell 2kg', 'Hex rubber dumbbell — 2kg', 12, 14000],
            ['Hexagonal Dumbbell 3kg', 'Hex rubber dumbbell — 3kg', 12, 21000],
            ['Hexagonal Dumbbell 4kg', 'Hex rubber dumbbell — 4kg', 12, 28000],
            ['Hexagonal Dumbbell 5kg', 'Hex rubber dumbbell — 5kg', 12, 35000],
            ['Hexagonal Dumbbell 6kg', 'Hex rubber dumbbell — 6kg', 12, 42000],
            ['Hexagonal Dumbbell 7kg', 'Hex rubber dumbbell — 7kg', 12, 49000],
            ['Hexagonal Dumbbell 8kg', 'Hex rubber dumbbell — 8kg', 12, 56000],
            ['Hexagonal Dumbbell 9kg', 'Hex rubber dumbbell — 9kg', 12, 63000],
            ['Hexagonal Dumbbell 10kg', 'Hex rubber dumbbell — 10kg', 12, 70000],
            // Item 2 — round rubber dumbbells, USD 0.94/kg
            ['Dumbbell 12.5kg', 'Rubber dumbbell — 12.5kg', 6, 117500],
            ['Dumbbell 15kg', 'Rubber dumbbell — 15kg', 6, 141000],
            ['Dumbbell 17.5kg', 'Rubber dumbbell — 17.5kg', 6, 164500],
            ['Dumbbell 20kg', 'Rubber dumbbell — 20kg', 6, 188000],
            ['Dumbbell 22.5kg', 'Rubber dumbbell — 22.5kg', 6, 211500],
            ['Dumbbell 25kg', 'Rubber dumbbell — 25kg', 6, 235000],
            ['Dumbbell 27.5kg', 'Rubber dumbbell — 27.5kg', 6, 258500],
            ['Dumbbell 30kg', 'Rubber dumbbell — 30kg', 6, 282000],
            ['Dumbbell 32.5kg', 'Rubber dumbbell — 32.5kg', 6, 305500],
            ['Dumbbell 35kg', 'Rubber dumbbell — 35kg', 6, 329000],
            // Item 3 — rubber weight plates, USD 0.97/kg
            ['Weight plate 2.5kg', 'Rubber weight plate — 2.5kg', 60, 24250],
            ['Weight plate 5kg', 'Rubber weight plate — 5kg', 60, 48500],
            ['Weight plate 10kg', 'Rubber weight plate — 10kg', 110, 97000],
            ['Weight plate 20kg', 'Rubber weight plate — 20kg', 75, 194000],
            // Itens 4–5 — racks
            ['Dumbell Rack', 'Dumbbell/weight storage rack 223.5*75*79.5cm (45kg)', 6, 918500],
            // PDF: unit 23.15 × 6 = 138.90, mas o total impresso é 138.89 —
            // derivado do line total (round(1388900/6)) como faz o resolver.
            ['Vertical Dumbbell Rack', '"A" type vertical dumbbell rack 69*47*108cm', 6, 231483],
            // Itens 6–9 — barras olímpicas
            ['W - 1.2 meters Olympic bearing bar', 'W - 1.2 meters Olympic bearing bar', 3, 205000],
            ['1.2m Bearing Barbell Bar', '1.2 meters Olympic bearing bar', 6, 210000],
            ['2.2m Olympic Bearing Barbell Bar', '2.2 meters Olympic bearing bar', 6, 320000],
            ['1.5m Olympic Bearing Barbell Bar', '1.5 meters Olympic bearing bar', 10, 225000],
            // Item 10 — straight barbell bar c/ anilhas (USD 99.25 por set de 100kg)
            ['barras com anilhas — 10kg', 'Straight barbell bar — 10kg', 3, 99250],
            ['barras com anilhas — 15kg', 'Straight barbell bar — 15kg', 3, 148875],
            ['barras com anilhas — 20kg', 'Straight barbell bar — 20kg', 3, 198500],
            ['barras com anilhas — 25kg', 'Straight barbell bar — 25kg', 3, 248125],
            ['barras com anilhas — 30kg', 'Straight barbell bar — 30kg', 3, 297750],
            // Item 11 — "W" barbell bar c/ anilhas (mesmos produtos, ver docblock)
            ['barras com anilhas — 10kg', '"W" barbell bar — 10kg', 3, 99250],
            ['barras com anilhas — 15kg', '"W" barbell bar — 15kg', 3, 148875],
            ['barras com anilhas — 20kg', '"W" barbell bar — 20kg', 3, 198500],
            ['barras com anilhas — 25kg', '"W" barbell bar — 25kg', 3, 248125],
            ['barras com anilhas — 30kg', '"W" barbell bar — 30kg', 3, 297750],
            // Itens 12–17 — racks e acessórios
            ['Rack / suporte para barras', 'Barbell rack 85*89*124cm', 3, 445000],
            ['Estação de treino / equipamento de academia com estrutura em tubo de aço', 'Plate tree 750x775x1150mm, main body 60x120x3mm rectangular steel pipe', 3, 1386700],
            ['Conjunto de pegadores/handles para cabo em aço', 'Conjunto de pegadores/handles para cabo em aço', 3, 346700],
            ['Double-ended rope (puxador de corda com duas alças)', 'Double-ended rope 70cm', 6, 29300],
            ['Puxador triangular V-bar cromado para cabo', 'Puxador triangular V-bar 180x112mm', 6, 44400],
            ['Barra "W" para Puxador', 'Barra "W" para Puxador 760*80mm', 3, 80000],
        ];
    }

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');

        $supplier = $this->findCompany(self::SUPPLIER_NAME);
        $client = $this->findCompany(self::CLIENT_NAME);

        if (! $supplier || ! $client) {
            return self::FAILURE;
        }

        $user = User::query()->where('email', $this->option('user'))->first();

        if (! $user) {
            $this->error('Usuário não encontrado: '.$this->option('user'));

            return self::FAILURE;
        }

        $filePath = (string) ($this->option('file') ?? '');

        if ($apply && ! is_file($filePath)) {
            $this->error('PDF fonte não encontrado (--file): '.($filePath ?: '(vazio)'));

            return self::FAILURE;
        }

        $productsByName = $this->supplierProductsByName($supplier);

        $itens = [];
        $tableRows = [];
        $missing = [];

        foreach ($this->lines() as [$productName, $description, $quantity, $unitCostMinor]) {
            $product = $productsByName[$this->normalizeName($productName)] ?? null;

            if ($product === null) {
                $missing[] = $productName;

                continue;
            }

            $tableRows[] = [
                $product->name.' (#'.$product->id.')',
                $description,
                $quantity,
                Money::format($unitCostMinor),
                Money::format($unitCostMinor * $quantity),
            ];

            $itens[] = [
                'status' => 'existente',
                'product_id' => $product->id,
                'part_no' => null,
                'description' => $description,
                'quantity' => $quantity,
                'unit' => 'pcs',
                'unit_cost_minor' => $unitCostMinor,
                'category_id' => null,
                'specifications' => null,
                'moq' => null,
                'lead_time_days' => null,
                'notes' => null,
            ];
        }

        if ($missing !== []) {
            $this->error('Produtos sem match único no pool do fornecedor — nada foi gravado:');
            foreach ($missing as $name) {
                $this->line('  - '.$name);
            }

            return self::FAILURE;
        }

        $totalMinor = array_sum(array_map(fn ($i) => $i['unit_cost_minor'] * $i['quantity'], $itens));

        $this->table(['Produto', 'Descrição', 'Qtd', 'Unit (USD)', 'Total (USD)'], $tableRows);

        $this->info(sprintf(
            'Fornecedor: %s (#%d) · Cliente: %s (#%d) · %d linhas · Total USD %s (documento: USD %s)',
            $supplier->name,
            $supplier->id,
            $client->name,
            $client->id,
            count($itens),
            Money::format($totalMinor),
            Money::format(self::DOCUMENT_TOTAL_MINOR),
        ));

        if (abs($totalMinor - self::DOCUMENT_TOTAL_MINOR) > 100) {
            $this->warn('Divergência acima de 1 centavo contra o total do documento — revise antes de aplicar.');
        }

        if (! $apply) {
            $this->warn('Dry-run: nada gravado. Use --apply --file=<caminho do PDF> para importar.');

            return self::SUCCESS;
        }

        $preview = [
            'fornecedor' => ['status' => 'existente', 'company_id' => $supplier->id, 'nome' => $supplier->name],
            'fornecedor_dados' => [],
            'cabecalho' => [
                'currency_code' => 'USD',
                'incoterm' => 'EXW',
                'supplier_reference' => self::SUPPLIER_REFERENCE,
                'notes' => 'Importado do PI YR-jjw-0706 (2026-07-23) — 4th Order Daxiong/Yangrun. '
                    .'G.W. total 6.840kg · 4,98004 m³ · pallet 150kg. Payment term: EXW.',
            ],
            'itens' => $itens,
        ];

        $result = app(ImportSupplierQuotationWithInquiryAction::class)(
            $preview,
            $user,
            $filePath,
            [],
            $client->id,
        );

        $this->info(sprintf(
            'Importado: %s + %s (%d itens).',
            $result['sq']->reference,
            $result['inquiry']->reference,
            count($itens),
        ));

        return self::SUCCESS;
    }

    private function findCompany(string $name): ?Company
    {
        $matches = Company::query()->where('name', 'like', '%'.$name.'%')->get();

        if ($matches->count() !== 1) {
            $this->error(sprintf('Empresa "%s": %d matches (esperava exatamente 1).', $name, $matches->count()));

            return null;
        }

        return $matches->first();
    }

    /**
     * Produtos do fornecedor indexados por nome normalizado (mesma regra do
     * ResolveSupplierQuotationDraft). Nomes duplicados dentro do fornecedor são
     * descartados — o match precisa ser único.
     *
     * @return array<string, Product>
     */
    private function supplierProductsByName(Company $supplier): array
    {
        $map = [];
        $duplicated = [];

        Product::query()
            ->whereHas('companies', fn ($q) => $q
                ->where('companies.id', $supplier->id)
                ->where('company_product.role', 'supplier'))
            ->get(['id', 'name'])
            ->each(function (Product $product) use (&$map, &$duplicated) {
                $key = $this->normalizeName($product->name);
                if ($key === '' || isset($duplicated[$key])) {
                    return;
                }
                if (isset($map[$key])) {
                    unset($map[$key]);
                    $duplicated[$key] = true;

                    return;
                }
                $map[$key] = $product;
            });

        return $map;
    }

    private function normalizeName(?string $name): string
    {
        return mb_strtoupper(preg_replace('/[^\p{L}\p{N}]+/u', '', (string) $name) ?? '');
    }
}
