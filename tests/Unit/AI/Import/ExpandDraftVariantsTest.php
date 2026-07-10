<?php

declare(strict_types=1);

namespace Tests\Unit\AI\Import;

use App\Domain\AI\Import\ExpandDraftVariants;
use Tests\TestCase;

class ExpandDraftVariantsTest extends TestCase
{
    public function test_items_without_variants_pass_through_unchanged(): void
    {
        $draft = ['itens' => [['part_no' => 'A-1', 'description' => 'Widget', 'quantity' => 2, 'unit_price' => 5.0]]];

        $out = (new ExpandDraftVariants)->expand($draft);

        $this->assertSame($draft['itens'], $out['itens']);
    }

    public function test_expands_variants_into_flat_rows_with_distinct_part_nos(): void
    {
        $draft = ['itens' => [
            [
                'part_no' => 'KB100', 'description' => 'Cast Iron Kettlebell', 'unit' => 'pcs',
                'page' => 1, 'specifications' => 'painted', 'descricao_inferida' => true,
                'quantity' => 0, 'unit_price' => 0,
                'variantes' => [
                    ['rotulo' => '8kg', 'quantity' => 20, 'unit_price' => 8.18, 'line_total' => 184.82],
                    ['rotulo' => '10kg', 'quantity' => 20, 'unit_price' => 10.22],
                ],
            ],
            ['part_no' => 'TR-40', 'description' => 'Trampoline', 'quantity' => 10, 'unit_price' => 12.8],
        ]];

        $out = (new ExpandDraftVariants)->expand($draft);

        $this->assertCount(3, $out['itens']);

        [$v1, $v2, $plain] = $out['itens'];

        $this->assertSame('Cast Iron Kettlebell — 8kg', $v1['description']);
        $this->assertSame('KB100-8KG', $v1['part_no']);
        $this->assertSame(20, $v1['quantity']);
        $this->assertSame(8.18, $v1['unit_price']);
        $this->assertSame(184.82, $v1['line_total']);
        // Herança do pai: page, specifications, unit, flag de descrição inferida.
        $this->assertSame(1, $v1['page']);
        $this->assertSame('painted', $v1['specifications']);
        $this->assertSame('pcs', $v1['unit']);
        $this->assertTrue($v1['descricao_inferida']);
        $this->assertSame(0, $v1['_variant_group']);
        $this->assertArrayNotHasKey('variantes', $v1);

        $this->assertSame('KB100-10KG', $v2['part_no']);
        $this->assertSame(0, $v2['_variant_group']);

        $this->assertSame('Trampoline', $plain['description']);
        $this->assertArrayNotHasKey('_variant_group', $plain);
    }

    public function test_variant_own_part_no_wins_and_label_already_in_description_is_not_repeated(): void
    {
        $draft = ['itens' => [[
            'part_no' => 'MB', 'description' => 'Medicine Ball 4kg', 'quantity' => 0,
            'variantes' => [['rotulo' => '4kg', 'part_no' => 'MB-CUSTOM', 'quantity' => 10]],
        ]]];

        $out = (new ExpandDraftVariants)->expand($draft);

        $this->assertSame('MB-CUSTOM', $out['itens'][0]['part_no']);
        $this->assertSame('Medicine Ball 4kg', $out['itens'][0]['description']);
    }

    public function test_parent_without_part_no_yields_null_and_blank_label_gets_positional_suffix(): void
    {
        $draft = ['itens' => [
            [
                'description' => 'Slam Ball', 'quantity' => 0,
                'variantes' => [['rotulo' => '6kg', 'quantity' => 10]],
            ],
            [
                'part_no' => 'DB', 'description' => 'Dumbbell', 'quantity' => 0,
                'variantes' => [['rotulo' => '', 'quantity' => 5], ['rotulo' => '', 'quantity' => 7]],
            ],
        ]];

        $out = (new ExpandDraftVariants)->expand($draft);

        $this->assertNull($out['itens'][0]['part_no']);
        $this->assertSame('Slam Ball — 6kg', $out['itens'][0]['description']);
        // Sem rótulo: sufixo posicional garante part_nos distintos (dedup createdByRef).
        $this->assertSame('DB-V1', $out['itens'][1]['part_no']);
        $this->assertSame('DB-V2', $out['itens'][2]['part_no']);
    }
}
