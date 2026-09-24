<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Single source of truth for the accepted `connection_type` request values.
 */
enum ConnectionType: string
{
    case Mysql = 'mysql';
    case Postgresql = 'postgresql';
    case Clickhouse = 'clickhouse';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn(self $type): string => $type->value, self::cases());
    }

    /**
     * Source family the portal files a connector under, sent as the `sourceCode` registration field.
     *
     * The map is spelled out case by case because the two vocabularies only look alike: the portal calls
     * PostgreSQL `pgsql`, so deriving one string from the other would register the connector under a
     * family the portal does not know.
     */
    public function sourceCode(): string
    {
        return match ($this) {
            self::Mysql => 'mysql',
            self::Postgresql => 'pgsql',
            self::Clickhouse => 'clickhouse',
        };
    }
}
