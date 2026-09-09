<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource;

use App\DataSource\RowLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RowLimitTest extends TestCase
{
    private const DEFAULT_MAXIMUM = 500000;

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
        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = '-5';
        $this->assertSame(self::DEFAULT_MAXIMUM, RowLimit::fromEnvironment()->getMaximum());

        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = 'not a number';
        $this->assertSame(self::DEFAULT_MAXIMUM, RowLimit::fromEnvironment()->getMaximum());

        unset($_ENV[RowLimit::ENVIRONMENT_VARIABLE]);
        $this->assertSame(self::DEFAULT_MAXIMUM, RowLimit::fromEnvironment()->getMaximum());
    }

    public function testTheDefaultFitsTheMemoryLimitOfTheImage(): void
    {
        // The pair of the two is what makes the row bound a bound on memory: a driver that collects the
        // whole result needs the number of rows multiplied by the width of a row, measured at about
        // 360 bytes of memory for a row of 300 bytes against MySQL 8.4. The default is kept at a third of
        // the memory_limit of the image, so a row about three times wider than expected still fits.
        $memoryLimitOfTheImage = 512 * 1024 * 1024;
        $memoryPerRowOfExpectedWidth = 360;

        $this->assertLessThan(
            intdiv($memoryLimitOfTheImage, 2),
            self::DEFAULT_MAXIMUM * $memoryPerRowOfExpectedWidth,
            'Raising the default row bound calls for raising memory_limit in the Dockerfile as well.'
        );
    }
}
