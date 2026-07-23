<?php

declare(strict_types=1);

namespace Tests\Feature\Financial;

use App\Domain\CRM\Models\Company;
use App\Domain\Financial\Models\CreditNote;
use App\Domain\Financial\Models\DebitNote;
use App\Domain\Infrastructure\Models\ReferenceSequence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DN/CN references must come from the reference_sequences counter (row-locked,
 * like every other document type) instead of the old max(suffix)+1 table scan,
 * which could hand the same number to two concurrent creations.
 */
class DebitCreditNoteReferenceTest extends TestCase
{
    use RefreshDatabase;

    private function noteAttributes(): array
    {
        return [
            'company_id' => Company::factory()->create()->id,
            'party_type' => 'client',
            'total_amount' => 1000,
            'currency_code' => 'USD',
        ];
    }

    public function test_debit_note_references_come_from_the_sequence_table(): void
    {
        $first = DebitNote::create($this->noteAttributes());
        $second = DebitNote::create($this->noteAttributes());

        $year = now()->year;
        $this->assertSame(sprintf('DN-%d-0001', $year), $first->reference);
        $this->assertSame(sprintf('DN-%d-0002', $year), $second->reference);

        $sequence = ReferenceSequence::where('type', 'DN')->where('year', $year)->first();
        $this->assertNotNull($sequence);
        $this->assertSame(3, $sequence->next_number);
    }

    public function test_credit_note_references_come_from_the_sequence_table(): void
    {
        $first = CreditNote::create($this->noteAttributes());
        $second = CreditNote::create($this->noteAttributes());

        $year = now()->year;
        $this->assertSame(sprintf('CN-%d-0001', $year), $first->reference);
        $this->assertSame(sprintf('CN-%d-0002', $year), $second->reference);

        $sequence = ReferenceSequence::where('type', 'CN')->where('year', $year)->first();
        $this->assertNotNull($sequence);
        $this->assertSame(3, $sequence->next_number);
    }

    public function test_force_deleting_a_note_does_not_reissue_its_number(): void
    {
        $first = DebitNote::create($this->noteAttributes());
        $first->forceDelete();

        $second = DebitNote::create($this->noteAttributes());

        $this->assertSame(sprintf('DN-%d-0002', now()->year), $second->reference);
    }

    public function test_explicit_reference_is_kept_and_does_not_consume_the_sequence(): void
    {
        $note = DebitNote::create($this->noteAttributes() + ['reference' => 'DN-LEGACY-1']);

        $this->assertSame('DN-LEGACY-1', $note->reference);
        $this->assertNull(ReferenceSequence::where('type', 'DN')->first());
    }
}
