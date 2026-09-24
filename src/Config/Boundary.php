<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Reading of a boundary the application takes from its environment.
 *
 * Every boundary of the application is a positive whole number, and a boundary is never left unbounded: a
 * missing, unreadable or non positive setting falls back to the default of the application. The reading
 * lives in one place so that a change of it -- another source of settings, another way of reporting an
 * unreadable value -- reaches every boundary at once.
 */
final class Boundary
{
    public static function readPositiveInt(string $name, int $default): int
    {
        $value = (int)($_ENV[$name] ?? $default);

        return $value > 0 ? $value : $default;
    }
}
