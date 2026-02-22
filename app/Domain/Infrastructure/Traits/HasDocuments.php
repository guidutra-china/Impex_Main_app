<?php

namespace App\Domain\Infrastructure\Traits;

use App\Domain\Infrastructure\Models\Document;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait HasDocuments
{
    public function documents(): MorphMany
    {
        return $this->morphMany(Document::class, 'documentable');
    }

    public function getLatestDocument(string $type): ?Document
    {
        return $this->documents()
            ->where('type', $type)
            ->orderByDesc('version')
            ->first();
    }

    public function getDocumentsByType(string $type)
    {
        return $this->documents()
            ->where('type', $type)
            ->orderByDesc('version')
            ->get();
    }
}
