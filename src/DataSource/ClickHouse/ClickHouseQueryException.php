<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

/**
 * A statement that the source refused to finish.
 *
 * The failure is told apart from a transport failure on purpose: the answer already carried a successful
 * status and a header, and the reason arrived inside the body afterwards.
 */
final class ClickHouseQueryException extends \RuntimeException
{
}
