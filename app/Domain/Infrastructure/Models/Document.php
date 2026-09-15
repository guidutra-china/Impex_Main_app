<?php

namespace App\Domain\Infrastructure\Models;

use App\Domain\Infrastructure\Enums\DocumentSourceType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    /**
     * Tamanho da coluna `type`. Os testes rodam em SQLite, que ignora o limite
     * de varchar, então nada avisa quando um tipo novo não cabe — foi assim que
     * shipment_financial_statement_pdf (32) estourou a coluna de 30 em
     * produção. DocumentTypeFitsColumnTest confere todo template contra esta
     * constante; mudá-la exige uma migration que altere a coluna junto.
     */
    public const TYPE_MAX_LENGTH = 60;

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'type',
        'name',
        'disk',
        'path',
        'version',
        'source',
        'checksum',
        'mime_type',
        'size',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'size' => 'integer',
            'source' => DocumentSourceType::class,
        ];
    }

    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version');
    }

    public function getUrl(): ?string
    {
        return Storage::disk($this->disk)->url($this->path);
    }

    public function getFullPath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    public function exists(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Nome do arquivo entregue no download. Documento gerado já nasce com a
     * extensão no nome (CI-SH-…-v1.pdf); documento enviado à mão tem o nome
     * digitado pelo usuário (Signed Contract). Colar a extensão sem olhar
     * produzia 'PL-SH-2026-00027-v1.xlsx.xlsx'.
     */
    public function downloadFilename(?string $path = null): string
    {
        $extension = strtolower((string) pathinfo($path ?? $this->path, PATHINFO_EXTENSION));
        $name = (string) $this->name;

        if ($extension === '' || str_ends_with(strtolower($name), '.'.$extension)) {
            return $name;
        }

        return $name.'.'.$extension;
    }

    /**
     * Nome de download de uma versão antiga: mesmo nome, com o número da
     * versão trocado — 'CI-SH-…-v2.pdf' com v1 no histórico baixa como
     * 'CI-SH-…-v1.pdf', não 'CI-SH-…-v2-v1.pdf'.
     */
    public function versionDownloadFilename(DocumentVersion $version): string
    {
        $extension = strtolower((string) pathinfo($version->path, PATHINFO_EXTENSION));
        $base = (string) pathinfo($this->downloadFilename($version->path), PATHINFO_FILENAME);
        $base = preg_replace('/-v\d+$/i', '', $base);

        return "{$base}-v{$version->version}".($extension !== '' ? ".{$extension}" : '');
    }
}
