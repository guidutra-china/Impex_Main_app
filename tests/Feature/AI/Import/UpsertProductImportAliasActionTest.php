<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\UpsertProductImportAliasAction;
use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpsertProductImportAliasActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_updates_and_guards(): void
    {
        $company = Company::factory()->create();
        $a = Product::factory()->create();
        $b = Product::factory()->create();
        $action = new UpsertProductImportAliasAction;

        // Cria.
        $alias = $action->execute($company->id, $a->id, 'Hex dumbbell — 5kg', 'import_confirm');
        $this->assertNotNull($alias);
        $this->assertSame('HEXDUMBBELL5KG', $alias->alias_normalized);

        // Mesma descrição, outro produto: a confirmação mais recente vence (sem duplicar).
        $action->execute($company->id, $b->id, 'HEX DUMBBELL 5kg', 'import_confirm');
        $this->assertDatabaseCount('product_import_aliases', 1);
        $this->assertSame($b->id, ProductImportAlias::sole()->product_id);
        $this->assertSame('HEX DUMBBELL 5kg', ProductImportAlias::sole()->alias);

        // Guarda: normalizado < 3 chars não grava.
        $this->assertNull($action->execute($company->id, $a->id, '5', 'import_confirm'));
        $this->assertNull($action->execute($company->id, $a->id, '  ', 'import_confirm'));
        $this->assertDatabaseCount('product_import_aliases', 1);
    }
}
