<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource;

use App\DataSource\ConnectionType;
use App\DataSource\DataSourceFactory;
use App\DataSource\DataSourceInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class ConnectionTypeTest extends TestCase
{
    public function testEnumHoldsExactlyTheSupportedConnectionTypes(): void
    {
        $this->assertSame(['mysql', 'postgresql', 'clickhouse'], ConnectionType::values());
    }

    public function testEveryCaseIsReachableByItsRequestValue(): void
    {
        $this->assertSame(ConnectionType::Mysql, ConnectionType::tryFrom('mysql'));
        $this->assertSame(ConnectionType::Postgresql, ConnectionType::tryFrom('postgresql'));
        $this->assertSame(ConnectionType::Clickhouse, ConnectionType::tryFrom('clickhouse'));
        $this->assertNull(ConnectionType::tryFrom('mssql'));
    }

    public function testFactoryRejectsConnectionTypeOutsideTheEnum(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Unsupported connection type "mssql"; supported types: mysql, postgresql, clickhouse'
        );

        $this->createFactory()->create('mssql', []);
    }

    public function testFactoryRejectsEmptyConnectionType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->createFactory()->create('', []);
    }

    /**
     * The factory recognises every enum value. Implementations are wired in per source family, so a value
     * without one yet fails as a wiring error, never as an unsupported connection type.
     */
    #[DataProvider('connectionTypeProvider')]
    public function testFactoryRecognisesEveryConnectionType(ConnectionType $type): void
    {
        try {
            $dataSource = $this->createFactory()->create($type->value, ['host' => 'localhost']);

            $this->assertInstanceOf(DataSourceInterface::class, $dataSource);
        } catch (\InvalidArgumentException $exception) {
            $this->fail(sprintf('Connection type "%s" was rejected: %s', $type->value, $exception->getMessage()));
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString($type->value, $exception->getMessage());
        }
    }

    /**
     * @return iterable<string, array{ConnectionType}>
     */
    public static function connectionTypeProvider(): iterable
    {
        foreach (ConnectionType::cases() as $type) {
            yield $type->value => [$type];
        }
    }

    public function testContractDeclaresTheFourSourceOperations(): void
    {
        $contract = new \ReflectionClass(DataSourceInterface::class);
        $operations = array_map(
            static fn(\ReflectionMethod $method): string => $method->getName(),
            $contract->getMethods()
        );

        sort($operations);

        $this->assertSame(['check', 'describeTable', 'fetchData', 'listTables'], $operations);
    }

    public function testFetchDataReturnsAnIterableSoRowsAreNeverHeldInMemory(): void
    {
        $returnType = (new \ReflectionMethod(DataSourceInterface::class, 'fetchData'))->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('iterable', $returnType->getName());
    }

    public function testCheckReportsFailureByThrowingInsteadOfReturningAValue(): void
    {
        $returnType = (new \ReflectionMethod(DataSourceInterface::class, 'check'))->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $returnType);
        $this->assertSame('void', $returnType->getName());
    }

    private function createFactory(): DataSourceFactory
    {
        return new DataSourceFactory(new NullLogger());
    }
}
