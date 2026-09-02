<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\BiConnector;
use App\DataSource\ClickHouse\ClickHouseDataSource;
use App\DataSource\ClickHouse\ClickHouseHttpClient;
use App\DataSource\ClickHouse\ClickHouseQueryException;
use App\DataSource\ClickHouse\ClickHouseRowStream;
use App\DataSource\ClickHouse\ClickHouseTypeMap;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The ClickHouse source against a live server.
 *
 * The service is the `clickhouse` one of docker-compose.yml; it is started with
 * `docker compose --profile test up -d clickhouse`. Without a reachable server every test is skipped, so a
 * run of the suite on a machine without the environment does not fail.
 */
class ClickHouseIntegrationTest extends TestCase
{
    // Credentials of the throwaway test service declared in docker-compose.yml, nothing else.
    private const DATABASE = 'bi_test';
    private const USERNAME = 'bi_test';
    private const PASSWORD = 'bi_test_password';

    // The service publishes its HTTP interface on the loopback interface of the host, and carries the
    // default port inside the compose network. A run from either side finds it without configuration.
    private const HOST_ENDPOINT = '127.0.0.1:8124';
    private const NETWORK_ENDPOINT = 'clickhouse:8123';

    private const TYPES_TABLE = 'ch_types';
    private const BULK_TABLE = 'ch_bulk';
    private const BULK_ROWS = 100000;

    private const BOUNDARY_VARIABLES = [
        'CLICKHOUSE_MAX_RESULT_ROWS',
        'CLICKHOUSE_MAX_ROWS_TO_READ',
        'CLICKHOUSE_MAX_EXECUTION_TIME',
    ];

    private static ?string $endpoint = null;
    private static bool $endpointResolved = false;
    private static bool $schemaPrepared = false;

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    private ?string $memoryLimitBackup = null;

    protected function setUp(): void
    {
        $endpoint = self::resolveEndpoint();

        if ($endpoint === null) {
            $this->markTestSkipped(sprintf(
                'No ClickHouse at %s or %s; start it with "docker compose --profile test up -d clickhouse".',
                self::HOST_ENDPOINT,
                self::NETWORK_ENDPOINT
            ));
        }

        // The read boundaries are taken from the environment, so every test starts from the defaults of
        // the application and sets only what it means to check.
        foreach (self::BOUNDARY_VARIABLES as $name) {
            $this->environmentBackup[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }

        self::prepareSchema($endpoint);
    }

    protected function tearDown(): void
    {
        foreach ($this->environmentBackup as $name => $value) {
            if ($value === null) {
                unset($_ENV[$name]);
            } else {
                $_ENV[$name] = $value;
            }
        }

        $this->environmentBackup = [];

        if ($this->memoryLimitBackup !== null) {
            ini_set('memory_limit', $this->memoryLimitBackup);
            $this->memoryLimitBackup = null;
        }
    }

    public function testCheckReachesTheSource(): void
    {
        $this->createDataSource()->check();

        $this->addToAssertionCount(1);
    }

    public function testCheckFailsOnWrongCredentials(): void
    {
        $params = $this->connectionParams();
        $params['password'] = 'not-the-password';

        $this->expectException(\RuntimeException::class);

        (new ClickHouseDataSource($params, new NullLogger()))->check();
    }

    public function testListTablesReadsTheCurrentDatabase(): void
    {
        $tables = $this->createDataSource()->listTables('');
        $codes = array_column($tables, 'code');

        $this->assertContains(self::TYPES_TABLE, $codes);
        $this->assertContains(self::BULK_TABLE, $codes);
        $this->assertSame($codes, array_column($tables, 'title'));
    }

    public function testListTablesNarrowsDownBySearchString(): void
    {
        $source = $this->createDataSource();

        $this->assertSame(
            [['code' => self::TYPES_TABLE, 'title' => self::TYPES_TABLE]],
            $source->listTables(self::TYPES_TABLE)
        );
        $this->assertSame([], $source->listTables('no_such_table_anywhere'));
    }

    public function testDescribeTableMapsBoundaryTypes(): void
    {
        $expected = [
            'ID' => ClickHouseTypeMap::TYPE_INT,
            'BIG_UNSIGNED' => ClickHouseTypeMap::TYPE_STRING,
            'BIG_SIGNED' => ClickHouseTypeMap::TYPE_INT,
            'EXACT_DECIMAL' => ClickHouseTypeMap::TYPE_DOUBLE,
            'FLOATING' => ClickHouseTypeMap::TYPE_DOUBLE,
            'FLAG' => ClickHouseTypeMap::TYPE_STRING,
            'MAYBE_TEXT' => ClickHouseTypeMap::TYPE_STRING,
            'EVENT_DATE' => ClickHouseTypeMap::TYPE_DATE,
            'EVENT_TIME' => ClickHouseTypeMap::TYPE_DATETIME,
        ];

        $fields = $this->createDataSource()->describeTable(self::TYPES_TABLE);

        $this->assertSame(array_keys($expected), array_column($fields, 'code'));
        $this->assertSame(array_keys($expected), array_column($fields, 'name'));
        $this->assertSame(array_values($expected), array_column($fields, 'type'));
    }

    public function testDescribeTableOfAnUnknownTableIsEmpty(): void
    {
        $this->assertSame([], $this->createDataSource()->describeTable('no_such_table_anywhere'));
    }

    public function testFetchDataPublishesColumnNamesFirst(): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, ['ID', 'MAYBE_TEXT'], [], 10);

