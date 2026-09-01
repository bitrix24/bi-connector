<?php

declare(strict_types=1);

namespace App\Tests\Unit\Response;

use App\Response\JsonRowsFileWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class JsonRowsFileWriterTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/biconnector_rows_test_' . uniqid();
        mkdir($this->directory, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
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

        $this->assertSame([], glob($this->directory . '/*'));
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

    private function writtenBytes(): int
    {
        $files = glob($this->directory . '/*') ?: [];

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
