<?php

namespace App\Domain\Catalog\Models;

use App\Domain\Catalog\Actions\GenerateProductSkuAction;
use App\Domain\Catalog\Enums\ProductStatus;
use App\Domain\CRM\Models\Company;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use HasFactory, SoftDeletes;

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected static function booted(): void
    {
        static::creating(function (Product $product) {
            if (empty($product->sku) && $product->category_id) {
                $product->sku = app(GenerateProductSkuAction::class)->execute($product->category_id);
            }

            if (empty($product->sku) && ! $product->category_id) {
                $product->sku = app(GenerateProductSkuAction::class)->generateDraftSku();
            }

            if (empty($product->name) && $product->category_id) {
                $category = Category::find($product->category_id);
                $product->name = $category?->name ?? 'New Product';
            }
        });

        // Delete old avatar file when avatar is changed
        static::updating(function (Product $product) {
            if ($product->isDirty('avatar')) {
                $oldAvatar = $product->getOriginal('avatar');
                if ($oldAvatar) {
                    self::deleteAvatarIfOrphan($oldAvatar, $product->id);
                }
            }
        });

        // Delete avatar file when product is force-deleted
        static::forceDeleting(function (Product $product) {
            if ($product->avatar) {
                self::deleteAvatarIfOrphan($product->avatar, $product->id);
            }
        });
    }

    /**
     * Delete avatar file from disk only if no other product references it.
     * Shared images (from deduplication) are kept until the last reference is removed.
     */
    private static function deleteAvatarIfOrphan(string $avatarPath, ?int $excludeProductId = null): void
    {
        $query = static::withTrashed()->where('avatar', $avatarPath);
        if ($excludeProductId) {
            $query->where('id', '!=', $excludeProductId);
        }

        if (! $query->exists()) {
            Storage::disk('public')->delete($avatarPath);
        }
    }

    protected $fillable = [
        'name',
        'commercial_name',
        'product_family',
        'sku',
        'reference_code',
        'avatar',
        'description',
        'status',
        'category_id',
        'parent_id',
        'hs_code',
        'origin_country',
        'brand',
        'model_number',
        'moq',
        'moq_unit',
        'lead_time_days',
        'certifications',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => ProductStatus::class,
            'moq' => 'integer',
            'lead_time_days' => 'integer',
        ];
    }

    // --- Relationships ---

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'parent_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Product::class, 'parent_id');
    }

    public function specification(): HasOne
    {
        return $this->hasOne(ProductSpecification::class);
    }

    public function packaging(): HasOne
    {
        return $this->hasOne(ProductPackaging::class);
    }

    public function costing(): HasOne
    {
        return $this->hasOne(ProductCosting::class);
    }

    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function attributeValues(): HasMany
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_product')
            ->using(CompanyProduct::class)
            ->withPivot([
                'role',
                'external_code',
                'external_name',
                'external_description',
                'unit_price',
                'custom_price',
                'currency_code',
                'incoterm',
                'lead_time_days',
                'moq',
                'notes',
                'is_preferred',
            ])
            ->withTimestamps();
    }

    public function suppliers(): BelongsToMany
    {
        return $this->companies()->wherePivot('role', 'supplier');
    }

    public function clients(): BelongsToMany
    {
        return $this->companies()->wherePivot('role', 'client');
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('status', ProductStatus::ACTIVE);
    }

    public function scopeBases($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeVariantsOf($query, int $parentId)
    {
        return $query->where('parent_id', $parentId);
    }

    // --- Accessors ---

    public function getIsVariantAttribute(): bool
    {
        return $this->parent_id !== null;
    }
}
