<?php

namespace App\Domain\Catalog\Actions\Import;

use App\Domain\Catalog\Models\Product;
use App\Domain\Infrastructure\Services\ClaudeAnalysisService;
use Illuminate\Support\Facades\Log;

class PreImportAnalysis
{
    private ClaudeAnalysisService $claude;

    public function __construct()
    {
        $this->claude = new ClaudeAnalysisService();
    }

    /**
     * Check if AI analysis is available (always true — local matching works without API key).
     */
    public function isAvailable(): bool
    {
        return true;
    }

    /**
     * Run analysis on mapped import items (from FlexibleProductImportAction).
     * Uses LOCAL fuzzy matching (instant, always works) + optional Claude AI enhancement.
     */
    public function analyzeFlexibleImport(array $mappedItems, array $categoryIds, string $categoryName): array
    {
        $result = [
            'ai_matches' => [],
            'ai_warnings' => [],
            'ai_available' => true,
            '_debug' => [],
        ];

        if (empty($mappedItems)) {
            return $result;
        }

        Log::info('PreImportAnalysis: starting analysis with ' . count($mappedItems) . ' mapped items');

        // Prepare rows
        $rows = collect($mappedItems)->map(fn ($item) => [
            '_row' => $item['_source_row'] ?? 0,
            'reference_code' => $item['reference_code'] ?? '',
            'name' => $item['product_name'] ?? ($item['name'] ?? ''),
            'model_number' => $item['model_number'] ?? '',
            'external_code' => $item['external_code'] ?? '',
            'unit_price' => $item['unit_price'] ?? '',
        ])->toArray();

        // Get existing products from DB (targeted by import codes/names)
        $existingProducts = $this->getExistingProducts($categoryIds, $rows);

        Log::info('PreImportAnalysis: ' . count($rows) . ' import rows, ' . count($existingProducts) . ' existing products');

        // Store debug info for display
        $result['_debug'] = [
            'import_rows' => count($rows),
            'existing_products' => count($existingProducts),
            'ai_configured' => $this->claude->isConfigured(),
        ];

        // ===== LOCAL FUZZY MATCHING (instant, reliable) =====
        $localMatches = $this->localFuzzyMatch($rows, $existingProducts);
        Log::info('PreImportAnalysis: local fuzzy matching found ' . count($localMatches) . ' matches');

        // ===== AI ENHANCEMENT (optional, adds semantic analysis) =====
        $aiMatches = [];
        if ($this->claude->isConfigured()) {
            try {
                $aiResult = $this->claude->analyzeImport($rows, $existingProducts, $categoryName);
                $aiMatches = $aiResult['matches'] ?? [];
                $result['ai_warnings'] = $aiResult['warnings'] ?? [];
                Log::info('PreImportAnalysis: AI found ' . count($aiMatches) . ' matches, ' . count($result['ai_warnings']) . ' warnings');
            } catch (\Throwable $e) {
                Log::warning('PreImportAnalysis: AI analysis failed: ' . $e->getMessage());
            }
        }

        // Merge local + AI matches, deduplicate by row+type
        $result['ai_matches'] = $this->mergeMatches($localMatches, $aiMatches);

        Log::info('PreImportAnalysis: final merged result — ' . count($result['ai_matches']) . ' matches');

        return $result;
    }

