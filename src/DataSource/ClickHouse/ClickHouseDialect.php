<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

/**
 * The single place where a value or a name becomes a piece of ClickHouse SQL.
 *
 * The HTTP interface carries the statement as plain text and the portal does not always tell the type of a
 * predicate, so typed parameters of the interface are of no use here: every value has to reach the statement
 * already escaped. No other part of the ClickHouse implementation may assemble SQL out of raw strings.
 */
final class ClickHouseDialect
{
    /**
     * A numeric literal is written without quotes, so its text is accepted only when it is a number and
     * nothing else. The pattern is the whitelist: an optional sign, digits with an optional fractional part
     * and an optional decimal exponent.
     */
    private const NUMBER_PATTERN = '/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/';

    /**
     * Wraps a name into backquotes, doubling the ones already inside it.
     *
     * The name is never split on a dot: a dot belongs to the name itself, and a qualified name is built by
     * quoting every part separately.
     */
    public static function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    /**
     * Wraps a value into single quotes and escapes its content.
     */
    public static function quoteString(string $value): string
    {
        return "'" . self::escapeString($value) . "'";
    }

    /**
     * Escapes the content of a single-quoted literal, without the quotes themselves.
     *
     * ClickHouse reads a string literal with the C-style rules, so a backslash and a single quote are what
     * ends the literal or changes the meaning of the next character. The control characters are escaped as
     * well: a raw line break inside a literal splits the statement for a reader and for a log alike.
     *
     * The order of the replacements matters. The backslash goes first, because the replacements that follow
     * introduce backslashes of their own and a later pass over them would double these.
     */
    public static function escapeString(string $value): string
    {
        return str_replace(
            ['\\', "'", "\n", "\r", "\t", "\0"],
            ['\\\\', "\\'", '\\n', '\\r', '\\t', '\\0'],
            $value
        );
    }

    /**
     * Writes a number as a bare literal.
     *
     * @param int|float|string $value a number, or its text as the portal sent it
     *
     * @throws \InvalidArgumentException when the value is not a finite number
     */
    public static function quoteNumber(int|float|string $value): string
    {
        if (is_int($value)) {
            return (string)$value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new \InvalidArgumentException('A non finite number cannot be written as a literal.');
            }

            // A plain cast to string prints the value with the `precision` setting and loses digits;
            // var_export() uses `serialize_precision` and keeps the value readable back.
            return var_export($value, true);
        }

        $text = trim($value);

        if (preg_match(self::NUMBER_PATTERN, $text) !== 1) {
            throw new \InvalidArgumentException('The value is not a number and cannot be written unquoted.');
        }

        return $text;
    }

    public static function quoteBoolean(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    /**
     * Writes any value the portal may send as a literal.
     *
     * A string stays a string: it is quoted and escaped even when it looks like a number, because the caller
     * that wants a bare number asks for one with quoteNumber().
     *
     * @throws \InvalidArgumentException when the value has no literal form
     */
    public static function quoteValue(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return self::quoteBoolean($value);
        }

        if (is_int($value) || is_float($value)) {
            return self::quoteNumber($value);
        }

        if (is_string($value)) {
            return self::quoteString($value);
        }

        throw new \InvalidArgumentException(sprintf(
            'A value of type %s has no ClickHouse literal form.',
            get_debug_type($value)
        ));
    }
}
