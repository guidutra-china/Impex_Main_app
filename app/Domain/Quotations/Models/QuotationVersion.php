<?php

namespace App\Domain\Quotations\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QuotationVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quotation_id',
        'version',
        'snapshot',
        'change_notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'version' => 'integer',
        ];
    }

    // --- Relationships ---

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
