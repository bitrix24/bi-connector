<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseDialect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClickHouseDialectTest extends TestCase
{
    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function identifierProvider(): array
    {
        return [
            'plain name' => ['deals', '`deals`'],
            'empty name' => ['', '``'],
            'name with a backquote' => ['de`als', '`de``als`'],
            'name that tries to close the quoting' => ['a` , `b', '`a`` , ``b`'],
            'name with a dot stays one name' => ['db.table', '`db.table`'],
            'name with a single quote' => ["o'brien", "`o'brien`"],
            'name with a backslash' => ['back\\slash', '`back\\\\slash`'],
            'name that tries to escape the closing quote' => ['deals\\', '`deals\\\\`'],
        ];
    }

    #[DataProvider('identifierProvider')]
    public function testQuoteIdentifierWrapsAndDoublesBackquotes(string $identifier, string $expected): void
    {
        self::assertSame($expected, ClickHouseDialect::quoteIdentifier($identifier));
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function stringProvider(): array
    {
        return [
            'single quote' => ["O'Brien", "'O\\'Brien'"],
            'backslash' => ['back\\slash', "'back\\\\slash'"],
            'line break and tab' => ["a\nb\tc", "'a\\nb\\tc'"],
            'carriage return' => ["a\r\nb", "'a\\r\\nb'"],
            'null byte' => ["a\0b", "'a\\0b'"],
            'empty string' => ['', "''"],
            'trailing backslash' => ['value\\', "'value\\\\'"],
            'attempt to close the literal' => ["' OR 1 = 1 --", "'\\' OR 1 = 1 --'"],
            'attempt to escape the escaping' => ["\\' OR 1 = 1 --", "'\\\\\\' OR 1 = 1 --'"],
            'unicode is untouched' => ['Grüße 東京', "'Grüße 東京'"],
        ];
    }

    #[DataProvider('stringProvider')]
    public function testQuoteStringEscapesTheContent(string $value, string $expected): void
    {
        self::assertSame($expected, ClickHouseDialect::quoteString($value));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function hostileStringProvider(): array
    {
        return [
            ["O'Brien"],
            ['back\\slash'],
            ['value\\'],
            ["' OR 1 = 1 --"],
            ["\\' OR 1 = 1 --"],
            ["'; DROP TABLE deals; --"],
            ["a\nb\tc\r\0"],
            ["\\\\'"],
            [''],
        ];
    }

    /**
     * The literal must stay one literal: the only unescaped single quotes are the ones this class added
     * around the value, and no backslash of the value can pair up with the closing quote.
     */
    #[DataProvider('hostileStringProvider')]
    public function testQuotedStringCannotBeClosedFromTheInside(string $value): void
    {
        $literal = ClickHouseDialect::quoteString($value);

        self::assertStringStartsWith("'", $literal);
        self::assertStringEndsWith("'", $literal);

        $body = substr($literal, 1, -1);

        // Walking the body the way the reader of the statement does: a backslash consumes the next
        // character, so whatever is left over must not be a quote and must not be a control character.
        $length = strlen($body);

        for ($index = 0; $index < $length; $index++) {
            if ($body[$index] === '\\') {
                self::assertLessThan($length, $index + 1, 'A literal must not end with a dangling escape.');
                $index++;

                continue;
            }

            self::assertNotSame("'", $body[$index], 'The literal carries an unescaped single quote.');
            self::assertNotContains(
                $body[$index],
                ["\n", "\r", "\t", "\0"],
                'The literal carries a raw control character.'
            );
        }
    }

    /**
     * @return list<array{0: int|float|string, 1: string}>
     */
    public static function numberProvider(): array
    {
        return [
            'zero' => [0, '0'],
            'negative integer' => [-42, '-42'],
            'float' => [0.5, '0.5'],
            'float that a plain cast would shorten' => [0.1 + 0.2, '0.30000000000000004'],
            'numeric text' => ['12345', '12345'],
            'numeric text with a sign' => ['-12.5', '-12.5'],
            'numeric text with an exponent' => ['1.5e-3', '1.5e-3'],
            'numeric text with spaces around it' => ['  17  ', '17'],
        ];
    }

    #[DataProvider('numberProvider')]
    public function testQuoteNumberWritesABareLiteral(int|float|string $value, string $expected): void
    {
        self::assertSame($expected, ClickHouseDialect::quoteNumber($value));
    }

    /**
     * @return list<array{0: string}>
     */
    public static function notANumberProvider(): array
    {
        return [
            ['1 OR 1 = 1'],
            ['0x1f'],
            [''],
            ['nan'],
            ['inf'],
            ['1,5'],
            ["1'"],
            ['1e'],
        ];
    }

    #[DataProvider('notANumberProvider')]
    public function testQuoteNumberRejectsAnythingButANumber(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClickHouseDialect::quoteNumber($value);
    }

    public function testQuoteNumberRejectsNonFiniteFloats(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClickHouseDialect::quoteNumber(INF);
    }

    public function testQuoteBooleanWritesKeywords(): void
    {
        self::assertSame('true', ClickHouseDialect::quoteBoolean(true));
        self::assertSame('false', ClickHouseDialect::quoteBoolean(false));
    }

    /**
     * @return list<array{0: mixed, 1: string}>
     */
    public static function valueProvider(): array
    {
        return [
            'null' => [null, 'NULL'],
            'false' => [false, 'false'],
            'true' => [true, 'true'],
            'zero' => [0, '0'],
            'integer' => [7, '7'],
            'float' => [1.25, '1.25'],
            'empty string' => ['', "''"],
            'numeric string stays a string' => ['12345', "'12345'"],
            'string with a quote' => ["O'Brien", "'O\\'Brien'"],
        ];
    }

    #[DataProvider('valueProvider')]
    public function testQuoteValueDispatchesByType(mixed $value, string $expected): void
    {
        self::assertSame($expected, ClickHouseDialect::quoteValue($value));
    }

    public function testQuoteValueRejectsAValueWithoutALiteralForm(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClickHouseDialect::quoteValue(['a', 'b']);
    }
}
