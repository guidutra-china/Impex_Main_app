<?php

namespace App\Domain\SupplierAudits\Services;

use App\Domain\SupplierAudits\Enums\AuditDocumentType;
use App\Domain\SupplierAudits\Models\AuditDocument;
use App\Domain\SupplierAudits\Models\SupplierAudit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class AuditDocumentService
{
    public function store(
        SupplierAudit $audit,
        UploadedFile $file,
        AuditDocumentType $type = AuditDocumentType::PHOTO,
        ?string $description = null,
    ): AuditDocument {
        $directory = "audit-documents/{$audit->id}";
        $path = $file->store($directory, 'local');

        return AuditDocument::create([
            'supplier_audit_id' => $audit->id,
            'type' => $type,
            'title' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'notes' => $description,
        ]);
    }

    public function storeFromPath(
        SupplierAudit $audit,
        string $storedPath,
        AuditDocumentType $type = AuditDocumentType::PHOTO,
    ): AuditDocument {
        $disk = Storage::disk('local');
        $size = $disk->exists($storedPath) ? $disk->size($storedPath) : 0;
        $mimeType = $disk->exists($storedPath) ? $disk->mimeType($storedPath) : null;

        return AuditDocument::create([
            'supplier_audit_id' => $audit->id,
            'type' => $type,
            'title' => basename($storedPath),
            'disk' => 'local',
            'path' => $storedPath,
            'original_name' => basename($storedPath),
            'size' => $size,
            'mime_type' => $mimeType,
        ]);
    }

    public function delete(AuditDocument $document): bool
    {
        Storage::disk($document->disk)->delete($document->path);

        return $document->delete();
    }

    public function getUrl(AuditDocument $document): string
    {
        return Storage::disk($document->disk)->url($document->path);
    }
}
