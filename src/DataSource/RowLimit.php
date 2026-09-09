<?php

declare(strict_types=1);

namespace App\DataSource;

/**
 * Upper bound the application puts on the number of rows an answer may carry.
 *
 * The bound belongs to the application and not to one kind of source: a source that streams its answer and a
 * source whose driver collects the whole result in memory both need a predictable refusal instead of an
 * answer whose size the caller alone decides. A limit that is not positive means the bound of the
 * application and never the absence of one.
 *
 * The default forms a pair with the memory_limit of the image (512M, set in the Dockerfile). A driver that
 * collects the whole result needs the number of rows multiplied by the width of a row: measured against
 * MySQL 8.4, the default of 500000 rows costs about 176 MB at 300 bytes a row and reaches 512 MB only at
 * about 950 bytes a row. Raising this default without raising memory_limit brings back a fatal error in
 * place of a refusal.
 *
 * The pair is thus tuned for rows up to about 900 bytes wide. A source whose rows are wider -- a table
 * carrying a description, a comment, an address or a JSON column -- needs this bound lowered through
 * MAX_RESULT_ROWS, or the memory_limit of the image raised in the same proportion. The width
 * CLICKHOUSE_EXPECTED_ROW_BYTES declares is a different quantity: it bounds the length of an answer of
 * ClickHouse, a path that reads its answer line by line and holds no result in memory at all.
 */
final class RowLimit
{
    public const ENVIRONMENT_VARIABLE = 'MAX_RESULT_ROWS';

    private const DEFAULT_MAXIMUM = 500000;

    private int $maximum;

    public function __construct(int $maximum)
    {
        $this->maximum = $maximum > 0 ? $maximum : self::DEFAULT_MAXIMUM;
    }

    /**
     * A missing, unreadable or non positive setting falls back to the default of the application.
     */
    public static function fromEnvironment(): self
    {
        return new self((int)($_ENV[self::ENVIRONMENT_VARIABLE] ?? self::DEFAULT_MAXIMUM));
    }

    public function getMaximum(): int
    {
        return $this->maximum;
    }

    public function resolve(int $requestedLimit): int
    {
        if ($requestedLimit <= 0) {
            return $this->maximum;
        }

        return min($requestedLimit, $this->maximum);
    }
}