    /**
     * LOCAL fuzzy matching — compares normalized codes/names.
     * Uses hash maps for O(n) exact matches + targeted Levenshtein for near-matches.
     */
    private function localFuzzyMatch(array $importRows, array $existingProducts): array
    {
        $matches = [];

        // Build normalized lookup for imported rows
        $importNormalized = [];
        // Hash maps: normalized code → list of indices that share it
        $codeToIndices = [];
        // Hash map: normalized name → list of indices
        $nameToIndices = [];

        foreach ($importRows as $idx => $row) {
            $codes = array_filter([
                $this->normalizeCode($row['reference_code'] ?? ''),
                $this->normalizeCode($row['model_number'] ?? ''),
                $this->normalizeCode($row['external_code'] ?? ''),
            ]);
            $nameNorm = $this->normalizeName($row['name'] ?? '');

            $importNormalized[$idx] = [
                'row' => $row['_row'] ?? 0,
                'codes' => $codes,
                'name' => $row['name'] ?? '',
                'name_normalized' => $nameNorm,
                'ref_original' => $row['reference_code'] ?? '',
                'model_original' => $row['model_number'] ?? '',
            ];

            foreach ($codes as $code) {
                $codeToIndices[$code][] = $idx;
            }
            if ($nameNorm !== '') {
                $nameToIndices[$nameNorm][] = $idx;
            }
        }

        // --- Intra-batch duplicates (hash-based, O(n) for exact matches) ---
        $seen = [];

        // Exact code matches via hash map
        foreach ($codeToIndices as $code => $indices) {
            if (count($indices) < 2) {
                continue;
            }
            for ($i = 0; $i < count($indices); $i++) {
                for ($j = $i + 1; $j < count($indices); $j++) {
                    $a = $importNormalized[$indices[$i]];
                    $b = $importNormalized[$indices[$j]];
                    $key = min($a['row'], $b['row']) . ':' . max($a['row'], $b['row']);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $matches[] = [
                            'type' => 'intra_batch',
                            'row' => $a['row'],
                            'imported_code' => $a['ref_original'] ?: $a['name'],
                            'duplicate_row' => $b['row'],
                            'matched_id' => null,
                            'matched_code' => $b['ref_original'] ?: $b['model_original'],
                            'matched_name' => $b['name'],
                            'confidence' => 'high',
                            'reason' => 'Códigos idênticos após normalização (removendo traços/pontos/espaços)',
                        ];
                    }
                }
            }
        }

        // Exact name matches via hash map
        foreach ($nameToIndices as $name => $indices) {
            if (count($indices) < 2) {
                continue;
            }
            for ($i = 0; $i < count($indices); $i++) {
                for ($j = $i + 1; $j < count($indices); $j++) {
                    $a = $importNormalized[$indices[$i]];
                    $b = $importNormalized[$indices[$j]];
                    $key = min($a['row'], $b['row']) . ':' . max($a['row'], $b['row']);
                    if (! isset($seen[$key])) {
                        $seen[$key] = true;
                        $matches[] = [
                            'type' => 'intra_batch',
                            'row' => $a['row'],
                            'imported_code' => $a['ref_original'] ?: $a['name'],
                            'duplicate_row' => $b['row'],
                            'matched_id' => null,
                            'matched_code' => $b['ref_original'] ?: $b['model_original'],
                            'matched_name' => $b['name'],
                            'confidence' => 'high',
                            'reason' => 'Nomes idênticos de produtos',
                        ];
                    }
                }
            }
        }

        // Near-match codes via Levenshtein (only codes >= 4 chars, grouped by length bucket)
        $codeBuckets = []; // length → [['code' => ..., 'idx' => ...], ...]
        foreach ($importNormalized as $idx => $item) {
            foreach ($item['codes'] as $code) {
                if (strlen($code) >= 4) {
                    $len = strlen($code);
                    // Group by length ±1 to reduce comparisons
                    $codeBuckets[$len][] = ['code' => $code, 'idx' => $idx];
                }
            }
        }

        foreach ($codeBuckets as $len => $entries) {
            // Also compare with adjacent length buckets
            $candidates = $entries;
            if (isset($codeBuckets[$len - 1])) {
                $candidates = array_merge($candidates, $codeBuckets[$len - 1]);
            }

            for ($i = 0; $i < count($entries); $i++) {
                for ($j = $i + 1; $j < count($candidates); $j++) {
                    if ($entries[$i]['idx'] === $candidates[$j]['idx']) {
                        continue;
                    }
                    $dist = levenshtein($entries[$i]['code'], $candidates[$j]['code']);
                    if ($dist === 1) {
                        $a = $importNormalized[$entries[$i]['idx']];
                        $b = $importNormalized[$candidates[$j]['idx']];
                        $key = min($a['row'], $b['row']) . ':' . max($a['row'], $b['row']);
                        if (! isset($seen[$key])) {
                            $seen[$key] = true;
                            $matches[] = [
                                'type' => 'intra_batch',
                                'row' => $a['row'],
                                'imported_code' => $a['ref_original'] ?: $a['name'],
                                'duplicate_row' => $b['row'],
                                'matched_id' => null,
                                'matched_code' => $b['ref_original'] ?: $b['model_original'],
                                'matched_name' => $b['name'],
                                'confidence' => 'high',
                                'reason' => 'Códigos muito similares (diferença de 1 caractere)',
                            ];
                        }
                    }
                }
            }
        }

