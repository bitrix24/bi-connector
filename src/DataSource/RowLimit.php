<?php

declare(strict_types=1);

namespace App\DataSource;

use App\Config\Boundary;

/**
 * Upper bound the application puts on the number of rows an answer may carry.
 *
 * The bound belongs to the application and not to one kind of source: a source that streams its answer and a
 * source whose driver collects the whole result in memory both need a predictable refusal instead of an
 * answer whose size the caller alone decides. A limit that is not positive means the bound of the
 * application and never the absence of one; a limit above the bound is refused, because an answer
 * shortened to the bound reaches the caller as a complete one and its data is incomplete without a sign
 * of it.
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
        return new self(Boundary::readPositiveInt(self::ENVIRONMENT_VARIABLE, self::DEFAULT_MAXIMUM));
    }

    public function getMaximum(): int
    {
        return $this->maximum;
    }

    /**
     * The row limit a statement runs with.
     *
     * A limit that is not positive means the bound of the application: the caller named none and gets the
     * one the application holds. A limit above the bound is refused instead of being lowered: an answer
     * shortened to the bound carries no sign of being incomplete, so the caller would take a part of the
     * data for all of it. The refusal names both numbers, because the one who reads it decides between
     * asking for fewer rows and raising the bound of the installation.
     *
     * @throws \InvalidArgumentException when the request asks for more rows than the application returns
     */
    public function resolve(int $requestedLimit): int
    {
        if ($requestedLimit <= 0) {
            return $this->maximum;
        }

        if ($requestedLimit > $this->maximum) {
            throw new \InvalidArgumentException(sprintf(
                'The request asks for %d rows, which is above the %d rows the application returns. '
                . 'Lower the row limit of the request, or raise %s in the settings of the application.',
                $requestedLimit,
                $this->maximum,
                self::ENVIRONMENT_VARIABLE
            ));
        }

        return $requestedLimit;
    }
}
