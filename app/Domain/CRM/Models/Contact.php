<?php

namespace App\Domain\CRM\Models;

use App\Domain\CRM\Enums\ContactFunction;
use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Contact extends Model
{
    use HasFactory;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $fillable = [
        'company_id',
        'name',
        'email',
        'phone',
        'wechat',
        'whatsapp',
        'function',
        'position',
        'is_primary',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'function' => ContactFunction::class,
            'is_primary' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
