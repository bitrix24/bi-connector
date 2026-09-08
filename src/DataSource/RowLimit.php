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
 */
final class RowLimit
{
    public const ENVIRONMENT_VARIABLE = 'MAX_RESULT_ROWS';

    private const DEFAULT_MAXIMUM = 1000000;

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