        // --- VS database ---
        // Build hash maps for existing products too
        $existingByCode = []; // normalized code → product data
        $existingByName = []; // normalized name → product data
        foreach ($existingProducts as $p) {
            $codes = array_filter([
                $this->normalizeCode($p['reference_code'] ?? ''),
                $this->normalizeCode($p['sku'] ?? ''),
                $this->normalizeCode($p['model_number'] ?? ''),
            ]);
            $nameNorm = $this->normalizeName($p['name'] ?? '');
            $pData = [
                'id' => $p['id'],
                'codes' => $codes,
                'name' => $p['name'] ?? '',
                'name_normalized' => $nameNorm,
                'ref_original' => $p['reference_code'] ?? $p['sku'] ?? '',
            ];

            foreach ($codes as $code) {
                $existingByCode[$code][] = $pData;
            }
            if ($nameNorm !== '') {
                $existingByName[$nameNorm][] = $pData;
            }
        }

        $matchedRows = [];

        // Exact code matches against DB (O(n) via hash lookup)
        foreach ($importNormalized as $imp) {
            if (isset($matchedRows[$imp['row']])) {
                continue;
            }
            foreach ($imp['codes'] as $impCode) {
                if ($impCode === '' || isset($matchedRows[$imp['row']])) {
                    continue;
                }
                if (isset($existingByCode[$impCode])) {
                    $ex = $existingByCode[$impCode][0];
                    $matches[] = [
                        'type' => 'vs_database',
                        'row' => $imp['row'],
                        'imported_code' => $imp['ref_original'] ?: $imp['name'],
                        'duplicate_row' => null,
                        'matched_id' => $ex['id'],
                        'matched_code' => $ex['ref_original'],
                        'matched_name' => $ex['name'],
                        'confidence' => 'high',
                        'reason' => 'Código corresponde a produto existente no banco (após normalização)',
                    ];
                    $matchedRows[$imp['row']] = true;
                }
            }
        }

        // Exact name matches against DB
        foreach ($importNormalized as $imp) {
            if (isset($matchedRows[$imp['row']]) || $imp['name_normalized'] === '') {
                continue;
            }
            if (isset($existingByName[$imp['name_normalized']])) {
                $ex = $existingByName[$imp['name_normalized']][0];
                $matches[] = [
                    'type' => 'vs_database',
                    'row' => $imp['row'],
                    'imported_code' => $imp['ref_original'] ?: $imp['name'],
                    'duplicate_row' => null,
                    'matched_id' => $ex['id'],
                    'matched_code' => $ex['ref_original'],
                    'matched_name' => $ex['name'],
                    'confidence' => 'high',
                    'reason' => 'Nome idêntico a produto existente no banco',
                ];
                $matchedRows[$imp['row']] = true;
            }
        }

        // Near-match codes against DB via Levenshtein (targeted, not O(n²))
        $existingCodeList = [];
        foreach ($existingProducts as $p) {
            foreach (array_filter([
                $this->normalizeCode($p['reference_code'] ?? ''),
                $this->normalizeCode($p['sku'] ?? ''),
                $this->normalizeCode($p['model_number'] ?? ''),
            ]) as $code) {
                if (strlen($code) >= 4) {
                    $existingCodeList[] = ['code' => $code, 'product' => $p];
                }
            }
        }

