<?php

namespace App\Domain\Infrastructure\Services;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Domain\Infrastructure\Models\Document;
use App\Domain\Infrastructure\Models\DocumentVersion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentService
{
    /**
     * Store a new document (uploaded file) for a model.
     * If a document of the same type already exists, it will be versioned.
     */
    public function storeUpload(
        Model $documentable,
        UploadedFile $file,
        string $type,
        ?string $name = null,
        string $disk = 'local',
    ): Document {
        $name = $name ?? $file->getClientOriginalName();
        $directory = $this->getDirectory($documentable, $type);
        $path = $file->store($directory, $disk);
        $fullPath = Storage::disk($disk)->path($path);

        return $this->createOrVersion($documentable, $type, $name, $disk, $path, $fullPath, DocumentSourceType::UPLOADED);
    }

    /**
     * Store a generated document (e.g. PDF created by the system).
     * If a document of the same type already exists, it will be versioned.
     */
    public function storeGenerated(
        Model $documentable,
        string $content,
        string $type,
        string $name,
        string $extension = 'pdf',
        string $disk = 'local',
    ): Document {
        $directory = $this->getDirectory($documentable, $type);
        $filename = $this->generateFilename($name, $extension);
        $path = $directory . '/' . $filename;

        Storage::disk($disk)->put($path, $content);
        $fullPath = Storage::disk($disk)->path($path);

        return $this->createOrVersion($documentable, $type, $name, $disk, $path, $fullPath, DocumentSourceType::GENERATED);
    }

    private function createOrVersion(
        Model $documentable,
        string $type,
        string $name,
        string $disk,
        string $path,
        string $fullPath,
        DocumentSourceType $source,
    ): Document {
        return DB::transaction(function () use ($documentable, $type, $name, $disk, $path, $fullPath, $source) {
            $existing = Document::query()
                ->where('documentable_type', $documentable->getMorphClass())
                ->where('documentable_id', $documentable->getKey())
                ->where('type', $type)
                ->orderByDesc('version')
                ->first();

            $checksum = hash_file('sha256', $fullPath);
            $size = filesize($fullPath);
            $mimeType = mime_content_type($fullPath) ?: null;

            if ($existing) {
                DocumentVersion::create([
                    'document_id' => $existing->id,
                    'path' => $existing->path,
                    'version' => $existing->version,
                    'checksum' => $existing->checksum,
                    'size' => $existing->size,
                    'created_by' => $existing->created_by,
                    'created_at' => $existing->updated_at ?? $existing->created_at,
                ]);

                $existing->update([
                    'name' => $name,
                    'disk' => $disk,
                    'path' => $path,
                    'version' => $existing->version + 1,
                    'source' => $source,
                    'checksum' => $checksum,
                    'mime_type' => $mimeType,
                    'size' => $size,
                    'created_by' => auth()->id(),
                ]);

                return $existing->fresh();
            }

            return Document::create([
                'documentable_type' => $documentable->getMorphClass(),
                'documentable_id' => $documentable->getKey(),
                'type' => $type,
                'name' => $name,
                'disk' => $disk,
                'path' => $path,
                'version' => 1,
                'source' => $source,
                'checksum' => $checksum,
                'mime_type' => $mimeType,
                'size' => $size,
                'created_by' => auth()->id(),
            ]);
        });
    }

    private function getDirectory(Model $documentable, string $type): string
    {
        $modelType = strtolower(class_basename($documentable));

        return "documents/{$modelType}/{$documentable->getKey()}/{$type}";
    }

    private function generateFilename(string $name, string $extension): string
    {
        $slug = str($name)->slug();
        $timestamp = now()->format('Ymd_His');

        return "{$slug}_{$timestamp}.{$extension}";
    }
}
