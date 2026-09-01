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
}
