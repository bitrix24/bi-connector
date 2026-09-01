<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\Dbal;

use App\DataSource\ConnectionType;
use App\DataSource\Dbal\DbalDataSource;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Statement;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class DbalDataSourceTest extends TestCase
{
    private array $connectionParams;

    protected function setUp(): void
    {
        $this->connectionParams = [
            'host' => 'localhost',
            'port' => '3306',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass'
        ];
    }

    public function testMapMySQLTypeToBitrix(): void
    {
        $dataSource = $this->createDataSource(ConnectionType::Mysql);

        $reflection = new \ReflectionClass($dataSource);
        $method = $reflection->getMethod('mapMySQLTypeToBitrix');
        $method->setAccessible(true);

        // Test integer types
        $this->assertEquals('int', $method->invoke($dataSource, 'int(11)'));
        $this->assertEquals('int', $method->invoke($dataSource, 'bigint(20)'));
        $this->assertEquals('int', $method->invoke($dataSource, 'tinyint(1)'));

        // Test float types
        $this->assertEquals('double', $method->invoke($dataSource, 'float'));
        $this->assertEquals('double', $method->invoke($dataSource, 'double'));
        $this->assertEquals('double', $method->invoke($dataSource, 'decimal(10,2)'));

        // Test date types
        $this->assertEquals('date', $method->invoke($dataSource, 'date'));
        $this->assertEquals('datetime', $method->invoke($dataSource, 'datetime'));
        $this->assertEquals('datetime', $method->invoke($dataSource, 'timestamp'));

        // Test string types
        $this->assertEquals('string', $method->invoke($dataSource, 'varchar(255)'));
        $this->assertEquals('string', $method->invoke($dataSource, 'text'));
    }

    public function testMapPostgreSQLTypeToBitrix(): void
    {
        $dataSource = $this->createDataSource(ConnectionType::Postgresql);

        $reflection = new \ReflectionClass($dataSource);
        $method = $reflection->getMethod('mapPostgreSQLTypeToBitrix');
        $method->setAccessible(true);

        // Test integer types
        $this->assertEquals('int', $method->invoke($dataSource, 'integer'));
        $this->assertEquals('int', $method->invoke($dataSource, 'bigint'));
        $this->assertEquals('int', $method->invoke($dataSource, 'serial'));

        // Test float types
        $this->assertEquals('double', $method->invoke($dataSource, 'real'));
        $this->assertEquals('double', $method->invoke($dataSource, 'double precision'));
        $this->assertEquals('double', $method->invoke($dataSource, 'numeric'));

        // Test date types
        $this->assertEquals('date', $method->invoke($dataSource, 'date'));
        $this->assertEquals('datetime', $method->invoke($dataSource, 'timestamp'));
        $this->assertEquals('datetime', $method->invoke($dataSource, 'timestamp with time zone'));

        // Test string types
        $this->assertEquals('string', $method->invoke($dataSource, 'character varying'));
        $this->assertEquals('string', $method->invoke($dataSource, 'text'));
    }

    public function testBuildDsn(): void
    {
        $dataSource = $this->createDataSource(ConnectionType::Mysql);

        $reflection = new \ReflectionClass($dataSource);
        $method = $reflection->getMethod('buildDsn');
        $method->setAccessible(true);

        // Test MySQL DSN
        $mysqlDsn = $method->invoke($dataSource, ConnectionType::Mysql);
        $this->assertStringContainsString('mysql://', $mysqlDsn);
        $this->assertStringContainsString('localhost', $mysqlDsn);
        $this->assertStringContainsString('3306', $mysqlDsn);
        $this->assertStringContainsString('test_db', $mysqlDsn);

        // Test PostgreSQL DSN
        $pgDsn = $method->invoke($dataSource, ConnectionType::Postgresql);
        $this->assertStringContainsString('postgresql://', $pgDsn);
        $this->assertStringContainsString('localhost', $pgDsn);
        $this->assertStringContainsString('test_db', $pgDsn);
    }

    public function testBuildDsnWithConnectionTypeOutsideDbal(): void
    {
        $dataSource = $this->createDataSource(ConnectionType::Mysql);

        $reflection = new \ReflectionClass($dataSource);
        $method = $reflection->getMethod('buildDsn');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported connection type: clickhouse');

        $method->invoke($dataSource, ConnectionType::Clickhouse);
    }

    public function testCheckProbesTheConnectionWithASelectQuery(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturn(['test' => 1]);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('executeQuery')
            ->with('SELECT 1 as test')
            ->willReturn($result);

        $this->createDataSource(ConnectionType::Mysql, $connection)->check();
    }

    public function testListTablesForMysql(): void
    {
        $result = $this->createMock(Result::class);
        $result
            ->method('fetchAssociative')
            ->willReturnOnConsecutiveCalls(['Tables_in_test_db' => 'orders'], ['Tables_in_test_db' => 'users'], false);

        $statement = $this->createMock(Statement::class);
        $statement->expects($this->never())->method('bindValue');
        $statement->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('prepare')
            ->with('SHOW TABLES')
            ->willReturn($statement);

        $tables = $this->createDataSource(ConnectionType::Mysql, $connection)->listTables('');

        $this->assertSame([
            ['code' => 'orders', 'title' => 'orders'],
            ['code' => 'users', 'title' => 'users'],
        ], $tables);
    }

    public function testListTablesForPostgresqlAppliesTheSearchString(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(['table_name' => 'orders'], false);

        $statement = $this->createMock(Statement::class);
        $statement->expects($this->once())->method('bindValue')->with('search', '%ord%');
        $statement->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('AND tablename LIKE :search'))
            ->willReturn($statement);

        $tables = $this->createDataSource(ConnectionType::Postgresql, $connection)->listTables('ord');

        $this->assertSame([['code' => 'orders', 'title' => 'orders']], $tables);
    }

    public function testDescribeTableForMysql(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['Field' => 'ID', 'Type' => 'int(11)'],
            ['Field' => 'TITLE', 'Type' => 'varchar(255)'],
            false
        );

        $statement = $this->createMock(Statement::class);
        $statement->expects($this->never())->method('bindValue');
        $statement->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('prepare')
            ->with('DESCRIBE `orders`')
            ->willReturn($statement);

        $fields = $this->createDataSource(ConnectionType::Mysql, $connection)->describeTable('orders');

        $this->assertSame([
            ['code' => 'ID', 'name' => 'ID', 'type' => 'int'],
            ['code' => 'TITLE', 'name' => 'TITLE', 'type' => 'string'],
        ], $fields);
    }

    public function testDescribeTableForPostgresqlBindsTheTableName(): void
    {
        $result = $this->createMock(Result::class);
        $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['column_name' => 'ID', 'data_type' => 'bigint', 'is_nullable' => 'NO'],
            ['column_name' => 'CREATED_AT', 'data_type' => 'timestamp with time zone', 'is_nullable' => 'YES'],
            false
        );

        $statement = $this->createMock(Statement::class);
        $statement->expects($this->once())->method('bindValue')->with('table_name', 'orders');
        $statement->method('executeQuery')->willReturn($result);

        $connection = $this->createMock(Connection::class);
        $connection
            ->expects($this->once())
            ->method('prepare')
            ->with($this->stringContains('information_schema.columns'))
            ->willReturn($statement);

        $fields = $this->createDataSource(ConnectionType::Postgresql, $connection)->describeTable('orders');

        $this->assertSame([
            ['code' => 'ID', 'name' => 'ID', 'type' => 'int'],
            ['code' => 'CREATED_AT', 'name' => 'CREATED_AT', 'type' => 'datetime'],
        ], $fields);
    }

    private function createDataSource(ConnectionType $connectionType, ?Connection $connection = null): DbalDataSource
    {
        $dataSource = new DbalDataSource($this->connectionParams, $connectionType, new NullLogger());

        if ($connection !== null) {
            $property = new \ReflectionProperty($dataSource, 'connection');
            $property->setAccessible(true);
            $property->setValue($dataSource, $connection);
        }

        return $dataSource;
    }
}
