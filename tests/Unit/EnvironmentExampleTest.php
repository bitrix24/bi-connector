<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\DataSource\ClickHouse\ClickHouseRowStream;
use App\DataSource\RowLimit;
use App\Response\JsonRowsFileWriter;
use PHPUnit\Framework\TestCase;

/**
 * The shipped example is what an installation copies its settings from, so a boundary the application
 * derives from another one must stay out of it: a number written down there survives the change of the
 * boundary it was derived from and the pair silently drifts apart.
 */
class EnvironmentExampleTest extends TestCase
{
    private const DERIVED_VARIABLES = [
        'ROWS_FILE_MAX_ROWS',
        'CLICKHOUSE_MAX_RESPONSE_BYTES',
    ];

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    private string $directory;

    protected function setUp(): void
    {
        $this->environmentBackup = $_ENV;

        foreach (self::DERIVED_VARIABLES as $name) {
            unset($_ENV[$name]);
        }

        foreach (self::settingsOfTheExample() as $name => $value) {
            $_ENV[$name] = $value;
        }

        $this->directory = sys_get_temp_dir() . '/biconnector_example_test_' . uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->environmentBackup;

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testTheExampleLeavesTheDerivedBoundariesUnset(): void
    {
        $settings = self::settingsOfTheExample();

        foreach (self::DERIVED_VARIABLES as $name) {
            $this->assertArrayNotHasKey(
                $name,
                $settings,
                $name . ' is derived from MAX_RESULT_ROWS and must stay an optional override in the example.'
            );
        }
    }

    public function testTheRowLimitOfTheExampleIsTheDefaultOfTheApplication(): void
    {
        $settings = self::settingsOfTheExample();

        $this->assertArrayHasKey(RowLimit::ENVIRONMENT_VARIABLE, $settings);
        $this->assertSame(
            (new RowLimit(0))->getMaximum(),
            (int)$settings[RowLimit::ENVIRONMENT_VARIABLE],
            'The example and the default of the application name the same row bound.'
        );
    }

    public function testTheRowBoundaryOfAResponseFileFollowsTheExample(): void
    {
        $writer = new JsonRowsFileWriter($this->directory);

        $maxRows = new \ReflectionProperty($writer, 'maxRows');
        $maxRows->setAccessible(true);

        $this->assertSame(
            (int)self::settingsOfTheExample()[RowLimit::ENVIRONMENT_VARIABLE] + 1,
            $maxRows->getValue($writer)
        );
    }

    public function testTheByteBoundaryOfTheAnswerFollowsTheExample(): void
    {
        $settings = self::settingsOfTheExample();

        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '["id"]' . "\n" . '["UInt32"]' . "\n");
        rewind($stream);

        $rows = new ClickHouseRowStream($stream);

        $maxResponseBytes = new \ReflectionProperty($rows, 'maxResponseBytes');
        $maxResponseBytes->setAccessible(true);

        $this->assertSame(
            (int)$settings[RowLimit::ENVIRONMENT_VARIABLE] * (int)$settings['CLICKHOUSE_EXPECTED_ROW_BYTES'],
            $maxResponseBytes->getValue($rows)
        );
    }

    /**
     * Settings the example actually delivers: a commented out line is guidance and not a setting.
     *
     * @return array<string, string>
     */
    private static function settingsOfTheExample(): array
    {
        $lines = file(dirname(__DIR__, 2) . '/.env.example', FILE_IGNORE_NEW_LINES);
        self::assertIsArray($lines);

        $settings = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $settings[trim($name)] = trim($value, " \t\"'");
        }

        return $settings;
    }
}
