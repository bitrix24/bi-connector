<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource;

use App\DataSource\RowLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RowLimitTest extends TestCase
{
    // Memory a row of the expected width of 300 bytes takes on the MySQL path, measured against MySQL 8.4.
    private const MEMORY_PER_ROW_OF_EXPECTED_WIDTH = 360;

    private mixed $environmentBackup = null;

    protected function setUp(): void
    {
        $this->environmentBackup = $_ENV[RowLimit::ENVIRONMENT_VARIABLE] ?? null;
        unset($_ENV[RowLimit::ENVIRONMENT_VARIABLE]);
    }

    protected function tearDown(): void
    {
        if ($this->environmentBackup === null) {
            unset($_ENV[RowLimit::ENVIRONMENT_VARIABLE]);

            return;
        }

        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = $this->environmentBackup;
    }

    /**
     * @return list<array{0: int, 1: int}>
     */
    public static function requestedLimitProvider(): array
    {
        return [
            'a limit below the bound is kept' => [700, 700],
            'a limit at the bound is kept' => [1000, 1000],
            'a limit above the bound is lowered' => [5000, 1000],
            'zero means the bound' => [0, 1000],
            'a negative limit means the bound' => [-1, 1000],
        ];
    }

    #[DataProvider('requestedLimitProvider')]
    public function testANonPositiveLimitMeansTheBoundAndNeverTheAbsenceOfOne(
        int $requestedLimit,
        int $expected
    ): void {
        $this->assertSame($expected, (new RowLimit(1000))->resolve($requestedLimit));
    }

    public function testTheBoundIsReadFromTheEnvironment(): void
    {
        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = '250';

        $this->assertSame(250, RowLimit::fromEnvironment()->getMaximum());
    }

    public function testAnUnreadableOrNonPositiveSettingFallsBackToTheDefault(): void
    {
        $default = self::defaultMaximum();

        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = '-5';
        $this->assertSame($default, RowLimit::fromEnvironment()->getMaximum());

        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = 'not a number';
        $this->assertSame($default, RowLimit::fromEnvironment()->getMaximum());

        unset($_ENV[RowLimit::ENVIRONMENT_VARIABLE]);
        $this->assertSame($default, RowLimit::fromEnvironment()->getMaximum());
    }

    public function testTheDefaultFitsTheMemoryLimitOfTheImage(): void
    {
        // The pair of the two is what makes the row bound a bound on memory: a driver that collects the
        // whole result needs the number of rows multiplied by the width of a row. The default is kept at
        // a third of the memory_limit of the image, so a row about three times wider than expected still
        // fits. Both numbers are read from the places that hold them -- the default through the class, the
        // memory_limit out of the Dockerfile -- so the guard works in both directions: raising the one or
        // lowering the other fails here.
        $this->assertLessThan(
            intdiv($this->readMemoryLimitOfTheImage(), 2),
            self::defaultMaximum() * self::MEMORY_PER_ROW_OF_EXPECTED_WIDTH,
            'The default row bound and the memory_limit of the Dockerfile have to be changed together.'
        );
    }

    /**
     * The default of the application, taken through the public interface of the class: a limit that is not
     * positive means the bound of the application.
     */
    private static function defaultMaximum(): int
    {
        return (new RowLimit(0))->getMaximum();
    }

    /**
     * The memory_limit the image sets, in bytes, read out of the Dockerfile itself.
     */
    private function readMemoryLimitOfTheImage(): int
    {
        $path = dirname(__DIR__, 3) . '/Dockerfile';
        $contents = is_readable($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            $this->fail('The Dockerfile of the image is not readable at ' . $path . '.');
        }

        if (preg_match('/^\s*RUN\s+echo\s+\'memory_limit\s*=\s*(\d+)([KMG]?)\'/mi', $contents, $match) !== 1) {
            $this->fail('The Dockerfile sets no memory_limit, so the row bound is no bound on memory.');
        }

        $multiplier = match (strtoupper($match[2])) {
            'K' => 1024,
            'M' => 1024 * 1024,
            'G' => 1024 * 1024 * 1024,
            default => 1,
        };

        return (int)$match[1] * $multiplier;
    }
}
