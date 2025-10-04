<?php

namespace Tests\Performance;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExamplePerformanceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test that basic API endpoints respond within acceptable time limits
     */
    public function test_api_endpoints_performance()
    {
        $startTime = microtime(true);

        // Test a basic endpoint (adjust based on your actual routes)
        $response = $this->get('/api/health');

        $endTime = microtime(true);
        $responseTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertLessThan(500, $responseTime, 'API endpoint should respond within 500ms');
        $this->assertEquals(200, $response->getStatusCode());
    }

    /**
     * Test database query performance
     */
    public function test_database_query_performance()
    {
        $startTime = microtime(true);

        // Test a simple database operation
        $users = \App\Models\User::count();

        $endTime = microtime(true);
        $queryTime = ($endTime - $startTime) * 1000; // Convert to milliseconds

        $this->assertLessThan(100, $queryTime, 'Database query should complete within 100ms');
        $this->assertIsInt($users);
    }

    /**
     * Test memory usage during operations
     */
    public function test_memory_usage()
    {
        $initialMemory = memory_get_usage();

        // Perform some operations that might use memory
        $data = [];
        for ($i = 0; $i < 1000; $i++) {
            $data[] = [
                'id' => $i,
                'name' => 'Test Item '.$i,
                'created_at' => now(),
            ];
        }

        $finalMemory = memory_get_usage();
        $memoryUsed = ($finalMemory - $initialMemory) / 1024 / 1024; // Convert to MB

        $this->assertLessThan(10, $memoryUsed, 'Memory usage should be less than 10MB for this operation');
    }
}
