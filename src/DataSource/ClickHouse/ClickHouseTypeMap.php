<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

/**
 * Maps a ClickHouse column type onto a BI Constructor field type.
 *
 * The map repeats the one the native source of the portal uses, so the same table is described the same way
 * no matter how it is connected. The type name arrives either from `system.columns` or from the type line of
 * the JSONCompactEachRowWithNamesAndTypes answer, so it may be wrapped into modifiers and carry parameters.
 */
final class ClickHouseTypeMap
{
    public const TYPE_DATE = 'date';
    public const TYPE_DATETIME = 'datetime';
    public const TYPE_DOUBLE = 'double';
    public const TYPE_INT = 'int';
    public const TYPE_STRING = 'string';

    /**
     * Modifiers a type may be wrapped into; they say how a value is stored, not what it is.
     */
    private const WRAPPERS = ['Nullable', 'LowCardinality', 'SimpleAggregateFunction'];

    /**
     * An unknown type is published as a string instead of failing the description of the whole table.
     */
    public static function toFieldType(string $clickHouseType): string
    {
        $baseType = self::getBaseTypeName(self::unwrapType($clickHouseType));

        if (str_starts_with($baseType, 'decimal')) {
            // Decimal32/64/128/256 belong to the same family and are published as a double as well. The
            // value itself never passes through a float: the answer carries it as exact decimal text and
            // the row stream hands it over unchanged.
            return self::TYPE_DOUBLE;
        }

        return match ($baseType) {
            'date', 'date32' => self::TYPE_DATE,
            'datetime', 'datetime64' => self::TYPE_DATETIME,
            'float32', 'float64' => self::TYPE_DOUBLE,
            'int8', 'int16', 'int32', 'int64', 'uint8', 'uint16', 'uint32' => self::TYPE_INT,
            // The upper half of UInt64 does not fit a PHP int, and a cast would round such a value away.
            // Bool is a string for the same reason of exactness: the wire carries 'true' and 'false'.
            'uint64', 'bool', 'boolean' => self::TYPE_STRING,
            default => self::TYPE_STRING,
        };
    }

    /**
     * Strips the modifiers a type is wrapped into, down to the type that carries the value.
     */
    private static function unwrapType(string $type): string
    {
        $type = trim($type);
        $changed = true;

        while ($changed) {
            $changed = false;

            foreach (self::WRAPPERS as $wrapper) {
                $prefix = $wrapper . '(';

                if (stripos($type, $prefix) !== 0 || !str_ends_with($type, ')')) {
                    continue;
                }

                $inner = substr($type, strlen($prefix), -1);

                if (strcasecmp($wrapper, 'SimpleAggregateFunction') === 0) {
                    // SimpleAggregateFunction(max, UInt64): the payload type is the last argument.
                    $inner = self::getLastTopLevelArgument($inner);
                }

                $type = trim($inner);
                $changed = true;
            }
        }

        return $type;
    }

    /**
     * The argument after the last comma of the top level.
     *
     * A plain split would cut inside a parameterized payload: for SimpleAggregateFunction(sum,
     * Decimal(18, 4)) the payload is Decimal(18, 4) and the comma of its own parameters separates nothing.
     */
    private static function getLastTopLevelArgument(string $arguments): string
    {
        $depth = 0;
        $lastComma = -1;

        for ($index = 0, $length = strlen($arguments); $index < $length; $index++) {
            $character = $arguments[$index];

            if ($character === '(') {
                $depth++;
            } elseif ($character === ')') {
                $depth--;
            } elseif ($character === ',' && $depth === 0) {
                $lastComma = $index;
            }
        }

        return trim($lastComma < 0 ? $arguments : substr($arguments, $lastComma + 1));
    }

    /**
     * Drops the parameters of a type, keeping the family only: Decimal(38, 4) and DateTime64(3, 'UTC')
     * are parameterized forms of Decimal and DateTime64.
     */
    private static function getBaseTypeName(string $type): string
    {
        $parenthesis = strpos($type, '(');

        if ($parenthesis !== false) {
            $type = substr($type, 0, $parenthesis);
        }

        return strtolower(trim($type));
    }
}
