<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Targets;

use App\Models\User;

/**
 * A destination the universal document import can write to (Supplier Quotation,
 * Inquiry, ...). Each target owns its extraction schema/prompts, deterministic
 * resolution, editable-form mapping and the transactional confirm Action. The
 * shared pipeline (upload, classification, extraction call, review UI, cleanup)
 * lives in HandlesDocumentImport and is target-agnostic.
 */
interface ImportTarget
{
    /** Stable identifier used in Livewire state, blade partials and the classifier enum. */
    public function key(): string;

    /** Human label (translated) shown in the target chooser. */
    public function label(): string;

    /** One-line hint that teaches the classifier to recognize this document type. */
    public function classifierHint(): string;

    public function extractionToolName(): string;

    /** @return array<string,mixed> JSON Schema for the extraction tool input. Must produce an `itens` array. */
    public function extractionSchema(): array;

    public function extractionSystemPrompt(): string;

    /** First user text block sent alongside the document content blocks. */
    public function extractionUserPrompt(): string;

    public function editToolName(): string;

    /** May contain the `:language` placeholder — DraftEditor substitutes the user language. */
    public function editSystemPrompt(): string;

    /**
     * Deterministically resolve a draft into the preview model (DB matching,
     * money conversion). No writes, no LLM.
     *
     * @param  array<string,mixed>  $draft
     * @return array<string,mixed>
     */
    public function resolve(array $draft): array;

    /** Whether the pipeline should extract embedded images for this target. */
    public function supportsImages(): bool;

    /** Whether the user may run this import at all (the confirm Action re-checks). */
    public function authorize(User $user): bool;

    /**
     * Build the editable review form from a resolved preview.
     *
     * @param  array<string,mixed>  $preview
     * @param  array<int,?int>  $itemPhoto  item index => image pool id
     * @return array<string,mixed>
     */
    public function buildForm(array $preview, array $itemPhoto): array;

    /**
     * Convert the edited form back into the confirm Action's input shape.
     *
     * @param  array<string,mixed>  $form
     * @param  list<array{id:int,path:string}>  $imagePool
     * @return array{preview:array<string,mixed>,images:array<string,string>}
     */
    public function formToConfirmPayload(array $form, array $imagePool): array;

    /**
     * Commit the reviewed preview (transactional, permission-gated, user-triggered).
     *
     * @param  array<string,mixed>  $preview
     * @param  array<string,string>  $images  item key => absolute temp path
     * @return array{reference:string,count:int}
     */
    public function confirm(array $preview, User $user, string $filePath, array $images): array;
}
