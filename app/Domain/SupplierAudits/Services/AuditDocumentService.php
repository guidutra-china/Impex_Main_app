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
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'file_type' => $type,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'description' => $description,
            'uploaded_by' => auth()->id(),
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
            'file_name' => basename($storedPath),
            'file_path' => $storedPath,
            'file_type' => $type,
            'file_size' => $size,
            'mime_type' => $mimeType,
            'uploaded_by' => auth()->id(),
        ]);
    }

    public function delete(AuditDocument $document): bool
    {
        Storage::disk('local')->delete($document->file_path);

        return $document->delete();
    }

    public function getUrl(AuditDocument $document): string
    {
        return Storage::disk('local')->url($document->file_path);
    }
}
