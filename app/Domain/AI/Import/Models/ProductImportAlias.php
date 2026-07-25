<?php

declare(strict_types=1);

namespace App\Domain\AI\Import\Models;

use App\Domain\Catalog\Models\Product;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alias aprendido de import: "esta descrição, nesta empresa, é este produto".
 * Empresa = fornecedor (SQ) ou cliente (Inquiry). Único por
 * (company_id, alias_normalized); a confirmação mais recente vence.
 */
class ProductImportAlias extends Model
{
    protected $fillable = [
        'company_id',
        'product_id',
        'alias',
        'alias_normalized',
        'source',
        'last_confirmed_at',
        'created_by',
    ];

    protected function casts(): array
    {
        return ['last_confirmed_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
