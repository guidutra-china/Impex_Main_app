<?php

namespace App\Domain\Infrastructure\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ClaudeAnalysisService
{
    private string $apiKey;

    private string $model;

    private string $baseUrl = 'https://api.anthropic.com/v1/messages';

    public function __construct()
    {
        $this->apiKey = config('services.anthropic.key', '');
        $this->model = config('services.anthropic.model', 'claude-haiku-4-5-20251001');
    }

    /**
     * Check if the service is properly configured.
     */
    public function isConfigured(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Run a SINGLE combined analysis: duplicate detection + data validation.
     * This replaces the old separate detectDuplicatesAndMatches() + validateImportData() calls.
     *
     * @param  array  $importedRows  Rows from the import
     * @param  array  $existingProducts  Existing products from DB
     * @param  string  $categoryName  Category name for validation context
     * @return array{matches: array, warnings: array}
     */
    public function analyzeImport(array $importedRows, array $existingProducts = [], string $categoryName = ''): array
    {
        if (! $this->isConfigured() || empty($importedRows)) {
            Log::info('analyzeImport: skipping — configured=' . ($this->isConfigured() ? 'true' : 'false') . ', rows=' . count($importedRows));

            return ['matches' => [], 'warnings' => []];
        }

        $importedList = collect($importedRows)
            ->filter(fn ($row) => ! empty($row['reference_code']) || ! empty($row['name']) || ! empty($row['model_number']))
            ->map(fn ($row) => [
                'row' => $row['_row'] ?? 0,
                'ref' => $row['reference_code'] ?? '',
                'name' => $row['name'] ?? '',
                'model' => $row['model_number'] ?? '',
                'ext_code' => $row['company_external_code'] ?? $row['external_code'] ?? '',
                'price' => $row['unit_price'] ?? '',
            ])
            ->values()
            ->toArray();

        if (empty($importedList)) {
            return ['matches' => [], 'warnings' => []];
        }

        // Filter to relevant products, then cap at 300 for the AI prompt size
        // (PreImportAnalysis now sends targeted results, not a blanket 200)
        $existingList = collect($existingProducts)
            ->filter(fn ($p) => ! empty($p['reference_code']) || ! empty($p['sku']) || ! empty($p['name']) || ! empty($p['model_number']))
            ->map(fn ($p) => [
                'id' => $p['id'],
                'ref' => $p['reference_code'] ?? '',
                'name' => $p['name'] ?? '',
                'sku' => $p['sku'] ?? '',
                'model' => $p['model_number'] ?? '',
            ])
            ->values()
            ->take(300)
            ->toArray();

        Log::info('analyzeImport: sending to Claude', [
            'imported_count' => count($importedList),
            'existing_count' => count($existingList),
            'imported_sample' => array_slice($importedList, 0, 2),
            'existing_sample' => array_slice($existingList, 0, 2),
        ]);

        // Process in a single chunk (limit to 80 imported rows for speed)
        $importedChunk = array_slice($importedList, 0, 80);

        $result = $this->callClaudeCombinedAnalysis($importedChunk, $existingList, $categoryName);

        return $result;
    }

    /**
     * Legacy method — delegates to analyzeImport for backward compatibility.
     */
    public function detectDuplicatesAndMatches(array $importedRows, array $existingProducts = []): array
    {
        $result = $this->analyzeImport($importedRows, $existingProducts);

        return $result['matches'] ?? [];
    }

    /**
     * Legacy method — now returns empty (validation is combined into analyzeImport).
     */
    public function validateImportData(array $rows, string $categoryName): array
    {
        return [];
    }

    /**
     * Single Claude API call that handles BOTH duplicate detection AND data validation.
     */
    private function callClaudeCombinedAnalysis(array $importedChunk, array $existingList, string $categoryName): array
    {
        $existingSection = '';
        if (! empty($existingList)) {
            $existingSection = <<<SECTION

EXISTING PRODUCTS ALREADY IN DATABASE:
```json
{$this->jsonEncode($existingList)}
```
SECTION;
        }

        $prompt = <<<PROMPT
You are a product data specialist for an import/export trading company. Analyze this import data for duplicates and quality issues.

IMPORTED ROWS BEING IMPORTED NOW:
```json
{$this->jsonEncode($importedChunk)}
```
{$existingSection}

Perform these checks:

## 1. INTRA-BATCH DUPLICATES
Compare imported rows AGAINST EACH OTHER. Find rows that likely refer to the SAME product:
- Dashes, spaces, dots removed: "ABC-001" vs "ABC001" vs "ABC.001"
- Case differences: "abc123" vs "ABC123"
- Typos: "ABD-123" vs "ABC-123"
- Same name with different codes, or same model with different reference codes

## 2. MATCHES AGAINST DATABASE
If existing products are provided, find imported rows that MATCH an existing product:
- Compare imported "ref" with existing "ref", "sku", AND "name"
- "ABC-001" in DB matching "ABC001" in import is a HIGH confidence match
- Similar names with same model number = medium confidence
- THIS IS CRITICAL: even small differences in separators (dash, dot, space) indicate the SAME product

## 3. DATA QUALITY (brief)
Only flag SERIOUS issues: missing name AND code, extreme price outliers (10x difference), obviously wrong data.

Respond with a JSON object:
{
  "matches": [
    {
      "type": "intra_batch" | "vs_database",
      "row": <row number>,
      "imported_code": "<ref or name>",
      "duplicate_row": <other row (intra_batch) or null>,
      "matched_id": <DB product id (vs_database) or null>,
      "matched_code": "<ref/sku of match>",
      "matched_name": "<name of match>",
      "confidence": "high" | "medium",
      "reason": "<brief explanation in Portuguese>"
    }
  ],
  "warnings": [
    {
      "row": <row number>,
      "severity": "error" | "warning",
      "field": "<field>",
      "message": "<explanation in Portuguese>"
    }
  ]
}

If no matches or warnings, return: {"matches": [], "warnings": []}
PROMPT;

        try {
            Log::info('Claude API call (combined_analysis): sending request...');

            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout(30)
                ->post($this->baseUrl, [
                    'model' => $this->model,
                    'max_tokens' => 4096,
                    'messages' => [
                        [
                            'role' => 'user',
                            'content' => $prompt,
                        ],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('Claude API error (combined_analysis): ' . $response->status() . ' - ' . $response->body());

                return ['matches' => [], 'warnings' => []];
            }

            $body = $response->json();
            $text = $body['content'][0]['text'] ?? '';

            Log::info('Claude API response (combined_analysis): ' . mb_substr($text, 0, 500));

            // Parse JSON from response (handle potential markdown wrapping)
            $text = trim($text);
            if (str_starts_with($text, '```')) {
                $text = preg_replace('/^```(?:json)?\s*/m', '', $text);
                $text = preg_replace('/\s*```$/m', '', $text);
            }

            $decoded = json_decode(trim($text), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                Log::warning('Claude API parse error (combined_analysis): ' . json_last_error_msg() . ' — raw: ' . mb_substr($text, 0, 300));

                return ['matches' => [], 'warnings' => []];
            }

            // Handle both object and array responses
            if (isset($decoded['matches'])) {
                Log::info('Claude API (combined_analysis): ' . count($decoded['matches']) . ' matches, ' . count($decoded['warnings'] ?? []) . ' warnings');

                return [
                    'matches' => $decoded['matches'] ?? [],
                    'warnings' => $decoded['warnings'] ?? [],
                ];
            }

            // If Claude returned a flat array (old format), treat as matches only
            if (is_array($decoded) && isset($decoded[0]['type'])) {
                Log::info('Claude API (combined_analysis): ' . count($decoded) . ' matches (flat array)');

                return ['matches' => $decoded, 'warnings' => []];
            }

            return ['matches' => [], 'warnings' => []];

        } catch (\Throwable $e) {
            Log::error('Claude API exception (combined_analysis): ' . $e->getMessage());

            return ['matches' => [], 'warnings' => []];
        }
    }

    private function jsonEncode(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
