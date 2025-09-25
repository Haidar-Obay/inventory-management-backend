<?php

namespace App\Helpers;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class MigrationHeaderExtractor
{
    /**
     * Normalize a header label for robust comparisons
     */
    private static function normalizeHeader(string $label): string
    {
        // Trim, lowercase, collapse internal whitespace
        $label = trim(mb_strtolower($label));
        $label = preg_replace('/\s+/u', ' ', $label);
        // Replace spaces and dashes with underscores
        $label = str_replace([' ', '-'], '_', $label);
        // Remove non alphanumeric/underscore characters (e.g., punctuation, accents will be simplified below when possible)
        $label = preg_replace('/[^a-z0-9_]/u', '', $label);
        return $label;
    }
    /**
     * Extract column headers from a migration file
     *
     * @param string $tableName
     * @return array
     */
    public static function extractHeadersFromMigration(string $tableName): array
    {
        $migrationFile = self::findMigrationFile($tableName);
        
        if (!$migrationFile) {
            return [];
        }

        $content = File::get($migrationFile);
        $headers = [];

        // Extract column definitions from the migration file
        preg_match_all('/\$table->(?:string|enum|integer|bigInteger|unsignedBigInteger|boolean|text|json|date|datetime|timestamp|foreignId|decimal)\([\'"]([^\'"]+)[\'"]/', $content, $matches);
        
        if (!empty($matches[1])) {
            $headers = $matches[1];
        }

        // Remove system columns that are typically not in Excel imports
        $systemColumns = ['id', 'created_at', 'updated_at', 'deleted_at'];
        $headers = array_diff($headers, $systemColumns);

        return array_values($headers);
    }

    /**
     * Find the migration file for a given table name
     *
     * @param string $tableName
     * @return string|null
     */
    private static function findMigrationFile(string $tableName): ?string
    {
        $migrationPath = database_path('migrations/tenant');
        
        if (!File::exists($migrationPath)) {
            return null;
        }

        $files = File::files($migrationPath);
        
        foreach ($files as $file) {
            $content = File::get($file->getPathname());
            
            // Look for the table creation with the specific table name
            if (preg_match('/Schema::create\([\'"]([^\'"]+)[\'"]/', $content, $matches)) {
                if ($matches[1] === $tableName) {
                    return $file->getPathname();
                }
            }
        }

        return null;
    }

    /**
     * Get expected headers for a model class
     *
     * @param string $modelClass
     * @return array
     */
    public static function getExpectedHeadersForModel(string $modelClass): array
    {
        // Extract table name from model class
        $model = new $modelClass();
        $tableName = $model->getTable();
        
        return self::extractHeadersFromMigration($tableName);
    }

    /**
     * Compare Excel headers with expected headers
     *
     * @param array $excelHeaders
     * @param array $expectedHeaders
     * @return array
     */
    public static function compareHeaders(array $excelHeaders, array $expectedHeaders, array $requiredHeaders = []): array
    {
        // Normalize both excel and expected headers
        $excelHeaders = array_map([self::class, 'normalizeHeader'], $excelHeaders);
        $expectedHeaders = array_map([self::class, 'normalizeHeader'], $expectedHeaders);
        $requiredHeaders = array_map([self::class, 'normalizeHeader'], $requiredHeaders);
        
        // Compute missing/extra
        $missing = array_diff($expectedHeaders, $excelHeaders);
        $extra = array_diff($excelHeaders, $expectedHeaders);
        $matches = array_intersect($excelHeaders, $expectedHeaders);
        // Determine required headers missing
        $missingRequired = array_diff($requiredHeaders, $excelHeaders);
        
        return [
            // Valid if all required headers are present; optional expected columns may be missing
            'valid' => empty($missingRequired),
            'missing' => array_values($missing),
            'missing_required' => array_values($missingRequired),
            'extra' => array_values($extra),
            'matches' => array_values($matches),
            'excel_headers' => $excelHeaders,
            'expected_headers' => $expectedHeaders
        ];
    }
}