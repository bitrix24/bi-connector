<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource;

use App\DataSource\RowLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RowLimitTest extends TestCase
{
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
        $this->assertSame(1000000, RowLimit::fromEnvironment()->getMaximum());

        $_ENV[RowLimit::ENVIRONMENT_VARIABLE] = 'not a number';
        $this->assertSame(1000000, RowLimit::fromEnvironment()->getMaximum());

        unset($_ENV[RowLimit::ENVIRONMENT_VARIABLE]);
        $this->assertSame(1000000, RowLimit::fromEnvironment()->getMaximum());
    }
}
