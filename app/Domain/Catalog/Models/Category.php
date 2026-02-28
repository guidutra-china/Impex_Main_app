<?php

namespace App\Domain\Catalog\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Domain\CRM\Models\Company;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'sku_prefix',
        'parent_id',
        'description',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    // --- Relationships ---

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'category_company')
            ->withPivot('notes')
            ->withTimestamps();
    }

    public function categoryAttributes(): HasMany
    {
        return $this->hasMany(CategoryAttribute::class);
    }

    // --- Scopes ---

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    // --- Accessors ---

    public function getFullPathAttribute(): string
    {
        $path = collect([$this->name]);
        $parent = $this->parent;

        while ($parent) {
            $path->prepend($parent->name);
            $parent = $parent->parent;
        }

        return $path->implode(' > ');
    }

    public function getReversePathAttribute(): string
    {
        $parents = collect();
        $parent = $this->parent;

        while ($parent) {
            $parents->push($parent->name);
            $parent = $parent->parent;
        }

        if ($parents->isEmpty()) {
            return '<strong>' . e($this->name) . '</strong>';
        }

        return '<strong>' . e($this->name) . '</strong> <span style="color:#6b7280;font-size:0.85em">‹ ' . e($parents->implode(' ‹ ')) . '</span>';
    }

    /**
     * Get all attributes including inherited from parent categories.
     * Own attributes come last (higher priority for display).
     */
    public function getAllAttributes(): \Illuminate\Support\Collection
    {
        $attributes = collect();
        $ancestors = collect();
        $current = $this->parent;

        while ($current) {
            $ancestors->prepend($current);
            $current = $current->parent;
        }

        foreach ($ancestors as $ancestor) {
            foreach ($ancestor->categoryAttributes()->orderBy('sort_order')->get() as $attr) {
                $attributes->put($attr->id, $attr);
            }
        }

        foreach ($this->categoryAttributes()->orderBy('sort_order')->get() as $attr) {
            $attributes->put($attr->id, $attr);
        }

        return $attributes->values();
    }
}