        $this->assertSame(['ID', 'MAYBE_TEXT'], $rows[0]);
        $this->assertCount(4, $rows);
    }

    public function testFetchDataOfAnEmptyResultYieldsNothing(): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, ['ID'], ['ID' => 9999], 10);

        $this->assertSame([], $rows);
    }

    public function testFetchDataKeepsTheValuesOfTheBoundaryTypesExact(): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, [], ['ID' => 1], 10);

        $this->assertCount(2, $rows);
        $this->assertSame([
            'ID',
            'BIG_UNSIGNED',
            'BIG_SIGNED',
            'EXACT_DECIMAL',
            'FLOATING',
            'FLAG',
            'MAYBE_TEXT',
            'EVENT_DATE',
            'EVENT_TIME',
        ], $rows[0]);

        // A UInt64 past the range of a PHP integer, an Int64 past the precision of a float and a
        // Decimal(38, 4) all arrive as exact text; nan keeps its name instead of becoming null.
        $this->assertSame([
            1,
            '18446744073709551615',
            '9007199254740993',
            '1234567890123456789.1234',
            'nan',
            'true',
            null,
            '2026-01-15',
            '2026-01-15 10:20:30.123',
        ], $rows[1]);
    }

    public function testFetchDataKeepsTheNegativeBoundaryValuesExact(): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, ['BIG_SIGNED', 'EXACT_DECIMAL', 'FLAG'], ['ID' => 2], 10);

        $this->assertSame(['-9007199254740993', '-0.0001', 'false'], $rows[1]);
    }

    /**
     * @param array<string, mixed> $filter
     * @param list<int> $expectedIds
     */
    #[DataProvider('filterProvider')]
    public function testFetchDataAppliesEveryFilterClass(array $filter, array $expectedIds): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, ['ID'], $filter, 100);

        array_shift($rows);
        $ids = array_map(static fn(array $row): int => (int)$row[0], $rows);
        sort($ids);

        $this->assertSame($expectedIds, $ids);
    }

    /**
     * @return array<string, array{array<string, mixed>, list<int>}>
     */
    public static function filterProvider(): array
    {
        return [
            'scalar equality' => [['ID' => 3], [3]],
            'list membership' => [['ID' => [1, 3]], [1, 3]],
            'operator =' => [['ID' => ['operator' => '=', 'value' => 2]], [2]],
            'operator EQ' => [['ID' => ['operator' => 'EQ', 'value' => 2]], [2]],
            'operator !=' => [['ID' => ['operator' => '!=', 'value' => 2]], [1, 3]],
            'operator <>' => [['ID' => ['operator' => '<>', 'value' => 2]], [1, 3]],
            'operator NEQ' => [['ID' => ['operator' => 'NEQ', 'value' => 2]], [1, 3]],
            'operator >' => [['ID' => ['operator' => '>', 'value' => 2]], [3]],
            'operator GT' => [['ID' => ['operator' => 'GT', 'value' => 2]], [3]],
            'operator >=' => [['ID' => ['operator' => '>=', 'value' => 2]], [2, 3]],
            'operator GTE' => [['ID' => ['operator' => 'GTE', 'value' => 2]], [2, 3]],
            'operator <' => [['ID' => ['operator' => '<', 'value' => 2]], [1]],
            'operator LT' => [['ID' => ['operator' => 'LT', 'value' => 2]], [1]],
            'operator <=' => [['ID' => ['operator' => '<=', 'value' => 2]], [1, 2]],
            'operator LTE' => [['ID' => ['operator' => 'LTE', 'value' => 2]], [1, 2]],
            'operator LIKE' => [['MAYBE_TEXT' => ['operator' => 'LIKE', 'value' => 'lph']], [2]],
            'operator NOT LIKE' => [['MAYBE_TEXT' => ['operator' => 'NOT LIKE', 'value' => 'lph']], [3]],
            'operator IN' => [['ID' => ['operator' => 'IN', 'value' => [1, 2]]], [1, 2]],
            'operator NOT IN' => [['ID' => ['operator' => 'NOT IN', 'value' => [1, 2]]], [3]],
            'operator IS NULL' => [['MAYBE_TEXT' => ['operator' => 'IS NULL']], [1]],
            'operator IS NOT NULL' => [['MAYBE_TEXT' => ['operator' => 'IS NOT NULL']], [2, 3]],
            'operator BETWEEN' => [['ID' => ['operator' => 'BETWEEN', 'from' => 2, 'to' => 3]], [2, 3]],
            'quote inside the value' => [['MAYBE_TEXT' => "O'Brien"], []],
            'unknown operator is dropped' => [['ID' => ['operator' => 'REGEXP', 'value' => '.*']], [1, 2, 3]],
            'two fields at once' => [
                ['ID' => ['operator' => '>=', 'value' => 2], 'MAYBE_TEXT' => 'beta'],
                [3],
            ],
        ];
    }

    public function testFetchDataAppliesTheRowLimit(): void
    {
        $rows = $this->fetchRows(self::TYPES_TABLE, ['ID'], [], 2);

        $this->assertCount(3, $rows);
    }

    public function testFetchDataCapsTheRowLimitOfTheRequest(): void
    {
        // The portal asks for more rows than the application allows: the cap wins and the answer is not
        // an overflow failure.
        $_ENV['CLICKHOUSE_MAX_RESULT_ROWS'] = '2';

        $rows = $this->fetchRows(self::TYPES_TABLE, ['ID'], [], 1000);

        $this->assertCount(3, $rows);
    }

    public function testResultRowsBoundaryFailsTheStatement(): void
    {
        $_ENV['CLICKHOUSE_MAX_RESULT_ROWS'] = '10';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Limit for result exceeded/');

        $this->readThroughTransport('SELECT number FROM system.numbers LIMIT 100');
    }

    public function testRowsToReadBoundaryFailsTheStatement(): void
    {
        $_ENV['CLICKHOUSE_MAX_ROWS_TO_READ'] = '100';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches("/max_rows_to_read/");

        $this->readThroughTransport('SELECT number FROM system.numbers LIMIT 1000');
    }

    public function testExecutionTimeBoundaryFailsTheStatement(): void
    {
        $_ENV['CLICKHOUSE_MAX_EXECUTION_TIME'] = '1';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Timeout exceeded/');

        $this->readThroughTransport('SELECT sum(sleepEachRow(0.05)) FROM numbers(40)');
    }

    public function testAFailureAppendedToAStartedAnswerBecomesAnError(): void
    {
        // The statement fails in the middle of the answer: the status is already 200, the header and a
        // part of the rows have been read, and the reason arrives inside the body afterwards. Reaching a
        // ClickHouseQueryException and not the \RuntimeException of the transport is what tells a failure
        // of the body apart from a failure of the request.
        $handedOut = 0;

        try {
            $this->readRowsThroughStream(
                "SELECT number, toString(number), throwIf(number = 400000, 'late failure') FROM system.numbers",
                $handedOut
            );
            $this->fail('A failure appended to a started answer must not pass for the end of the data.');
        } catch (ClickHouseQueryException $exception) {
            $this->assertMatchesRegularExpression(
                '/FUNCTION_THROW_IF_VALUE_IS_NON_ZERO/',
                $exception->getMessage()
            );
        }

        $this->assertGreaterThan(
            0,
            $handedOut,
            'The answer has to be a truncated successful one, otherwise the failure is not the late kind.'
        );
    }

    public function testAFailureAppendedToASingleColumnAnswerBecomesAnError(): void
    {
        // One column: the appended failure carries exactly as many values as a data row of this answer,
        // so nothing but the message itself tells them apart. The server sends the header and appends the
        // reason to it, which is the position a data row would take.
        $_ENV['CLICKHOUSE_MAX_RESULT_ROWS'] = '100000';
        $handedOut = 0;

        $this->expectException(ClickHouseQueryException::class);
        $this->expectExceptionMessageMatches('/Limit for result exceeded/');

        $this->readRowsThroughStream('SELECT number FROM system.numbers LIMIT 200000', $handedOut);
    }

    public function testLargeResultKeepsTheMemoryFlatAndLeavesNoTemporaryFile(): void
    {
        $connector = new BiConnector($this->connectionParams(), 'clickhouse', new NullLogger());

        // The run is bounded to 32 MB above what the harness has already allocated, so a body that is
        // collected in memory instead of being streamed cannot pass unnoticed.
        $this->memoryLimitBackup = (string)ini_get('memory_limit');
        ini_set('memory_limit', (string)(memory_get_usage(true) + 32 * 1024 * 1024));

        $smallBody = $this->measureResponse($connector, 1000);
        $largeBody = $this->measureResponse($connector, self::BULK_ROWS);

        $this->assertGreaterThan(1024 * 1024, $largeBody['size']);
        $this->assertLessThan(
            4 * 1024 * 1024,
            $largeBody['peak'],
            'The peak memory grows with the number of rows, so the body is not streamed.'
        );
        $this->assertLessThan($largeBody['size'], $smallBody['size'] * 10);
    }

    public function testConnectivityScriptAnswersForBothStates(): void
    {
        [$host, $port] = explode(':', self::endpoint());
        $script = dirname(__DIR__, 2) . '/scripts/test_clickhouse.sh';

        $this->assertSame(0, $this->runConnectivityScript($script, $host, $port));
        // A port nothing listens on: the script reports a failure instead of a success.
        $this->assertSame(1, $this->runConnectivityScript($script, $host, '1'));
    }

    private function runConnectivityScript(string $script, string $host, string $port): int
    {
        $command = sprintf(
            'bash %s %s %s %s %s %s > /dev/null 2>&1',
            escapeshellarg($script),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg(self::DATABASE),
            escapeshellarg(self::USERNAME),
            escapeshellarg(self::PASSWORD)
        );

        exec($command, $output, $exitCode);

        return $exitCode;
    }

    /**
     * Reads the whole answer of a statement through the transport, without the source in between.
     *
     * The row limit of the application always reaches the statement as a `LIMIT` clause, so a statement
     * that outgrows a read boundary is written here directly.
     */
    private function readThroughTransport(string $sql): void
    {
        $client = new ClickHouseHttpClient($this->connectionParams(), new NullLogger());
        $stream = $client->query($sql);

        while (fgets($stream) !== false) {
            continue;
        }

        fclose($stream);
    }

    /**
     * Reads the answer of a statement the way a request does: through the row reader.
     *
     * @param int $handedOut receives the number of rows the reader handed out, the rows read before a
     *                       failure included
     */
    private function readRowsThroughStream(string $sql, int &$handedOut): void
    {
        $client = new ClickHouseHttpClient($this->connectionParams(), new NullLogger());
        $rows = new ClickHouseRowStream($client->query($sql));
        $handedOut = 0;

        while ($rows->fetchRow() !== null) {
            $handedOut++;
        }
    }

    /**
     * @return array{size: int, peak: int}
     */
    private function measureResponse(BiConnector $connector, int $limit): array
    {
        $peakBefore = memory_get_peak_usage(true);
        $response = $connector->getData(self::BULK_TABLE, ['ID', 'LABEL'], [], $limit);
        $peak = memory_get_peak_usage(true) - $peakBefore;

        $this->assertInstanceOf(BinaryFileResponse::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        $path = $response->getFile()->getPathname();
        $size = (int)filesize($path);

        // The body is discarded in chunks: collecting it here would put the very load into the test that
        // the application avoids.
        ob_start(static fn(string $chunk): string => '', 8192);
        $response->send();
        ob_end_clean();

        $this->assertFileDoesNotExist($path, 'The temporary body file outlived the response.');

        return ['size' => $size, 'peak' => $peak];
    }

    /**
     * @param array<int, string> $select
     * @param array<string, mixed> $filter
     *
     * @return list<list<scalar|null>>
     */
    private function fetchRows(string $table, array $select, array $filter, int $limit): array
    {
        $rows = [];

        foreach ($this->createDataSource()->fetchData($table, $select, $filter, $limit) as $row) {
            $rows[] = $row;
        }

        return $rows;
    }

    private function createDataSource(): ClickHouseDataSource
    {
        return new ClickHouseDataSource($this->connectionParams(), new NullLogger());
    }

    /**
     * @return array<string, string>
     */
    private function connectionParams(): array
    {
        return [
            'host' => self::endpoint(),
            'database' => self::DATABASE,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ];
    }

    private static function endpoint(): string
    {
        return self::$endpoint ?? throw new \RuntimeException('The ClickHouse endpoint is not resolved.');
    }

    /**
     * The schema of the test is created by the test itself and only once per run.
     */
    private static function prepareSchema(string $endpoint): void
    {
        if (self::$schemaPrepared) {
            return;
        }

        self::execute($endpoint, 'DROP TABLE IF EXISTS `' . self::TYPES_TABLE . '`');
        self::execute($endpoint, 'DROP TABLE IF EXISTS `' . self::BULK_TABLE . '`');
        self::execute($endpoint, 'CREATE TABLE `' . self::TYPES_TABLE . '` ('
            . '`ID` UInt32,'
            . '`BIG_UNSIGNED` UInt64,'
            . '`BIG_SIGNED` Int64,'
            . '`EXACT_DECIMAL` Decimal(38, 4),'
            . '`FLOATING` Float64,'
            . '`FLAG` Bool,'
            . '`MAYBE_TEXT` Nullable(String),'
            . '`EVENT_DATE` Date,'
            . '`EVENT_TIME` DateTime64(3)'
            . ') ENGINE = MergeTree ORDER BY `ID`');
        self::execute($endpoint, 'INSERT INTO `' . self::TYPES_TABLE . '` VALUES '
            . "(1, 18446744073709551615, 9007199254740993, 1234567890123456789.1234, nan, true, NULL,"
            . " '2026-01-15', '2026-01-15 10:20:30.123'),"
            . " (2, 0, -9007199254740993, -0.0001, 1.5, false, 'alpha',"
            . " '2026-02-20', '2026-02-20 00:00:00.001'),"
            . " (3, 42, 42, 42.0000, 2.5, true, 'beta',"
            . " '2026-03-25', '2026-03-25 23:59:59.999')");
        self::execute($endpoint, 'CREATE TABLE `' . self::BULK_TABLE . '` ('
            . '`ID` UInt32,'
            . '`LABEL` String'
            . ') ENGINE = MergeTree ORDER BY `ID`');
        self::execute($endpoint, 'INSERT INTO `' . self::BULK_TABLE . '` '
            . "SELECT number, concat('row-', toString(number)) FROM numbers(" . self::BULK_ROWS . ')');

        self::$schemaPrepared = true;
    }

    /**
     * Statements of the test setup travel outside the application: the source of the application never
     * writes, and the schema of the test has to be written.
     */
    private static function execute(string $endpoint, string $sql): void
    {
        $response = HttpClient::create()->request('POST', 'http://' . $endpoint . '/?database=' . self::DATABASE, [
            'headers' => [
                'X-ClickHouse-User' => self::USERNAME,
                'X-ClickHouse-Key' => self::PASSWORD,
            ],
            'body' => $sql,
            'timeout' => 30,
        ]);

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('The test schema could not be prepared: ' . $response->getContent(false));
        }
    }

    private static function resolveEndpoint(): ?string
    {
        if (self::$endpointResolved) {
            return self::$endpoint;
        }

        self::$endpointResolved = true;
        $configured = $_ENV['CLICKHOUSE_TEST_ENDPOINT'] ?? getenv('CLICKHOUSE_TEST_ENDPOINT');
        $candidates = is_string($configured) && $configured !== ''
            ? [$configured]
            : [self::HOST_ENDPOINT, self::NETWORK_ENDPOINT];

        foreach ($candidates as $candidate) {
            if (self::isAlive($candidate)) {
                self::$endpoint = $candidate;

                break;
            }
        }

        return self::$endpoint;
    }

    private static function isAlive(string $endpoint): bool
    {
        try {
            $response = HttpClient::create()->request('GET', 'http://' . $endpoint . '/ping', [
                'timeout' => 2,
                'max_duration' => 5,
            ]);

            return $response->getStatusCode() === 200 && str_contains($response->getContent(false), 'Ok');
        } catch (\Throwable) {
            return false;
        }
    }
}
