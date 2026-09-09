<?php

declare(strict_types=1);

namespace App\Tests\Unit\Response;

use App\Response\JsonRowsFileWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonRowsFileWriterTest extends TestCase
{
    private const ENVIRONMENT_VARIABLES = [
        'ROWS_FILE_MAX_AGE_SECONDS',
        'ROWS_FILE_SWEEP_INTERVAL_SECONDS',
        'ROWS_FILE_MAX_ROWS',
        'ROWS_FILE_MAX_BYTES',
        'MAX_RESULT_ROWS',
    ];

    private string $directory;

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/biconnector_rows_test_' . uniqid();
        mkdir($this->directory, 0777, true);

        foreach (self::ENVIRONMENT_VARIABLES as $name) {
            $this->environmentBackup[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentBackup as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);

                continue;
            }

            $_ENV[$name] = $value;
        }

        $this->environmentBackup = [];

        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAFileLeftBehindByAStoppedRequestIsSweptAway(): void
    {
        $stale = $this->directory . '/rows_stale';
        $fresh = $this->directory . '/rows_fresh';
        $foreign = $this->directory . '/other_stale';

        foreach ([$stale, $fresh, $foreign] as $path) {
            file_put_contents($path, '[]');
        }

        touch($stale, time() - 7200);
        touch($foreign, time() - 7200);

        $_ENV['ROWS_FILE_SWEEP_INTERVAL_SECONDS'] = '0';

        $writer = new JsonRowsFileWriter($this->directory);
        $path = $writer->write($this->yieldRows([]));

        $this->assertFileDoesNotExist($stale, 'A file older than the age of a request must be removed.');
        $this->assertFileExists($fresh, 'A file of a request that may still be running must be kept.');
        $this->assertFileExists($foreign, 'Only the files of this writer are swept away.');
        $this->assertFileExists($path);

        unlink($path);
    }

    public function testTheSweepIsSwitchedOffByANonPositiveAge(): void
    {
        $stale = $this->directory . '/rows_stale';
        file_put_contents($stale, '[]');
        touch($stale, time() - 7200);

        $_ENV['ROWS_FILE_MAX_AGE_SECONDS'] = '0';
        $_ENV['ROWS_FILE_SWEEP_INTERVAL_SECONDS'] = '0';

        $writer = new JsonRowsFileWriter($this->directory);
        unlink($writer->write($this->yieldRows([])));

        $this->assertFileExists($stale);
    }

    public function testTheSweepDoesNotRunAgainInsideTheInterval(): void
    {
        // Walking the directory costs as much as the number of files it holds, so the sweep is kept off
        // the beginning of every request.
        $stale = $this->directory . '/rows_stale';
        file_put_contents($stale, '[]');
        touch($stale, time() - 7200);
        touch($this->directory . '/sweep.marker');

        $_ENV['ROWS_FILE_SWEEP_INTERVAL_SECONDS'] = '300';

        $writer = new JsonRowsFileWriter($this->directory);
        unlink($writer->write($this->yieldRows([])));

        $this->assertFileExists($stale);
    }

    public function testTheSweepRunsAgainOnceTheIntervalHasPassed(): void
    {
        // The interval is counted from the moment of the last sweep, so a rare stream of requests does
        // not keep a file left behind for longer than the age at which it is removed.
        $stale = $this->directory . '/rows_stale';
        file_put_contents($stale, '[]');
        touch($stale, time() - 7200);

        $marker = $this->directory . '/sweep.marker';
        touch($marker, time() - 600);

        $_ENV['ROWS_FILE_SWEEP_INTERVAL_SECONDS'] = '300';

        $writer = new JsonRowsFileWriter($this->directory);
        unlink($writer->write($this->yieldRows([])));

        clearstatcache();

        $this->assertFileDoesNotExist($stale);
        $this->assertGreaterThan(
            time() - 300,
            filemtime($marker),
            'A sweep that ran records the moment it did.'
        );
    }

    public function testBodyMatchesJsonEncodeOfTheSameRows(): void
    {
        $rows = [
            ['ID', 'NAME', 'AMOUNT'],
            [1, 'First', 10.5],
            [2, null, 0],
        ];

        $writer = new JsonRowsFileWriter($this->directory);
        $path = $writer->write($this->yieldRows($rows));

        $this->assertSame(json_encode($rows), file_get_contents($path));
    }

    public function testEmptyResultGivesEmptyJsonArray(): void
    {
        $writer = new JsonRowsFileWriter($this->directory);
        $path = $writer->write($this->yieldRows([]));

        $this->assertSame('[]', file_get_contents($path));
    }

    public function testRowCountExcludesTheHeaderRow(): void
    {
        $rows = [
            ['ID', 'NAME'],
            [1, 'First'],
            [2, 'Second'],
        ];

        $writer = new JsonRowsFileWriter($this->directory);
        $writer->write($this->yieldRows($rows));

        $this->assertSame(count($rows) - 1, $writer->getDataRowCount());
    }

    public function testRowCountOfAnEmptyResultKeepsThePreviousValue(): void
    {
        $writer = new JsonRowsFileWriter($this->directory);
        $writer->write($this->yieldRows([]));

        $this->assertSame(-1, $writer->getDataRowCount());
    }

    /**
     * @param list<list<scalar|null>> $rows
     */
    #[DataProvider('escapingProvider')]
    public function testValuesAreEncodedWithTheSameFlagsAsBefore(array $rows): void
    {
        $writer = new JsonRowsFileWriter($this->directory);
        $path = $writer->write($this->yieldRows($rows));

        $this->assertSame(json_encode($rows), file_get_contents($path));
    }

    /**
     * @return array<string, array{list<list<scalar|null>>}>
     */
    public static function escapingProvider(): array
    {
        return [
            'double quotes' => [[['NAME'], ['He said "hello"']]],
            'backslashes' => [[['PATH'], ['C:\\temp\\file']]],
            'non ascii' => [[['NAME'], ['Компания «Пример»']]],
            'slashes and control characters' => [[['TEXT'], ["a/b\nc\td"]]],
            'mixed scalars' => [[['A', 'B', 'C', 'D'], [true, false, null, 1.0]]],
        ];
    }

    public function testFailingIteratorLeavesNoFileBehind(): void
    {
        $writer = new JsonRowsFileWriter($this->directory);

        try {
            $writer->write($this->yieldRowsThenFail([
                ['ID', 'NAME'],
                [1, 'First'],
            ]));

            $this->fail('The exception of the iterator must not be swallowed');
        } catch (\RuntimeException $e) {
            $this->assertSame('Data source failure', $e->getMessage());
        }

        $this->assertSame([], $this->responseFiles());
    }

    public function testRowsAreWrittenWhileTheIteratorIsStillRunning(): void
    {
        $sizes = [];

        $rows = function () use (&$sizes): \Generator {
            foreach ([['ID'], [1], [2], [3]] as $row) {
                $sizes[] = $this->writtenBytes();

                yield $row;
            }
        };

        $writer = new JsonRowsFileWriter($this->directory);
        $path = $writer->write($rows());

        // Every row is on disk before the next one is pulled: nothing but the current row is held
        $this->assertSame([1, 7, 11, 15], $sizes);
        $this->assertSame(json_encode([['ID'], [1], [2], [3]]), file_get_contents($path));
    }

    public function testMoreRowsThanTheBoundaryFailTheWriting(): void
    {
        $_ENV['ROWS_FILE_MAX_ROWS'] = '3';

        $writer = new JsonRowsFileWriter($this->directory);

        try {
            $writer->write($this->yieldRows([['ID'], [1], [2], [3]]));

            $this->fail('Crossing the row boundary must fail the writing.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The answer carries more than the 3 rows the application writes.', $e->getMessage());
        }

        $this->assertSame([], $this->responseFiles(), 'The partly written file must be dropped.');
    }

    public function testTheRowBoundaryFollowsTheRowLimitOfTheApplication(): void
    {
        // The file holds the rows the source is allowed to hand over and the row of column names above them
        $_ENV['MAX_RESULT_ROWS'] = '2';

        $writer = new JsonRowsFileWriter($this->directory);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more than the 3 rows');

        $writer->write($this->yieldRows([['ID'], [1], [2], [3]]));
    }

    public function testMoreBytesThanTheBoundaryFailTheWriting(): void
    {
        $_ENV['ROWS_FILE_MAX_BYTES'] = '16';

        $writer = new JsonRowsFileWriter($this->directory);

        try {
            $writer->write($this->yieldRows([['ID'], ['x'], ['y'], ['z']]));

            $this->fail('Crossing the size boundary must fail the writing.');
        } catch (\RuntimeException $e) {
            $this->assertSame('The answer is longer than the 16 bytes the application writes.', $e->getMessage());
        }

        $this->assertSame([], $this->responseFiles(), 'The partly written file must be dropped.');
    }

    public function testTheBoundariesOfTheFileAreTakenFromTheConstructorFirst(): void
    {
        $_ENV['ROWS_FILE_MAX_ROWS'] = '1000';

        $writer = new JsonRowsFileWriter($this->directory, 2, 1024);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('more than the 2 rows');

        $writer->write($this->yieldRows([['ID'], [1], [2]]));
    }

    /**
     * @return list<string>
     */
    private function responseFiles(): array
    {
        return array_values(glob($this->directory . '/rows_*') ?: []);
    }

    private function writtenBytes(): int
    {
        $files = $this->responseFiles();

        $this->assertCount(1, $files, 'The writer must work with exactly one temporary file');

        clearstatcache(true, $files[0]);

        return (int)filesize($files[0]);
    }

    /**
     * @param list<list<scalar|null>> $rows
     *
     * @return \Generator<int, list<scalar|null>>
     */
    private function yieldRows(array $rows): \Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }
    }

    /**
     * @param list<list<scalar|null>> $rows
     *
     * @return \Generator<int, list<scalar|null>>
     */
    private function yieldRowsThenFail(array $rows): \Generator
    {
        foreach ($rows as $row) {
            yield $row;
        }

        throw new \RuntimeException('Data source failure');
    }
}
