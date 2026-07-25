<?php

declare(strict_types=1);

namespace Tests\Unit\AI;

use App\Domain\AI\Import\Support\NameNormalizer;
use PHPUnit\Framework\TestCase;

class NameNormalizerTest extends TestCase
{
    public function test_strips_punctuation_and_uppercases(): void
    {
        $this->assertSame('DUMBBELL5KG', NameNormalizer::normalize('Dumbbell — 5kg'));
        $this->assertSame('DUMBBELL5KG', NameNormalizer::normalize('  dumbbell 5KG  '));
        $this->assertSame('BARRAWPARAPUXADOR', NameNormalizer::normalize('Barra "W" para Puxador'));
        $this->assertSame('', NameNormalizer::normalize(null));
        $this->assertSame('', NameNormalizer::normalize('—  ,.'));
    }
}
