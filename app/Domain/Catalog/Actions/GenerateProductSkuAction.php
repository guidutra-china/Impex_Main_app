<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Category;
use App\Domain\Catalog\Models\Product;
use Illuminate\Support\Facades\DB;

class GenerateProductSkuAction
{
    /**
     * Gera um SKU único para o produto dentro de uma transação com lock pessimista.
     * Faz até 3 tentativas em caso de colisão de unique constraint.
     */
    public function execute(int $categoryId): string
    {
        $attempts = 0;
        $maxAttempts = 3;

        while ($attempts < $maxAttempts) {
            try {
                return DB::transaction(function () use ($categoryId) {
                    $category = Category::query()
                        ->lockForUpdate()
                        ->find($categoryId);

                    if (! $category || ! $category->sku_prefix) {
                        $count = Product::withTrashed()->lockForUpdate()->count() + 1;

                        return 'PRD-' . str_pad($count, 5, '0', STR_PAD_LEFT);
                    }

                    $prefix = $category->sku_prefix;

                    $lastSku = Product::withTrashed()
                        ->where('sku', 'like', $prefix . '-%')
                        ->orderByRaw("CAST(SUBSTRING_INDEX(sku, '-', -1) AS UNSIGNED) DESC")
                        ->lockForUpdate()
                        ->value('sku');

                    $nextNumber = $lastSku
                        ? (int) last(explode('-', $lastSku)) + 1
                        : 1;

                    return $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                $attempts++;

                if ($attempts >= $maxAttempts) {
                    throw $e;
                }
            }
        }

        // Nunca atingido, mas satisfaz o type-checker
        throw new \RuntimeException('Failed to generate unique SKU after ' . $maxAttempts . ' attempts.');
    }

    /**
     * Gera uma prévia do próximo SKU sem lock (apenas para exibição no formulário).
     * O valor final é sempre recalculado no evento creating do Model.
     */
    public function preview(int $categoryId): string
    {
        $category = Category::find($categoryId);

        if (! $category || ! $category->sku_prefix) {
            $count = Product::withTrashed()->count() + 1;

            return 'PRD-' . str_pad($count, 5, '0', STR_PAD_LEFT);
        }

        $prefix = $category->sku_prefix;

        $lastSku = Product::withTrashed()
            ->where('sku', 'like', $prefix . '-%')
            ->orderByRaw("CAST(SUBSTRING_INDEX(sku, '-', -1) AS UNSIGNED) DESC")
            ->value('sku');

        $nextNumber = $lastSku
            ? (int) last(explode('-', $lastSku)) + 1
            : 1;

        return $prefix . '-' . str_pad($nextNumber, 5, '0', STR_PAD_LEFT);
    }
}
