<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\QueryBuilder;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class QueryBuilderTest extends TestCase
{
    private Connection $connection;
    private LoggerInterface $logger;
    private QueryBuilder $queryBuilder;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(Connection::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->queryBuilder = new QueryBuilder($this->connection, $this->logger);
    }

    public function testConstructor(): void
    {
        $this->assertInstanceOf(QueryBuilder::class, $this->queryBuilder);
    }

    public function testFormatDataForBitrix(): void
    {
        $reflection = new \ReflectionClass($this->queryBuilder);
        $method = $reflection->getMethod('formatDataForBitrix');
        $method->setAccessible(true);

        // Test empty data
        $result = $method->invoke($this->queryBuilder, [], []);
        $this->assertEquals([], $result);

        // Test with data
        $rows = [
            ['id' => 1, 'name' => 'John', 'age' => 30],
            ['id' => 2, 'name' => 'Jane', 'age' => 25]
        ];
        $select = ['id', 'name'];

        $result = $method->invoke($this->queryBuilder, $rows, $select);

        // Should return header row + data rows
        $this->assertCount(3, $result);
        $this->assertEquals(['id', 'name'], $result[0]); // Header
        $this->assertEquals([1, 'John'], $result[1]); // First row
        $this->assertEquals([2, 'Jane'], $result[2]); // Second row
    }

    public function testFormatDataForBitrixWithAllFields(): void
    {
        $reflection = new \ReflectionClass($this->queryBuilder);
        $method = $reflection->getMethod('formatDataForBitrix');
        $method->setAccessible(true);

        $rows = [
            ['id' => 1, 'name' => 'John', 'age' => 30]
        ];
        $select = []; // Empty select means all fields

        $result = $method->invoke($this->queryBuilder, $rows, $select);

        $this->assertCount(2, $result);
        $this->assertEquals(['id', 'name', 'age'], $result[0]); // Header with all fields
        $this->assertEquals([1, 'John', 30], $result[1]); // Data row
    }

    public function testQuoteIdentifier(): void
    {
        $this->connection
            ->expects($this->once())
            ->method('quoteIdentifier')
            ->with('test_field')
            ->willReturn('`test_field`');

        $reflection = new \ReflectionClass($this->queryBuilder);
        $method = $reflection->getMethod('quoteIdentifier');
        $method->setAccessible(true);

        $result = $method->invoke($this->queryBuilder, 'test_field');
        $this->assertEquals('`test_field`', $result);
    }
}
