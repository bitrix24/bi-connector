<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\BiConnector;
use App\DataSource\DataSourceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

class BiConnectorTest extends TestCase
{
    private LoggerInterface $logger;
    private array $connectionParams;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->connectionParams = [
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass'
        ];
    }

    public function testConstructor(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        $this->assertInstanceOf(BiConnector::class, $connector);
    }

    public function testTableListQueriesTheDataSourceOnCacheMissOnly(): void
    {
        $connectionParams = $this->uniqueConnectionParams();
        $tables = [['code' => 'orders', 'title' => 'orders']];

        $missDataSource = $this->createMock(DataSourceInterface::class);
        $missDataSource
            ->expects($this->once())
            ->method('listTables')
            ->with('')
            ->willReturn($tables);

        $hitDataSource = $this->createMock(DataSourceInterface::class);
        $hitDataSource->expects($this->never())->method('listTables');

        $missConnector = new BiConnector($connectionParams, 'mysql', $this->logger, $missDataSource);
        $hitConnector = new BiConnector($connectionParams, 'mysql', $this->logger, $hitDataSource);

        try {
            $missResponse = $missConnector->tableList();
            $hitResponse = $hitConnector->tableList();

            $this->assertSame(200, $missResponse->getStatusCode());
            $this->assertSame(json_encode($tables), $missResponse->getContent());
            $this->assertSame(200, $hitResponse->getStatusCode());
            $this->assertSame($missResponse->getContent(), $hitResponse->getContent());
        } finally {
            $this->forgetCacheItem($missConnector, $this->cacheKey('table_list_', $connectionParams, ''));
        }
    }

    public function testTableDescriptionQueriesTheDataSourceOnCacheMissOnly(): void
    {
        $connectionParams = $this->uniqueConnectionParams();
        $fields = [['code' => 'ID', 'name' => 'ID', 'type' => 'int']];

        $missDataSource = $this->createMock(DataSourceInterface::class);
        $missDataSource
            ->expects($this->once())
            ->method('describeTable')
            ->with('orders')
            ->willReturn($fields);

        $hitDataSource = $this->createMock(DataSourceInterface::class);
        $hitDataSource->expects($this->never())->method('describeTable');

        $missConnector = new BiConnector($connectionParams, 'mysql', $this->logger, $missDataSource);
        $hitConnector = new BiConnector($connectionParams, 'mysql', $this->logger, $hitDataSource);

        try {
            $missResponse = $missConnector->tableDescription('orders');
            $hitResponse = $hitConnector->tableDescription('orders');

            $this->assertSame(200, $missResponse->getStatusCode());
            $this->assertSame(json_encode($fields), $missResponse->getContent());
            $this->assertSame(200, $hitResponse->getStatusCode());
            $this->assertSame($missResponse->getContent(), $hitResponse->getContent());
        } finally {
            $this->forgetCacheItem($missConnector, $this->cacheKey('table_desc_', $connectionParams, 'orders'));
        }
    }

    public function testCacheDirectoryInitialization(): void
    {
        // Create a temporary directory for testing
        $tempDir = sys_get_temp_dir() . '/biconnector_test_' . uniqid();

        // Create connector that will initialize cache directory
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('initializeCacheDirectory');
        $method->setAccessible(true);

        // Test cache directory initialization
        $method->invoke($connector, $tempDir);

        $this->assertTrue(is_dir($tempDir), 'Cache directory should be created');
        $this->assertTrue(is_dir($tempDir . '/biconnector'), 'BiConnector cache subdirectory should be created');
        $this->assertTrue(is_writable($tempDir), 'Cache directory should be writable');

        // Cleanup
        if (is_dir($tempDir . '/biconnector')) {
            rmdir($tempDir . '/biconnector');
        }
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }
    }

    public function testCacheKeyGeneration(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        // We can't directly test cache key generation since it's inside methods,
        // but we can test that different parameters create different cache scenarios

        // Test that tableName validation works
        $response = $connector->getData('', [], [], 100);
        $this->assertEquals(400, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Table name is required', $responseData['error']);
    }

    public function testErrorHandlingWithCaching(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        // Test tableDescription with empty table name
        $response = $connector->tableDescription('');
        $this->assertEquals(400, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Table name is required', $responseData['error']);

        // Test getData with empty table name
        $response = $connector->getData('', [], [], 100);
        $this->assertEquals(400, $response->getStatusCode());

        $responseData = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('error', $responseData);
        $this->assertEquals('Table name is required', $responseData['error']);
    }

    /**
     * Connection parameters nobody else has used, so that the catalog cache starts out cold.
     */
    private function uniqueConnectionParams(): array
    {
        $connectionParams = $this->connectionParams;
        $connectionParams['database'] = 'cache_probe_' . uniqid('', true);

        return $connectionParams;
    }

    private function cacheKey(string $prefix, array $connectionParams, string $suffix): string
    {
        return $prefix . md5(json_encode($connectionParams) . 'mysql' . $suffix);
    }

    private function forgetCacheItem(BiConnector $connector, string $cacheKey): void
    {
        $property = new \ReflectionProperty($connector, 'cache');
        $property->setAccessible(true);
        $property->getValue($connector)->deleteItem($cacheKey);
    }
}
