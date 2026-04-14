<?php

namespace App\Domain\Catalog\Actions\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads spreadsheet files (XLSX, XLS, CSV) into a unified row array format.
 * Handles encoding normalization for CSV files from various sources.
 */
class SpreadsheetReader
{
    private const MAX_ROWS = 5000;

    /**
     * Read a file and return rows as arrays of trimmed strings.
     * Supports .xlsx, .xls, and .csv files.
     *
     * @return array{rows: array, row_origins: array}
     */
    public static function read(string $path): array
    {
        ini_set('memory_limit', '512M');

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($extension === 'csv' || $extension === 'tsv') {
            return self::readCsv($path, $extension === 'tsv' ? "\t" : null);
        }

        return self::readExcel($path);
    }

    /**
     * Check if a file path is a CSV/TSV file.
     */
    public static function isCsv(string $path): bool
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($ext, ['csv', 'tsv']);
    }

    /**
     * Read an Excel file (.xlsx, .xls) using PhpSpreadsheet.
     */
    private static function readExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();

        $rawData = $worksheet->toArray(null, true, false, false);

        $spreadsheet->disconnectWorksheets();
        unset($spreadsheet, $worksheet);

        return self::filterRows($rawData);
    }

    /**
     * Read a CSV file with automatic encoding and delimiter detection.
     */
    private static function readCsv(string $path, ?string $forceDelimiter = null): array
    {
        $rawContent = file_get_contents($path);
        if ($rawContent === false) {
            return ['rows' => [], 'row_origins' => []];
        }

        // Normalize encoding to UTF-8
        $rawContent = self::normalizeEncoding($rawContent);

        // Detect delimiter if not forced
        $delimiter = $forceDelimiter ?? self::detectCsvDelimiter($rawContent);

        // Parse CSV from string
        $lines = explode("\n", $rawContent);
        $rawData = [];

        foreach ($lines as $line) {
            $line = rtrim($line, "\r");
            if ($line === '') {
                continue;
            }

            $parsed = str_getcsv($line, $delimiter, '"', '\\');
            $rawData[] = $parsed;
        }

        return self::filterRows($rawData);
    }

    /**
     * Normalize file content encoding to UTF-8.
     * Handles BOM, GB2312/GBK (Chinese), ISO-8859-1 (Latin), and Windows-1252.
     */
    private static function normalizeEncoding(string $content): string
    {
        // Strip UTF-8 BOM
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }
        // Strip UTF-16 LE BOM
        if (str_starts_with($content, "\xFF\xFE")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }
        // Strip UTF-16 BE BOM
        if (str_starts_with($content, "\xFE\xFF")) {
            $content = mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        // Detect and convert non-UTF-8 encodings
        if (! mb_check_encoding($content, 'UTF-8')) {
            $detected = mb_detect_encoding($content, ['UTF-8', 'GB2312', 'GBK', 'GB18030', 'ISO-8859-1', 'Windows-1252'], true);
            if ($detected && $detected !== 'UTF-8') {
                $content = mb_convert_encoding($content, 'UTF-8', $detected);
            }
        }

        return $content;
    }

    /**
     * Auto-detect CSV delimiter by sampling the first few lines.
     * Supports: comma, semicolon, tab, pipe.
     */
    private static function detectCsvDelimiter(string $content): string
    {
        $sample = substr($content, 0, 4096);
        $delimiters = [',' => 0, ';' => 0, "\t" => 0, '|' => 0];

        foreach ($delimiters as $d => &$count) {
            $count = substr_count($sample, $d);
        }

        arsort($delimiters);

        return array_key_first($delimiters);
    }

    /**
     * Filter empty rows, trim values, and track original row numbers.
     */
    private static function filterRows(array $rawData): array
    {
        $rows = [];
        $rowOrigins = [];
        $maxRows = min(count($rawData), self::MAX_ROWS);

        for ($r = 0; $r < $maxRows; $r++) {
            $values = array_map(fn ($v) => trim((string) ($v ?? '')), $rawData[$r]);
            if (implode('', $values) !== '') {
                $rowOrigins[count($rows)] = $r + 1; // 1-based spreadsheet row
                $rows[] = $values;
            }
        }

        return ['rows' => $rows, 'row_origins' => $rowOrigins];
    }
}
