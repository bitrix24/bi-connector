<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DataSource\RowLimit;
use App\QueryBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Doctrine\DBAL\Result;
use PHPUnit\Framework\Attributes\DataProvider;
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
        // Test empty data
        $this->assertSame([], $this->formatDataForBitrix([], []));

        // Test with data
        $rows = [
            ['id' => 1, 'name' => 'John', 'age' => 30],
            ['id' => 2, 'name' => 'Jane', 'age' => 25]
        ];
        $select = ['id', 'name'];

        $result = $this->formatDataForBitrix($rows, $select);

        // Should return header row + data rows
        $this->assertCount(3, $result);
        $this->assertEquals(['id', 'name'], $result[0]); // Header
        $this->assertEquals([1, 'John'], $result[1]); // First row
        $this->assertEquals([2, 'Jane'], $result[2]); // Second row
    }

    public function testFormatDataForBitrixWithAllFields(): void
    {
        $rows = [
            ['id' => 1, 'name' => 'John', 'age' => 30]
        ];
        $select = []; // Empty select means all fields

        $result = $this->formatDataForBitrix($rows, $select);

        $this->assertCount(2, $result);
        $this->assertEquals(['id', 'name', 'age'], $result[0]); // Header with all fields
        $this->assertEquals([1, 'John', 30], $result[1]); // Data row
    }

    public function testFormatDataForBitrixKeepsTheSelectOrderAndFillsMissingValues(): void
    {
        $rows = [
            ['id' => 1, 'name' => null],
        ];

        $result = $this->formatDataForBitrix($rows, ['name', 'id']);

        $this->assertSame([['name', 'id'], [null, 1]], $result);
    }

    public function testFormatDataForBitrixDeliversRowsOneByOne(): void
    {
        $rows = [
            ['id' => 1],
            ['id' => 2],
        ];

        $generator = $this->invokeFormatDataForBitrix($rows, []);

        $this->assertSame(['id'], $generator->current());
        $generator->next();
        $this->assertSame([1], $generator->current());
        $generator->next();
        $this->assertSame([2], $generator->current());
        $generator->next();
        $this->assertFalse($generator->valid());
    }

    public function testBuildAndExecuteQueryStreamsRowsAndFreesTheResult(): void
    {
        $rows = [
            ['ID' => 1, 'NAME' => 'John'],
            ['ID' => 2, 'NAME' => 'Jane'],
        ];

        $result = $this->createMock(Result::class);
        $result->method('iterateAssociative')->willReturn(new \ArrayIterator($rows));
        $result->expects($this->once())->method('free');

        $dbalQueryBuilder = $this->createMock(DBALQueryBuilder::class);
        $dbalQueryBuilder->method('getSQL')->willReturn('SELECT * FROM orders');
        $dbalQueryBuilder->method('getParameters')->willReturn([]);
        $dbalQueryBuilder->expects($this->once())->method('setMaxResults')->with(100);
        $dbalQueryBuilder->expects($this->once())->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')->willReturn($dbalQueryBuilder);
        $this->connection->method('quoteIdentifier')->willReturnCallback(
            static fn(string $identifier): string => '`' . $identifier . '`'
        );

        $generator = $this->queryBuilder->buildAndExecuteQuery('orders', [], [], 100);

        // The result is released exactly once, after the caller has drained the rows
        $this->assertSame(
            [['ID', 'NAME'], [1, 'John'], [2, 'Jane']],
            iterator_to_array($generator, false)
        );
    }

    /**
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function rowLimitProvider(): array
    {
        return [
            'a limit below the bound of the application is kept' => [100, 1000, 100],
            'a limit of zero means the bound and not the absence of one' => [0, 1000, 1000],
            'a negative limit means the bound as well' => [-1, 1000, 1000],
        ];
    }

    #[DataProvider('rowLimitProvider')]
    public function testEveryStatementCarriesTheRowBoundOfTheApplication(
        int $requestedLimit,
        int $maximum,
        int $expectedLimit
    ): void {
        $result = $this->createMock(Result::class);
        $result->method('iterateAssociative')->willReturn(new \ArrayIterator([]));

        $dbalQueryBuilder = $this->createMock(DBALQueryBuilder::class);
        $dbalQueryBuilder->method('getSQL')->willReturn('SELECT * FROM orders');
        $dbalQueryBuilder->method('getParameters')->willReturn([]);
        $dbalQueryBuilder->expects($this->once())->method('setMaxResults')->with($expectedLimit);
        $dbalQueryBuilder->method('executeQuery')->willReturn($result);

        $this->connection->method('createQueryBuilder')->willReturn($dbalQueryBuilder);
        $this->connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '`' . $identifier . '`'
        );

        $queryBuilder = new QueryBuilder($this->connection, $this->logger, new RowLimit($maximum));

        iterator_to_array($queryBuilder->buildAndExecuteQuery('orders', [], [], $requestedLimit), false);
    }

    public function testALimitAboveTheBoundOfTheApplicationRefusesTheStatement(): void
    {
        $dbalQueryBuilder = $this->createMock(DBALQueryBuilder::class);
        $dbalQueryBuilder->expects($this->never())->method('executeQuery');

        $this->connection->method('createQueryBuilder')->willReturn($dbalQueryBuilder);
        $this->connection->method('quoteIdentifier')->willReturnCallback(
            static fn (string $identifier): string => '`' . $identifier . '`'
        );

        $queryBuilder = new QueryBuilder($this->connection, $this->logger, new RowLimit(1000));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The request asks for 5000 rows, which is above the 1000 rows');

        iterator_to_array($queryBuilder->buildAndExecuteQuery('orders', [], [], 5000), false);
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

    /**
     * @return list<array<int, mixed>>
     */
    private function formatDataForBitrix(array $rows, array $select): array
    {
        return iterator_to_array($this->invokeFormatDataForBitrix($rows, $select), false);
    }

    private function invokeFormatDataForBitrix(array $rows, array $select): \Generator
    {
        $reflection = new \ReflectionClass($this->queryBuilder);
        $method = $reflection->getMethod('formatDataForBitrix');
        $method->setAccessible(true);

        return $method->invoke($this->queryBuilder, $rows, $select);
    }
}
