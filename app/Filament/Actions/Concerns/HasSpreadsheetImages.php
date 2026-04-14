<?php

namespace App\Filament\Actions\Concerns;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\MemoryDrawing;

/**
 * Shared logic for resolving Livewire file uploads and extracting images from spreadsheets.
 * Includes security hardening: extension whitelist, magic byte validation, EXIF stripping.
 */
trait HasSpreadsheetImages
{
    private static array $allowedImageExtensions = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    protected static function resolveUploadPath(mixed $filePath): ?string
    {
        if (empty($filePath)) {
            return null;
        }

        if ($filePath instanceof TemporaryUploadedFile) {
            $realPath = $filePath->getRealPath();

            return ($realPath && file_exists($realPath)) ? $realPath : null;
        }

        if (is_array($filePath)) {
            foreach ($filePath as $value) {
                if ($value instanceof TemporaryUploadedFile) {
                    $realPath = $value->getRealPath();
                    if ($realPath && file_exists($realPath)) {
                        return $realPath;
                    }
                }

                if (is_string($value) && str_starts_with($value, 'livewire-file:')) {
                    $filename = substr($value, strlen('livewire-file:'));
                    try {
                        $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                        $realPath = $tmpFile->getRealPath();
                        if ($realPath && file_exists($realPath)) {
                            return $realPath;
                        }
                    } catch (\Throwable $e) {
                    }
                }
            }

            $filePath = reset($filePath);
            if (! is_string($filePath)) {
                return null;
            }
        }

        if (! is_string($filePath)) {
            return null;
        }

        if (str_starts_with($filePath, 'livewire-file:')) {
            $filename = substr($filePath, strlen('livewire-file:'));
            try {
                $tmpFile = TemporaryUploadedFile::createFromLivewire($filename);
                $realPath = $tmpFile->getRealPath();
                if ($realPath && file_exists($realPath)) {
                    return $realPath;
                }
            } catch (\Throwable $e) {
            }
        }

        if (file_exists($filePath)) {
            return $filePath;
        }

        $path = storage_path('app/private/' . $filePath);
        if (file_exists($path)) {
            return $path;
        }

        $path = storage_path('app/' . $filePath);

        return file_exists($path) ? $path : null;
    }

    /**
     * Extract images from a worksheet, sanitize them, and save to storage.
     * Returns [rowNumber => storedPath].
     *
     * @param  int  $minRow  Skip images above this row (e.g. header rows)
     */
    protected static function extractImagesByRow(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $worksheet,
        int $minRow = 1,
    ): array {
        $imagesByRow = [];
        $hashMap = [];

        foreach ($worksheet->getDrawingCollection() as $drawing) {
            $coordinate = $drawing->getCoordinates();
            if (empty($coordinate)) {
                continue;
            }

            $row = (int) preg_replace('/[A-Z]+/i', '', $coordinate);
            if ($row < $minRow || isset($imagesByRow[$row])) {
                continue;
            }

            $imageData = null;
            $extension = 'png';

            try {
                if ($drawing instanceof MemoryDrawing) {
                    $imageResource = $drawing->getImageResource();
                    if ($imageResource) {
                        ob_start();
                        $renderFunc = match ($drawing->getRenderingFunction()) {
                            MemoryDrawing::RENDERING_JPEG => 'imagejpeg',
                            MemoryDrawing::RENDERING_GIF => 'imagegif',
                            default => 'imagepng',
                        };
                        $renderFunc($imageResource);
                        $imageData = ob_get_clean();
                        $extension = match ($drawing->getRenderingFunction()) {
                            MemoryDrawing::RENDERING_JPEG => 'jpg',
                            MemoryDrawing::RENDERING_GIF => 'gif',
                            default => 'png',
                        };
                    }
                } elseif ($drawing instanceof Drawing && $drawing->getPath()) {
                    $sourcePath = $drawing->getPath();
                    $imageData = str_starts_with($sourcePath, 'zip://')
                        ? @file_get_contents($sourcePath)
                        : (file_exists($sourcePath) ? file_get_contents($sourcePath) : null);
                    if ($imageData) {
                        $extension = strtolower($drawing->getExtension() ?: pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'png');
                    }
                }
            } catch (\Throwable $e) {
                Log::warning("Image extraction failed at {$coordinate}: {$e->getMessage()}");

                continue;
            }

            if (! $imageData || strlen($imageData) <= 100) {
                continue;
            }

            // Validate extension whitelist
            if (! in_array($extension, self::$allowedImageExtensions)) {
                continue;
            }

            // Validate magic bytes
            $magicBytes = substr($imageData, 0, 8);
            $isValidImage = str_starts_with($magicBytes, "\xFF\xD8\xFF") // JPEG
                || str_starts_with($magicBytes, "\x89PNG") // PNG
                || str_starts_with($magicBytes, "GIF87a") || str_starts_with($magicBytes, "GIF89a") // GIF
                || str_starts_with($magicBytes, "RIFF"); // WebP

            if (! $isValidImage) {
                continue;
            }

            // Reprocess through GD to strip EXIF and sanitize
            $gdImage = @imagecreatefromstring($imageData);
            if ($gdImage === false) {
                continue;
            }

            ob_start();
            match ($extension) {
                'jpg', 'jpeg' => imagejpeg($gdImage, null, 90),
                'gif' => imagegif($gdImage),
                'webp' => imagewebp($gdImage, null, 90),
                default => imagepng($gdImage, null, 6),
            };
            $cleanImageData = ob_get_clean();
            imagedestroy($gdImage);

            if (empty($cleanImageData)) {
                continue;
            }

            $hash = md5($cleanImageData);

            if (isset($hashMap[$hash])) {
                $imagesByRow[$row] = $hashMap[$hash];
            } else {
                $filename = 'products/' . uniqid('import_') . '.' . $extension;
                Storage::disk('public')->put($filename, $cleanImageData);
                $hashMap[$hash] = $filename;
                $imagesByRow[$row] = $filename;
            }
        }

        return $imagesByRow;
    }
}
