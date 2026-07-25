<?php

declare(strict_types=1);

namespace Tests\Feature\AI\Import;

use App\Domain\AI\Import\ProductMatchSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductMatchSuggesterTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<int,mixed>  $matches
     */
    private function fake(array $matches): ProductMatchSuggester
    {
        return new class($matches) extends ProductMatchSuggester
        {
            public function __construct(private readonly array $matches)
            {
                parent::__construct();
            }

            protected function callModel(string $system, string $user): object
            {
                return (object) ['content' => [
                    (object) ['type' => 'tool_use', 'name' => 'sugerir_vinculos', 'input' => ['matches' => $this->matches]],
                ]];
            }
        };
    }

    public function test_returns_index_to_product_map_ignoring_ids_outside_catalog(): void
    {
        $suggester = $this->fake([
            ['index' => 0, 'product_id' => 10],
            ['index' => 1, 'product_id' => null],
            ['index' => 2, 'product_id' => 999], // fora do catálogo — descartado
        ]);

        $result = $suggester->suggest(
            [
                0 => ['description' => 'Halter hexagonal 5kg'],
                1 => ['description' => 'Produto desconhecido'],
                2 => ['description' => 'Outro'],
            ],
            [
                ['id' => 10, 'name' => 'Hexagonal Dumbbell 5kg', 'reference_code' => null, 'model_number' => null, 'aliases' => []],
                ['id' => 11, 'name' => 'Weight plate 5kg', 'reference_code' => null, 'model_number' => null, 'aliases' => []],
            ],
        );

        $this->assertSame([0 => 10], $result);
    }

    public function test_empty_input_short_circuits_without_api_call(): void
    {
        $suggester = new class extends ProductMatchSuggester
        {
            protected function callModel(string $system, string $user): object
            {
                throw new \RuntimeException('não deveria chamar a API');
            }
        };

        $this->assertSame([], $suggester->suggest([], [['id' => 1, 'name' => 'X', 'reference_code' => null, 'model_number' => null, 'aliases' => []]]));
        $this->assertSame([], $suggester->suggest([0 => ['description' => 'Y']], []));
    }

    public function test_payload_includes_specs_when_present_but_omits_when_absent(): void
    {
        $capturedUser = null;

        $suggester = new class(function (string $user) use (&$capturedUser): void {
            $capturedUser = $user;
        }) extends ProductMatchSuggester
        {

            public function __construct(private readonly \Closure $capture)
            {
                parent::__construct();
            }

            protected function callModel(string $system, string $user): object
            {
                ($this->capture)($user);

                return (object) ['content' => []];
            }
        };

        $suggester->suggest(
            [
                0 => ['description' => 'Halter hexagonal 5kg', 'specs' => '5kg, ferro fundido'],
                1 => ['description' => 'Produto desconhecido'],
            ],
            [['id' => 10, 'name' => 'X', 'reference_code' => null, 'model_number' => null, 'aliases' => []]],
        );

        $this->assertStringContainsString('"specs":"5kg, ferro fundido"', $capturedUser);
        $this->assertStringContainsString('{"index":1,"description":"Produto desconhecido"}', $capturedUser);
    }
}