        foreach ($importNormalized as $imp) {
            if (isset($matchedRows[$imp['row']])) {
                continue;
            }
            foreach ($imp['codes'] as $impCode) {
                if (strlen($impCode) < 4 || isset($matchedRows[$imp['row']])) {
                    continue;
                }
                foreach ($existingCodeList as $exEntry) {
                    if (abs(strlen($impCode) - strlen($exEntry['code'])) > 1) {
                        continue; // Skip if lengths differ by more than 1
                    }
                    $dist = levenshtein($impCode, $exEntry['code']);
                    if ($dist === 1) {
                        $p = $exEntry['product'];
                        $matches[] = [
                            'type' => 'vs_database',
                            'row' => $imp['row'],
                            'imported_code' => $imp['ref_original'] ?: $imp['name'],
                            'duplicate_row' => null,
                            'matched_id' => $p['id'],
                            'matched_code' => $p['reference_code'] ?? $p['sku'] ?? '',
                            'matched_name' => $p['name'] ?? '',
                            'confidence' => 'high',
                            'reason' => 'Código muito similar a produto existente (diferença de 1 caractere)',
                        ];
                        $matchedRows[$imp['row']] = true;
                        break 2;
                    }
                }
            }
        }

        return $matches;
    }

    /**
     * Normalize a product code for comparison.
     * Strips dashes, dots, spaces, underscores and lowercases.
     * "ABC-001" → "abc001", "ABC.001" → "abc001", "ABC 001" → "abc001"
     */
    private function normalizeCode(string $code): string
    {
        $code = trim($code);
        if ($code === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/[\s\-\._\/\\\\]+/', '', $code));
    }

    /**
     * Normalize a product name for comparison.
     */
    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return mb_strtolower(preg_replace('/\s+/', ' ', $name));
    }

    /**
     * Compare two imported items for intra-batch duplicates.
     */
    private function compareItems(array $a, array $b): ?string
    {
        // Compare normalized codes
        foreach ($a['codes'] as $codeA) {
            if ($codeA === '') {
                continue;
            }
            foreach ($b['codes'] as $codeB) {
                if ($codeB === '' ) {
                    continue;
                }
                if ($codeA === $codeB) {
                    return "Códigos idênticos após normalização (removendo traços/pontos/espaços)";
                }
                // Check Levenshtein distance for typos (only for codes >= 4 chars)
                if (strlen($codeA) >= 4 && strlen($codeB) >= 4) {
                    $dist = levenshtein($codeA, $codeB);
                    $maxLen = max(strlen($codeA), strlen($codeB));
                    if ($dist <= 1 && $maxLen >= 4) {
                        return "Códigos muito similares (diferença de {$dist} caractere)";
                    }
                }
            }
        }

        // Compare names (exact match after normalization)
        if ($a['name_normalized'] !== '' && $a['name_normalized'] === $b['name_normalized']) {
            return 'Nomes idênticos de produtos';
        }

        return null;
    }

    /**
     * Compare an imported item against an existing DB product.
     */
    private function compareItemToExisting(array $imp, array $ex): ?string
    {
        // Compare normalized codes (import vs DB ref/sku/model)
        foreach ($imp['codes'] as $impCode) {
            if ($impCode === '') {
                continue;
            }
            foreach ($ex['codes'] as $exCode) {
                if ($exCode === '') {
                    continue;
                }
                if ($impCode === $exCode) {
                    return 'Código corresponde a produto existente no banco (após normalização)';
                }
                if (strlen($impCode) >= 4 && strlen($exCode) >= 4) {
                    $dist = levenshtein($impCode, $exCode);
                    if ($dist <= 1) {
                        return "Código muito similar a produto existente (diferença de {$dist} caractere)";
                    }
                }
            }
        }

        // Compare names
        if ($imp['name_normalized'] !== '' && $imp['name_normalized'] === $ex['name_normalized']) {
            return 'Nome idêntico a produto existente no banco';
        }

        return null;
    }

    /**
     * Merge local and AI matches, deduplicating by row + type.
     */
    private function mergeMatches(array $localMatches, array $aiMatches): array
    {
        $seen = [];
        $merged = [];

        // Local matches take priority (they're always correct)
        foreach ($localMatches as $m) {
            $key = ($m['type'] ?? '') . ':' . ($m['row'] ?? 0);
            $seen[$key] = true;
            $merged[] = $m;
        }

        // Add AI matches that aren't already covered
        foreach ($aiMatches as $m) {
            $key = ($m['type'] ?? '') . ':' . ($m['row'] ?? 0);
            if (! isset($seen[$key])) {
                $merged[] = $m;
            }
        }

        return $merged;
    }

    /**
     * Get existing products from the database for comparison.
     * Uses targeted queries based on import codes/names instead of a blanket limit.
     */
    private function getExistingProducts(array $categoryIds, array $importRows = []): array
    {
        try {
            // Collect all codes/names from the import batch for targeted lookup
            $importCodes = [];
            $importNames = [];
            foreach ($importRows as $row) {
                foreach (['reference_code', 'model_number', 'external_code'] as $field) {
                    $val = trim($row[$field] ?? '');
                    if ($val !== '') {
                        $importCodes[] = $val;
                        // Also add normalized variant (without separators)
                        $normalized = mb_strtolower(preg_replace('/[\s\-\._\/\\\\]+/', '', $val));
                        if ($normalized !== mb_strtolower($val)) {
                            $importCodes[] = $normalized;
                        }
                    }
                }
                $name = trim($row['name'] ?? ($row['product_name'] ?? ''));
                if ($name !== '') {
                    $importNames[] = $name;
                }
            }

            $importCodes = array_unique($importCodes);
            $importNames = array_unique($importNames);

            // Targeted query: products matching any of the import codes or names
            $targetedProducts = collect();

            if (! empty($importCodes)) {
                $codeMatches = Product::query()
                    ->select(['id', 'name', 'sku', 'reference_code', 'model_number', 'brand'])
                    ->where(function ($q) use ($importCodes) {
                        $q->whereIn('reference_code', $importCodes)
                            ->orWhereIn('sku', $importCodes)
                            ->orWhereIn('model_number', $importCodes);
                    })
                    ->limit(500)
                    ->get();
                $targetedProducts = $targetedProducts->merge($codeMatches);
            }

            if (! empty($importNames)) {
                $nameMatches = Product::query()
                    ->select(['id', 'name', 'sku', 'reference_code', 'model_number', 'brand'])
                    ->whereIn('name', $importNames)
                    ->whereNotIn('id', $targetedProducts->pluck('id'))
                    ->limit(500)
                    ->get();
                $targetedProducts = $targetedProducts->merge($nameMatches);
            }

            // Also include same-category products for Levenshtein near-matches
            if (! empty($categoryIds)) {
                $categoryMatches = Product::query()
                    ->select(['id', 'name', 'sku', 'reference_code', 'model_number', 'brand'])
                    ->whereIn('category_id', $categoryIds)
                    ->whereNotIn('id', $targetedProducts->pluck('id'))
                    ->where(function ($q) {
                        $q->whereNotNull('reference_code')
                            ->orWhereNotNull('model_number');
                    })
                    ->limit(500)
                    ->get();
                $targetedProducts = $targetedProducts->merge($categoryMatches);
            }

            $products = $targetedProducts
                ->unique('id')
                ->map(fn ($p) => [
                    'id' => $p->id,
                    'name' => $p->name ?? '',
                    'sku' => $p->sku ?? '',
                    'reference_code' => $p->reference_code ?? '',
                    'model_number' => $p->model_number ?? '',
                    'brand' => $p->brand ?? '',
                ])
                ->values()
                ->toArray();

            Log::info('PreImportAnalysis: getExistingProducts returned ' . count($products) . ' products (targeted lookup)', [
                'import_codes' => count($importCodes),
                'import_names' => count($importNames),
                'sample' => array_slice($products, 0, 3),
            ]);

            return $products;
        } catch (\Throwable $e) {
            Log::error('PreImportAnalysis: getExistingProducts failed: ' . $e->getMessage());

            return [];
        }
    }
}
