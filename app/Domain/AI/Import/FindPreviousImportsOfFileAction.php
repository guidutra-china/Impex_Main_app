<?php

namespace App\Domain\AI\Import;

use App\Domain\Infrastructure\Models\Document;
use Illuminate\Support\Collection;

/**
 * Fingerprint de importação: localiza importações anteriores do mesmo arquivo
 * pelo sha256 já gravado em documents.checksum pelos ImportTargets. Usado para
 * avisar o usuário antes de confirmar um reimport que criaria uma SQ/Inquiry
 * duplicada. Registros soft-deletados não contam — reimportar algo excluído é
 * legítimo.
 */
class FindPreviousImportsOfFileAction
{
    /** Document types written by the import targets for the uploaded source file. */
    public const SOURCE_TYPES = ['inquiry_source', 'supplier_quotation_source'];

    /**
     * @return Collection<int, Document> one document per previously imported record
     */
    public function execute(string $filePath): Collection
    {
        $checksum = @hash_file('sha256', $filePath);

        if ($checksum === false || $checksum === null) {
            return collect();
        }

        return Document::query()
            ->with('documentable')
            ->whereIn('type', self::SOURCE_TYPES)
            ->where('checksum', $checksum)
            ->latest('id')
            ->get()
            ->filter(fn (Document $document) => $document->documentable !== null)
            ->unique(fn (Document $document) => $document->documentable_type.'#'.$document->documentable_id)
            ->values();
    }
}
