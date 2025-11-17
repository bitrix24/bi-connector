<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\BiConnector;
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

    public function testMapMySQLTypeToBitrix(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('mapMySQLTypeToBitrix');
        $method->setAccessible(true);

        // Test integer types
        $this->assertEquals('int', $method->invoke($connector, 'int(11)'));
        $this->assertEquals('int', $method->invoke($connector, 'bigint(20)'));
        $this->assertEquals('int', $method->invoke($connector, 'tinyint(1)'));

        // Test float types
        $this->assertEquals('double', $method->invoke($connector, 'float'));
        $this->assertEquals('double', $method->invoke($connector, 'double'));
        $this->assertEquals('double', $method->invoke($connector, 'decimal(10,2)'));

        // Test date types
        $this->assertEquals('date', $method->invoke($connector, 'date'));
        $this->assertEquals('datetime', $method->invoke($connector, 'datetime'));
        $this->assertEquals('datetime', $method->invoke($connector, 'timestamp'));

        // Test string types
        $this->assertEquals('string', $method->invoke($connector, 'varchar(255)'));
        $this->assertEquals('string', $method->invoke($connector, 'text'));
    }

    public function testMapPostgreSQLTypeToBitrix(): void
    {
        $connector = new BiConnector($this->connectionParams, 'postgresql', $this->logger);

        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('mapPostgreSQLTypeToBitrix');
        $method->setAccessible(true);

        // Test integer types
        $this->assertEquals('int', $method->invoke($connector, 'integer'));
        $this->assertEquals('int', $method->invoke($connector, 'bigint'));
        $this->assertEquals('int', $method->invoke($connector, 'serial'));

        // Test float types
        $this->assertEquals('double', $method->invoke($connector, 'real'));
        $this->assertEquals('double', $method->invoke($connector, 'double precision'));
        $this->assertEquals('double', $method->invoke($connector, 'numeric'));

        // Test date types
        $this->assertEquals('date', $method->invoke($connector, 'date'));
        $this->assertEquals('datetime', $method->invoke($connector, 'timestamp'));
        $this->assertEquals('datetime', $method->invoke($connector, 'timestamp with time zone'));

        // Test string types
        $this->assertEquals('string', $method->invoke($connector, 'character varying'));
        $this->assertEquals('string', $method->invoke($connector, 'text'));
    }

    public function testBuildDsn(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('buildDsn');
        $method->setAccessible(true);

        // Test MySQL DSN
        $mysqlDsn = $method->invoke($connector, 'mysql');
        $this->assertStringContainsString('mysql://', $mysqlDsn);
        $this->assertStringContainsString('localhost', $mysqlDsn);
        $this->assertStringContainsString('3306', $mysqlDsn);
        $this->assertStringContainsString('test_db', $mysqlDsn);

        // Test PostgreSQL DSN
        $pgDsn = $method->invoke($connector, 'postgresql');
        $this->assertStringContainsString('postgresql://', $pgDsn);
        $this->assertStringContainsString('localhost', $pgDsn);
        $this->assertStringContainsString('test_db', $pgDsn);
    }

    public function testBuildDsnWithInvalidType(): void
    {
        $connector = new BiConnector($this->connectionParams, 'mysql', $this->logger);

        $reflection = new \ReflectionClass($connector);
        $method = $reflection->getMethod('buildDsn');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported connection type: invalid');

        $method->invoke($connector, 'invalid');
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
}
