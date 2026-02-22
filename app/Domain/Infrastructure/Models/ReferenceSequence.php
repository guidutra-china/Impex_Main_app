<?php

namespace App\Domain\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

class ReferenceSequence extends Model
{
    protected $fillable = [
        'type',
        'year',
        'next_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'next_number' => 'integer',
        ];
    }
}
