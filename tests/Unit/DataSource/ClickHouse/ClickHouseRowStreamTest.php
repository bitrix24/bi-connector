<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseQueryException;
use App\DataSource\ClickHouse\ClickHouseRowStream;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClickHouseRowStreamTest extends TestCase
{
    private const BOUNDARY_VARIABLES = [
        'CLICKHOUSE_MAX_LINE_BYTES',
        'CLICKHOUSE_MAX_RESPONSE_BYTES',
        'CLICKHOUSE_EXPECTED_ROW_BYTES',
        'MAX_RESULT_ROWS',
    ];

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    protected function setUp(): void
    {
        // The boundaries are read from the environment, so every test starts from the defaults of the
        // application and not from whatever the shell of the developer carries.
        foreach (self::BOUNDARY_VARIABLES as $name) {
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
    }

    /**
     * @return resource
     */
    private static function streamOf(string $body)
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, $body);
        rewind($stream);

        return $stream;
    }

    public function testReadsHeaderAndDataRows(): void
    {
        $stream = self::streamOf(
            '["id","title","amount"]' . "\n"
            . '["UInt32","String","Decimal(18, 2)"]' . "\n"
            . '[1,"first","10.50"]' . "\n"
            . '[2,"second","20.00"]' . "\n"
            . '[3,"third","30.25"]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame(['id', 'title', 'amount'], $rows->getColumnNames());
        self::assertSame(['UInt32', 'String', 'Decimal(18, 2)'], $rows->getColumnTypes());

        self::assertSame([1, 'first', '10.50'], $rows->fetchRow());
        self::assertSame([2, 'second', '20.00'], $rows->fetchRow());
        self::assertSame([3, 'third', '30.25'], $rows->fetchRow());
        self::assertNull($rows->fetchRow());

        self::assertFalse(is_resource($stream), 'The stream must be released once the data is exhausted.');
    }

    public function testEmptyLinesAreSkipped(): void
    {
        $stream = self::streamOf(
            "\n"
            . '["id"]' . "\n"
            . "\n"
            . '["UInt32"]' . "\n"
            . "\n"
            . '[1]' . "\n"
            . "   \n"
            . '[2]' . "\n"
            . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame(['id'], $rows->getColumnNames());
        self::assertSame([1], $rows->fetchRow());
        self::assertSame([2], $rows->fetchRow());
        self::assertNull($rows->fetchRow());
    }

    public function testAFailureAppendedAfterTheHeaderStopsTheReading(): void
    {
        $message = 'Code: 241. DB::Exception: Memory limit exceeded';

        $stream = self::streamOf(
            '["id","title"]' . "\n"
            . '["UInt32","String"]' . "\n"
            . '[1,"first"]' . "\n"
            . '[2,"second"]' . "\n"
            . $message . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1, 'first'], $rows->fetchRow());
        self::assertSame([2, 'second'], $rows->fetchRow());

        try {
            $rows->fetchRow();
            self::fail('An unreadable line must not be taken for the end of the data.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream), 'The stream must be released when the statement fails.');
        self::assertNull($rows->fetchRow(), 'A failed stream must not hand out rows afterwards.');
    }

    /**
     * The shape the server actually uses in this answer format: the failure is appended as a readable
     * JSON list carrying the message alone.
     *
     * @return array<string, array{string}>
     */
    public static function appendedFailures(): array
    {
        return [
            'result overflow' => [
                'Code: 396. DB::Exception: Limit for result exceeded, max rows: 100.00 thousand, current'
                . ' rows: 130.82 thousand. (TOO_MANY_ROWS_OR_BYTES) (version 24.8.14.39 (official build))',
            ],
            'memory limit' => [
                'Code: 241. DB::Exception: Memory limit (for query) exceeded: would use 95.42 MiB.'
                . ' (MEMORY_LIMIT_EXCEEDED) (version 24.8.14.39 (official build))',
            ],
            'statement stopped mid stream' => [
                'Code: 395. DB::Exception: late failure. (FUNCTION_THROW_IF_VALUE_IS_NON_ZERO)'
                . ' (version 24.8.14.39 (official build))',
            ],
            'subclass of the exception' => [
                'Code: 210. DB::NetException: Connection reset by peer. (NETWORK_ERROR)',
            ],
        ];
    }

    #[DataProvider('appendedFailures')]
    public function testAFailureAppendedAsAJsonListStopsTheReading(string $message): void
    {
        $stream = self::streamOf(
            '["id","title"]' . "\n"
            . '["UInt32","String"]' . "\n"
            . '[1,"first"]' . "\n"
            . json_encode([$message]) . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1, 'first'], $rows->fetchRow());

        try {
            $rows->fetchRow();
            self::fail('A failure appended to the body must not be taken for a data row.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream), 'The stream must be released when the statement fails.');
        self::assertNull($rows->fetchRow(), 'A failed stream must not hand out rows afterwards.');
    }

    public function testAFailureAppendedToASingleColumnAnswerStopsTheReading(): void
    {
        // A one column answer is the case where the failure line has the width of a data row, so only
        // the message itself tells the two apart.
        $message = 'Code: 396. DB::Exception: Limit for result exceeded, max rows: 100.00 thousand.'
            . ' (TOO_MANY_ROWS_OR_BYTES) (version 24.8.14.39 (official build))';

        $stream = self::streamOf(
            '["number"]' . "\n"
            . '["UInt64"]' . "\n"
            . '[1]' . "\n"
            . json_encode([$message]) . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1], $rows->fetchRow());

        try {
            $rows->fetchRow();
            self::fail('A failure appended to a single column answer must not be taken for a data row.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream));
    }

    public function testAFailureAsAJsonListInsteadOfTheHeaderIsReported(): void
    {
        $message = 'Code: 159. DB::Exception: Timeout exceeded: elapsed 1.02 seconds.'
            . ' (TIMEOUT_EXCEEDED) (version 24.8.14.39 (official build))';

        $stream = self::streamOf(json_encode([$message]) . "\n");

        try {
            new ClickHouseRowStream($stream);
            self::fail('A failure in place of the header must not produce a readable stream.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream));
    }

    public function testAFailureAsAJsonListInsteadOfTheColumnTypesIsReported(): void
    {
        $message = 'Code: 241. DB::Exception: Memory limit (for query) exceeded.'
            . ' (MEMORY_LIMIT_EXCEEDED) (version 24.8.14.39 (official build))';

        $stream = self::streamOf('["id","title"]' . "\n" . json_encode([$message]) . "\n");

        try {
            new ClickHouseRowStream($stream);
            self::fail('A failure in place of the column types must not produce a readable stream.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function textsThatOnlyResembleAFailure(): array
    {
        return [
            'no exception class' => ['Code: 241. Memory limit exceeded'],
            'no numeric code' => ['Code: none. DB::Exception: not a failure of the source'],
            'the text starts later' => ['see Code: 241. DB::Exception: quoted in a message'],
            'another product' => ['Code: 1064. MySQL error text stored as data'],
        ];
    }

    #[DataProvider('textsThatOnlyResembleAFailure')]
    public function testATextThatOnlyResemblesAFailureStaysData(string $value): void
    {
        $stream = self::streamOf(
            '["message"]' . "\n"
            . '["String"]' . "\n"
            . json_encode([$value]) . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([$value], $rows->fetchRow());
        self::assertNull($rows->fetchRow());
    }

    public function testAFailureTextInsideAWiderRowStaysData(): void
    {
        // The value sits in a row that carries a value per column, so it is data the source stores and
        // not a failure the server appended.
        $value = 'Code: 241. DB::Exception: Memory limit exceeded. (MEMORY_LIMIT_EXCEEDED)';

        $stream = self::streamOf(
            '["id","message"]' . "\n"
            . '["UInt32","String"]' . "\n"
            . json_encode([7, $value]) . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([7, $value], $rows->fetchRow());
        self::assertNull($rows->fetchRow());
    }

    public function testAnswerWithoutDataRows(): void
    {
        $stream = self::streamOf('["id","title"]' . "\n" . '["UInt32","String"]' . "\n");

        $rows = new ClickHouseRowStream($stream);

        self::assertSame(['id', 'title'], $rows->getColumnNames());
        self::assertSame(['UInt32', 'String'], $rows->getColumnTypes());
        self::assertNull($rows->fetchRow());
        self::assertFalse(is_resource($stream));
    }

    public function testAShortRowIsFilledUpWithNulls(): void
    {
        $stream = self::streamOf(
            '["id","title","amount"]' . "\n"
            . '["UInt32","String","Decimal(18, 2)"]' . "\n"
            . '[1,"first"]' . "\n"
            . '[]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1, 'first', null], $rows->fetchRow());
        self::assertSame([null, null, null], $rows->fetchRow());
    }

    public function testARowWiderThanTheHeaderIsCutToTheColumns(): void
    {
        $stream = self::streamOf(
            '["id"]' . "\n"
            . '["UInt32"]' . "\n"
            . '[1,"unexpected"]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1], $rows->fetchRow());
    }

    public function testBooleanValuesAreReadAsText(): void
    {
        $stream = self::streamOf(
            '["id","active"]' . "\n"
            . '["UInt32","Bool"]' . "\n"
            . '[1,true]' . "\n"
            . '[2,false]' . "\n"
            . '[3,null]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1, 'true'], $rows->fetchRow());
        self::assertSame([2, 'false'], $rows->fetchRow());
        self::assertSame([3, null], $rows->fetchRow());
    }

    public function testBigIntegersKeepTheirDigits(): void
    {
        $stream = self::streamOf(
            '["quoted","unquoted","fits"]' . "\n"
            . '["UInt64","UInt64","UInt32"]' . "\n"
            . '["18446744073709551615",18446744073709551615,42]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame(['18446744073709551615', '18446744073709551615', 42], $rows->fetchRow());
    }

    public function testAFailureInsteadOfTheHeaderIsReported(): void
    {
        $message = 'Code: 60. DB::Exception: Table default.missing does not exist';
        $stream = self::streamOf($message . "\n");

        try {
            new ClickHouseRowStream($stream);
            self::fail('A body without a header must not produce a readable stream.');
        } catch (ClickHouseQueryException $exception) {
            self::assertSame($message, $exception->getMessage());
        }

        self::assertFalse(is_resource($stream));
    }

    public function testAnEmptyBodyIsReported(): void
    {
        $stream = self::streamOf('');

        $this->expectException(ClickHouseQueryException::class);

        try {
            new ClickHouseRowStream($stream);
        } finally {
            self::assertFalse(is_resource($stream));
        }
    }

    public function testABodyThatBreaksOffAfterTheNamesIsReported(): void
    {
        $stream = self::streamOf('["id","title"]' . "\n");

        $this->expectException(ClickHouseQueryException::class);

        try {
            new ClickHouseRowStream($stream);
        } finally {
            self::assertFalse(is_resource($stream));
        }
    }

    public function testAHeaderThatIsNotAListIsReported(): void
    {
        $stream = self::streamOf('{"id":"UInt32"}' . "\n" . '["UInt32"]' . "\n");

        $this->expectException(ClickHouseQueryException::class);

        try {
            new ClickHouseRowStream($stream);
        } finally {
            self::assertFalse(is_resource($stream));
        }
    }

    public function testAHeaderWithNonTextEntriesIsReported(): void
    {
        $stream = self::streamOf('["id",17]' . "\n" . '["UInt32","UInt32"]' . "\n");

        $this->expectException(ClickHouseQueryException::class);

        try {
            new ClickHouseRowStream($stream);
        } finally {
            self::assertFalse(is_resource($stream));
        }
    }

    public function testClosingEarlyReleasesTheStream(): void
    {
        $stream = self::streamOf(
            '["id"]' . "\n"
            . '["UInt32"]' . "\n"
            . '[1]' . "\n"
            . '[2]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        self::assertSame([1], $rows->fetchRow());

        $rows->close();
        $rows->close();

        self::assertFalse(is_resource($stream), 'close() must release the stream.');
        self::assertNull($rows->fetchRow(), 'A closed stream hands out no more rows.');
    }

    public function testDroppingTheReaderReleasesTheStream(): void
    {
        $stream = self::streamOf('["id"]' . "\n" . '["UInt32"]' . "\n" . '[1]' . "\n" . '[2]' . "\n");

        $rows = new ClickHouseRowStream($stream);
        self::assertSame([1], $rows->fetchRow());

        unset($rows);

        self::assertFalse(is_resource($stream), 'A consumer that stops early must still release the stream.');
    }

    public function testTheBodyOfAnAnswerThatIsNotClickHouseDoesNotReachTheCaller(): void
    {
        // The address of the source is chosen by the caller, so the answer may come from any service
        // reachable from the network of the application.
        $body = '<html>internal admin page, cookie=zzz</html>';
        $stream = self::streamOf($body . "\n");

        try {
            new ClickHouseRowStream($stream);
            self::fail('A body that is not an answer of ClickHouse must not produce a readable stream.');
        } catch (ClickHouseQueryException $exception) {
            self::assertStringNotContainsString('cookie', $exception->getMessage());
            self::assertStringNotContainsString('internal admin page', $exception->getMessage());
            self::assertSame('The answer of ClickHouse could not be read.', $exception->getMessage());
        }
    }

    public function testALineLongerThanItsBoundaryFailsTheReading(): void
    {
        $_ENV['CLICKHOUSE_MAX_LINE_BYTES'] = '1024';

        // A body without a line break used to be read into memory as a whole.
        $stream = self::streamOf('["id"]' . "\n" . '["String"]' . "\n" . '["' . str_repeat('a', 200000));

        $rows = new ClickHouseRowStream($stream);

        $this->expectException(ClickHouseQueryException::class);
        $this->expectExceptionMessage('A line of the answer of ClickHouse is longer than the 1024 bytes');

        $rows->fetchRow();
    }

    public function testALineLongerThanItsBoundaryFailsEvenWhenItEndsProperly(): void
    {
        $_ENV['CLICKHOUSE_MAX_LINE_BYTES'] = '1024';

        $stream = self::streamOf(
            '["id"]' . "\n" . '["String"]' . "\n" . '["' . str_repeat('a', 4096) . '"]' . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        $this->expectException(ClickHouseQueryException::class);

        $rows->fetchRow();
    }

    public function testABodyLongerThanItsBoundaryFailsTheReading(): void
    {
        $_ENV['CLICKHOUSE_MAX_RESPONSE_BYTES'] = '64';

        $stream = self::streamOf(
            '["id"]' . "\n"
            . '["UInt32"]' . "\n"
            . implode("\n", array_map(static fn (int $value): string => '[' . $value . ']', range(1, 100)))
            . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        $this->expectException(ClickHouseQueryException::class);
        $this->expectExceptionMessage('The answer of ClickHouse is longer than the 64 bytes');

        while ($rows->fetchRow() !== null) {
            continue;
        }
    }

    public function testTheBoundariesAreReadFromTheEnvironment(): void
    {
        $_ENV['CLICKHOUSE_MAX_LINE_BYTES'] = '2048';
        $_ENV['CLICKHOUSE_MAX_RESPONSE_BYTES'] = '4096';

        $rows = new ClickHouseRowStream(self::streamOf('["id"]' . "\n" . '["UInt32"]' . "\n"));

        $line = new \ReflectionProperty($rows, 'maxLineBytes');
        $line->setAccessible(true);
        $response = new \ReflectionProperty($rows, 'maxResponseBytes');
        $response->setAccessible(true);

        self::assertSame(2048, $line->getValue($rows));
        self::assertSame(4096, $response->getValue($rows));
    }

    public function testTheBoundaryOfTheBodyFollowsTheBoundaryOfTheRows(): void
    {
        // The two boundaries used to be set apart from one another, so a wide answer ran into the byte
        // boundary long before the row boundary and lost the work already done.
        $_ENV['MAX_RESULT_ROWS'] = '1000';
        $_ENV['CLICKHOUSE_EXPECTED_ROW_BYTES'] = '2048';

        $rows = new ClickHouseRowStream(self::streamOf('["id"]' . "\n" . '["UInt32"]' . "\n"));

        $response = new \ReflectionProperty($rows, 'maxResponseBytes');
        $response->setAccessible(true);

        self::assertSame(1000 * 2048, $response->getValue($rows));
    }

    public function testTheRefusalOverTheBodyNamesBothBoundaries(): void
    {
        $_ENV['MAX_RESULT_ROWS'] = '4';
        $_ENV['CLICKHOUSE_EXPECTED_ROW_BYTES'] = '8';

        $stream = self::streamOf(
            '["id"]' . "\n"
            . '["UInt32"]' . "\n"
            . implode("\n", array_map(static fn (int $value): string => '[' . $value . ']', range(1, 100)))
            . "\n"
        );

        $rows = new ClickHouseRowStream($stream);

        $this->expectException(ClickHouseQueryException::class);
        $this->expectExceptionMessage('longer than the 32 bytes the application reads (4 rows at 8 bytes each)');

        while ($rows->fetchRow() !== null) {
            continue;
        }
    }

    public function testAValueWithoutAScalarFormBecomesTextInTheRowItself(): void
    {
        // The row is walked once: the alignment and the published shape of the values happen together.
        $rows = new ClickHouseRowStream(self::streamOf(
            '["id","tags"]' . "\n"
            . '["UInt32","Array(String)"]' . "\n"
            . '[1,["alpha","beta"]]' . "\n"
        ));

        self::assertSame([1, '["alpha","beta"]'], $rows->fetchRow());
    }

    public function testAClosedResourceIsRefused(): void
    {
        $stream = self::streamOf('["id"]' . "\n" . '["UInt32"]' . "\n");
        fclose($stream);

        $this->expectException(\InvalidArgumentException::class);

        new ClickHouseRowStream($stream);
    }
}
