<?php

declare(strict_types=1);

namespace App\Domain\AI\Import;

use App\Domain\AI\Import\Models\ProductImportAlias;
use App\Domain\AI\Import\Support\NameNormalizer;

/**
 * Grava/atualiza um alias aprendido de import. Última confirmação vence:
 * se a mesma descrição normalizada já existir para a empresa apontando para
 * outro produto, o vínculo é trocado (dentro de uma empresa, uma descrição
 * aponta para um único produto). Descrições curtas demais são ignoradas.
 */
class UpsertProductImportAliasAction
{
    public function execute(int $companyId, int $productId, ?string $alias, string $source, ?int $userId = null): ?ProductImportAlias
    {
        $alias = trim((string) $alias);
        $normalized = mb_substr(NameNormalizer::normalize($alias), 0, 255);

        if (mb_strlen($normalized) < 3) {
            return null;
        }

        $record = ProductImportAlias::query()
            ->where('company_id', $companyId)
            ->where('alias_normalized', $normalized)
            ->first();

        if ($record) {
            $record->update([
                'product_id' => $productId,
                'alias' => mb_substr($alias, 0, 500),
                'source' => $source,
                'last_confirmed_at' => now(),
            ]);

            return $record;
        }

        return ProductImportAlias::create([
            'company_id' => $companyId,
            'product_id' => $productId,
            'alias' => mb_substr($alias, 0, 500),
            'alias_normalized' => $normalized,
            'source' => $source,
            'last_confirmed_at' => now(),
            'created_by' => $userId,
        ]);
    }
}
