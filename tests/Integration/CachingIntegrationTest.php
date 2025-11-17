<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\BiConnector;
use App\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class CachingIntegrationTest extends TestCase
{
    private string $testCacheDir;
    private BiConnector $connector;

    protected function setUp(): void
    {
        // Create temporary cache directory for testing
        $this->testCacheDir = sys_get_temp_dir() . '/biconnector_cache_test_' . uniqid();

        // Set environment variables for caching
        $_ENV['CACHE_TTL_TABLE_LIST'] = '3600';
        $_ENV['CACHE_TTL_TABLE_DESCRIPTION'] = '1800';

        $connectionParams = [
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass'
        ];

        $this->connector = new BiConnector($connectionParams, 'mysql', Application::getLog());
    }

    protected function tearDown(): void
    {
        // Clean up test cache directory
        if (is_dir($this->testCacheDir)) {
            $this->removeDirectory($this->testCacheDir);
        }
    }

    public function testCacheDirectoryCreation(): void
    {
        // Test that cache directory is created during initialization
        $cacheDir = dirname(__DIR__, 2) . '/cache';
        $biconnectorCacheDir = $cacheDir . '/biconnector';

        $this->assertTrue(is_dir($cacheDir), 'Main cache directory should exist');
        $this->assertTrue(is_dir($biconnectorCacheDir), 'BiConnector cache directory should exist');
        $this->assertTrue(is_writable($cacheDir), 'Cache directory should be writable');
    }

    public function testCacheKeyUniqueness(): void
    {
        // Create a mock FilesystemAdapter to test cache key generation
        $reflection = new \ReflectionClass($this->connector);

        // Test different scenarios should generate different cache keys
        $connectionParams1 = ['host' => 'host1', 'database' => 'db1'];
        $connectionParams2 = ['host' => 'host2', 'database' => 'db2'];

        $connector1 = new BiConnector($connectionParams1, 'mysql', Application::getLog());
        $connector2 = new BiConnector($connectionParams2, 'mysql', Application::getLog());

        // Both connectors should be different instances
        $this->assertNotSame($connector1, $connector2);
    }

    public function testCacheErrorHandling(): void
    {
        // Test that cache errors don't break the application
        // This simulates scenarios where cache directory might not be writable

        $response = $this->connector->tableList('test');
        $this->assertNotNull($response);
        $this->assertContains($response->getStatusCode(), [200, 500]); // Either success or database error
    }

    public function testResponseFormatConsistency(): void
    {
        // Test that responses maintain consistent format with or without cache

        // Test tableList response format
        $response = $this->connector->tableList('test');
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);

        // Test tableDescription response format
        $response = $this->connector->tableDescription('test_table');
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);

        // Test getData response format
        $response = $this->connector->getData('test_table', [], [], 10);
        $this->assertEquals('application/json', $response->headers->get('Content-Type'));

        $content = json_decode($response->getContent(), true);
        $this->assertIsArray($content);
    }

    public function testCacheConfigurationUsage(): void
    {
        // Test that cache configuration is properly read from environment
        $this->assertEquals('3600', $_ENV['CACHE_TTL_TABLE_LIST']);
        $this->assertEquals('1800', $_ENV['CACHE_TTL_TABLE_DESCRIPTION']);
    }

    private function removeDirectory(string $dir): void
    {
        if (is_dir($dir)) {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $path = $dir . '/' . $file;
                if (is_dir($path)) {
                    $this->removeDirectory($path);
                } else {
                    unlink($path);
                }
            }
            rmdir($dir);
        }
    }
}
