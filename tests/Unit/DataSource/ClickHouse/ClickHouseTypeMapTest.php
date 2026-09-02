<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseTypeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClickHouseTypeMapTest extends TestCase
{
    /**
     * One input per row of the type map, so a changed row fails a test of its own.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function typeProvider(): iterable
    {
        yield 'Date' => ['Date', 'date'];
        yield 'Date32' => ['Date32', 'date'];
        yield 'DateTime' => ['DateTime', 'datetime'];
        yield 'DateTime64' => ['DateTime64(3)', 'datetime'];
        yield 'Float32' => ['Float32', 'double'];
        yield 'Float64' => ['Float64', 'double'];
        yield 'Decimal' => ['Decimal(38, 4)', 'double'];
        yield 'Decimal64' => ['Decimal64(4)', 'double'];
        yield 'Int8' => ['Int8', 'int'];
        yield 'Int16' => ['Int16', 'int'];
        yield 'Int32' => ['Int32', 'int'];
        yield 'Int64' => ['Int64', 'int'];
        yield 'UInt8' => ['UInt8', 'int'];
        yield 'UInt16' => ['UInt16', 'int'];
        yield 'UInt32' => ['UInt32', 'int'];
        yield 'UInt64' => ['UInt64', 'string'];
        yield 'Bool' => ['Bool', 'string'];
        yield 'Boolean' => ['Boolean', 'string'];
        yield 'String' => ['String', 'string'];
        yield 'FixedString' => ['FixedString(16)', 'string'];
        yield 'UUID' => ['UUID', 'string'];
        yield 'Enum8' => ["Enum8('a' = 1, 'b' = 2)", 'string'];
        yield 'Array' => ['Array(String)', 'string'];
        yield 'IPv4' => ['IPv4', 'string'];
        yield 'IPv6' => ['IPv6', 'string'];
    }

    #[DataProvider('typeProvider')]
    public function testTypeIsPublishedAsTheFieldTypeOfTheMap(string $clickHouseType, string $expected): void
    {
        $this->assertSame($expected, ClickHouseTypeMap::toFieldType($clickHouseType));
    }

    public function testNullableWrapperIsStrippedFromAParameterizedType(): void
    {
        $this->assertSame('double', ClickHouseTypeMap::toFieldType('Nullable(Decimal(38, 4))'));
    }

    public function testNestedWrappersAreStripped(): void
    {
        $this->assertSame('string', ClickHouseTypeMap::toFieldType('LowCardinality(Nullable(String))'));
    }

    /**
     * The payload of an aggregate wrapper is its last argument, not its first one.
     */
    public function testSimpleAggregateFunctionIsReadThroughItsPayloadType(): void
    {
        $this->assertSame('string', ClickHouseTypeMap::toFieldType('SimpleAggregateFunction(max, UInt64)'));
    }

    /**
     * The comma of the parameters of the payload does not separate arguments of the wrapper.
     */
    public function testSimpleAggregateFunctionKeepsAParameterizedPayloadTogether(): void
    {
        $this->assertSame('double', ClickHouseTypeMap::toFieldType('SimpleAggregateFunction(sum, Decimal(18, 4))'));
    }

    public function testWrappedParameterizedDateTimeIsPublishedAsADateTime(): void
    {
        $this->assertSame('datetime', ClickHouseTypeMap::toFieldType('Nullable(DateTime64(3))'));
    }

    public function testTypeNamesAreComparedWithoutRegardToCase(): void
    {
        $this->assertSame('string', ClickHouseTypeMap::toFieldType('nullable(uint64)'));
    }

    public function testUnknownTypeIsPublishedAsAStringInsteadOfFailing(): void
    {
        $this->assertSame('string', ClickHouseTypeMap::toFieldType('Point'));
    }
}
