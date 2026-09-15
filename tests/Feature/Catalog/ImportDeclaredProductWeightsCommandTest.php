<?php

namespace Tests\Feature\Catalog;

use App\Domain\Catalog\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportDeclaredProductWeightsCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $file;

    protected function setUp(): void
    {
        parent::setUp();

        $this->file = tempnam(sys_get_temp_dir(), 'weights').'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->file);

        parent::tearDown();
    }

    private function declare(array $weights): void
    {
        file_put_contents($this->file, json_encode(['_' => 'teste', 'weights' => $weights]));
    }

    private function import(string $extra = ''): \Illuminate\Testing\PendingCommand
    {
        return $this->artisan("products:import-declared-weights --file={$this->file} {$extra}");
    }

    public function test_writes_unit_net_weight_and_the_master_carton_from_the_file(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-1903', 'name' => 'Incline chest press']);
        $this->declare(['JG-1903' => ['net' => 166.0, 'gross' => 191.0]]);

        $this->import('--apply')->assertSuccessful();

        $product->refresh();
        $this->assertEquals(166.0, $product->specification->net_weight);
        $this->assertSame(1, $product->packaging->pcs_per_carton);
        $this->assertEquals(166.0, $product->packaging->carton_net_weight);
        $this->assertEquals(191.0, $product->packaging->carton_weight);
    }

    public function test_fills_only_the_missing_fields_of_a_product_that_already_has_the_same_net_weight(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-6802']);
        $product->specification()->create(['net_weight' => 56.0]);
        $product->packaging()->create(['pcs_per_carton' => 1, 'carton_net_weight' => 56.0]);
        $this->declare(['JG-6802' => ['net' => 56.0, 'gross' => 61.0]]);

        $this->import('--apply')->assertSuccessful();

        $this->assertEquals(61.0, $product->refresh()->packaging->carton_weight);
    }

    public function test_a_product_whose_net_weight_differs_from_the_file_is_reported_as_a_conflict_and_left_alone(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-9800A']);
        $product->specification()->create(['net_weight' => 200.0]);
        $this->declare(['JG-9800A' => ['net' => 165.0, 'gross' => 216.0]]);

        $this->import('--apply')->expectsOutputToContain('conflito')->assertSuccessful();

        $product->refresh();
        $this->assertEquals(200.0, $product->specification->net_weight);
        $this->assertNull($product->packaging);
    }

    public function test_overwrite_replaces_the_conflicting_weights(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-9800A']);
        $product->specification()->create(['net_weight' => 200.0]);
        $product->packaging()->create(['pcs_per_carton' => 1, 'carton_net_weight' => 200.0, 'carton_weight' => 251.0]);
        $this->declare(['JG-9800A' => ['net' => 165.0, 'gross' => 216.0]]);

        $this->import('--overwrite --apply')->assertSuccessful();

        $product->refresh();
        $this->assertEquals(165.0, $product->specification->net_weight);
        $this->assertEquals(165.0, $product->packaging->carton_net_weight);
        $this->assertEquals(216.0, $product->packaging->carton_weight);
    }

    public function test_an_unknown_sku_is_reported_and_does_not_stop_the_others(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-1643']);
        $this->declare([
            'JG-NOPE' => ['net' => 1.0, 'gross' => 2.0],
            'JG-1643' => ['net' => 45.0, 'gross' => 50.0],
        ]);

        $this->import('--apply')->expectsOutputToContain('JG-NOPE')->assertSuccessful();

        $this->assertEquals(45.0, $product->refresh()->specification->net_weight);
    }

    public function test_dry_run_writes_nothing(): void
    {
        $product = Product::factory()->create(['sku' => 'JG-1643']);
        $this->declare(['JG-1643' => ['net' => 45.0, 'gross' => 50.0]]);

        $this->import()->expectsOutputToContain('Dry-run')->assertSuccessful();

        $product->refresh();
        $this->assertNull($product->specification);
        $this->assertNull($product->packaging);
    }
}
