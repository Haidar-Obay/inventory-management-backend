<?php

namespace Tests\Unit;

use App\Helpers\MigrationHeaderExtractor;
use App\Imports\DynamicExcelImport;
use App\Models\Trade;
use Tests\TestCase;

class HeaderValidationTest extends TestCase
{
    public function test_migration_header_extraction()
    {
        $headers = MigrationHeaderExtractor::extractHeadersFromMigration('trades');
        
        $this->assertIsArray($headers);
        $this->assertContains('code', $headers);
        $this->assertContains('name', $headers);
        $this->assertContains('active', $headers);
        $this->assertNotContains('id', $headers); // System column should be excluded
        $this->assertNotContains('created_at', $headers); // System column should be excluded
        $this->assertNotContains('updated_at', $headers); // System column should be excluded
    }

    public function test_header_comparison()
    {
        $excelHeaders = ['code', 'name', 'active'];
        $expectedHeaders = ['code', 'name', 'active'];
        
        $result = MigrationHeaderExtractor::compareHeaders($excelHeaders, $expectedHeaders);
        
        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['missing']);
        $this->assertEmpty($result['extra']);
        $this->assertCount(3, $result['matches']);
    }

    public function test_header_comparison_with_missing_headers()
    {
        $excelHeaders = ['code', 'name'];
        $expectedHeaders = ['code', 'name', 'active'];
        
        $result = MigrationHeaderExtractor::compareHeaders($excelHeaders, $expectedHeaders);
        
        $this->assertFalse($result['valid']);
        $this->assertContains('active', $result['missing']);
        $this->assertEmpty($result['extra']);
    }

    public function test_header_comparison_with_extra_headers()
    {
        $excelHeaders = ['code', 'name', 'active', 'extra_field'];
        $expectedHeaders = ['code', 'name', 'active'];
        
        $result = MigrationHeaderExtractor::compareHeaders($excelHeaders, $expectedHeaders);
        
        $this->assertFalse($result['valid']);
        $this->assertEmpty($result['missing']);
        $this->assertContains('extra_field', $result['extra']);
    }

    public function test_dynamic_excel_import_header_validation()
    {
        $import = new DynamicExcelImport(
            Trade::class,
            ['code', 'name'],
            function ($row) {
                return [];
            },
            function ($row) {
                return [
                    'code' => $row['code'],
                    'name' => $row['name'],
                    'active' => boolval($row['active'] ?? true),
                ];
            },
            true // Enable header validation
        );

        // Simulate a row with valid headers
        $validRow = ['code' => 'TEST', 'name' => 'Test Trade', 'active' => true];
        $import->model($validRow);

        $this->assertTrue($import->areHeadersValid());
        $this->assertIsArray($import->getHeaderValidationResult());
    }
}