<?php

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Models\Product;

class SyncProductPrimaryImageAction
{
    /**
     * Garante que exista exatamente uma imagem marcada como principal na galeria
     * e espelha o caminho dela na coluna `avatar` (foto destaque usada em PDFs,
     * tabelas e portais). Sem imagens, limpa o avatar.
     */
    public function execute(Product $product, ?int $preferredImageId = null): void
    {
        $images = $product->images()->get();

        if ($images->isEmpty()) {
            if ($product->avatar) {
                $product->update(['avatar' => null]);
            }

            return;
        }

        $primary = ($preferredImageId ? $images->firstWhere('id', $preferredImageId) : null)
            ?? $images->firstWhere('is_primary', true)
            ?? $images->first();

        // Normaliza a flag is_primary para uma única linha.
        $product->images()->whereKeyNot($primary->getKey())->update(['is_primary' => false]);

        if (! $primary->is_primary) {
            $primary->update(['is_primary' => true]);
        }

        // Espelha no avatar. Setar avatar dispara a limpeza de órfão (já ciente da galeria).
        if ($product->avatar !== $primary->path) {
            $product->update(['avatar' => $primary->path]);
        }
    }
}
