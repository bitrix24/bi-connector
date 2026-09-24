<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The whole path of a request against a live ClickHouse: HTTP request to public/index.php, the entry point
 * with its validation, the connector, the source, the answer.
 *
 * The entry point is a script that ends in `$response->send()`, so it is exercised the way a portal
 * exercises it: a web server of its own is started for the run, and every action is asked for over HTTP.
 * Nothing here is asserted against the classes directly; a break anywhere between the wire and the source
 * shows up as a wrong answer.
 *
 * Both preconditions are provided by the run itself: the source is the `clickhouse` service of
 * docker-compose.yml (`docker compose --profile test up -d clickhouse`), and the table of the scenario is
 * created and dropped by the test. Without a reachable source every test is skipped.
 */
class ClickHouseEndToEndTest extends TestCase
{
    // Credentials of the throwaway test service declared in docker-compose.yml, nothing else.
    private const DATABASE = 'bi_test';
    private const USERNAME = 'bi_test';
    private const PASSWORD = 'bi_test_password';

    private const HOST_ENDPOINT = '127.0.0.1:8124';
    private const NETWORK_ENDPOINT = 'clickhouse:8123';

    private const SERVER_START_TIMEOUT_SECONDS = 10;

    private static ?string $endpoint = null;
    private static bool $endpointResolved = false;

    /** @var resource|null */
    private static $server = null;
    private static ?string $baseUrl = null;
    private static ?string $logPath = null;
    private static ?string $table = null;

    private HttpClientInterface $httpClient;

    protected function setUp(): void
    {
        if (self::resolveEndpoint() === null) {
            $this->markTestSkipped(sprintf(
                'PRECONDITION: no ClickHouse at %s or %s; start it with '
                . '"docker compose --profile test up -d clickhouse".',
                self::HOST_ENDPOINT,
                self::NETWORK_ENDPOINT
            ));
        }

        $this->httpClient = HttpClient::create();

        self::prepareTable();
        self::startServer();
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();
        self::dropTable();
        self::removeLogPath();

        self::$endpoint = null;
        self::$endpointResolved = false;
    }

    public function testCheckReportsASuccessfulConnection(): void
    {
        $answer = $this->call('check', ['connection' => $this->connection()]);

        $this->assertSame(200, $answer['status']);
        $this->assertSame('OK', $answer['body']['status'] ?? null, $answer['raw']);
    }

    public function testTableListCarriesTheTableOfTheScenario(): void
    {
        $answer = $this->call('table_list', [
            'connection' => $this->connection(),
            // The list is cached by connection and search string, so the name of this run keys its own entry.
            'searchString' => self::table(),
        ]);

        $this->assertSame(200, $answer['status'], $answer['raw']);
        $this->assertSame(
            [['code' => self::table(), 'title' => self::table()]],
            $answer['body'],
            $answer['raw']
        );
    }

    public function testTableDescriptionCarriesTheColumnsWithTheirTypes(): void
    {
        $answer = $this->call('table_description', [
            'connection' => $this->connection(),
            'table' => self::table(),
        ]);

        $this->assertSame(200, $answer['status'], $answer['raw']);
        $this->assertSame(
            [
                ['code' => 'ID', 'name' => 'ID', 'type' => 'int'],
                ['code' => 'LABEL', 'name' => 'LABEL', 'type' => 'string'],
                ['code' => 'AMOUNT', 'name' => 'AMOUNT', 'type' => 'double'],
            ],
            $answer['body'],
            $answer['raw']
        );
    }

    public function testDataAnswersWithTheSelectedRows(): void
    {
        $answer = $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table(),
            'select' => ['ID', 'LABEL'],
            'filter' => ['ID' => ['operator' => '>=', 'value' => '2']],
            'limit' => '10',
        ]);

        $this->assertSame(200, $answer['status'], $answer['raw']);
        // The first row of the answer carries the column names, every row after it carries the values.
        $this->assertSame(
            [
                ['ID', 'LABEL'],
                [2, 'second'],
                [3, 'third'],
            ],
            $answer['body'],
            $answer['raw']
        );
    }

    public function testDataHoldsTheRowLimitOfTheRequest(): void
    {
        $answer = $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table(),
            'select' => ['ID'],
            'filter' => [],
            'limit' => '1',
        ]);

        $this->assertSame(200, $answer['status'], $answer['raw']);
        $this->assertSame([['ID'], [1]], $answer['body'], $answer['raw']);
    }

    public function testDataRefusesARowLimitAboveTheBoundOfTheApplication(): void
    {
        // The portal asks for more rows than the application returns: the answer is a refusal naming both
        // numbers, and never a shortened dataset under a successful status.
        $answer = $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table(),
            'select' => ['ID'],
            'filter' => [],
            'limit' => '100000000',
        ]);

        $this->assertSame(500, $answer['status'], $answer['raw']);
        $error = (string)($answer['body']['error'] ?? '');
        $this->assertStringContainsString('The request asks for 100000000 rows', $error, $answer['raw']);
        $this->assertStringContainsString('which is above the', $error, $answer['raw']);
    }

    public function testAnUnknownConnectionTypeIsRefused(): void
    {
        $answer = $this->call('check', ['connection' => $this->connection()], 'clickhouse-cluster');

        $this->assertSame(500, $answer['status'], $answer['raw']);
        $this->assertStringContainsString('Valid connection_type', (string)($answer['body']['error'] ?? ''));
    }

    public function testATableNameOutsideTheAlphabetIsRefused(): void
    {
        $answer = $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table() . '`; DROP TABLE x; --',
            'select' => ['ID'],
            'filter' => [],
            'limit' => '10',
        ]);

        $this->assertSame(500, $answer['status'], $answer['raw']);
        $this->assertStringContainsString('Invalid table name', (string)($answer['body']['error'] ?? ''));
    }

    public function testAFieldNameOutsideTheAlphabetIsRefused(): void
    {
        $answer = $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table(),
            'select' => ['ID', 'LABEL) FROM system.tables --'],
            'filter' => [],
            'limit' => '10',
        ]);

        $this->assertSame(500, $answer['status'], $answer['raw']);
        $this->assertStringContainsString('Invalid field name', (string)($answer['body']['error'] ?? ''));
    }

    public function testAWrongPasswordIsReportedInsteadOfAConnection(): void
    {
        $connection = $this->connection();
        $connection['password'] = 'not-the-password';

        $answer = $this->call('check', ['connection' => $connection]);

        $this->assertSame(200, $answer['status'], $answer['raw']);
        $this->assertSame('ERROR', $answer['body']['status'] ?? null, $answer['raw']);
    }

    public function testThePasswordNeverReachesTheLog(): void
    {
        $this->call('check', ['connection' => $this->connection()]);
        $this->call('data', [
            'connection' => $this->connection(),
            'table' => self::table(),
            'select' => ['ID'],
            'filter' => [],
            'limit' => '10',
        ]);

        $log = self::readLog();

        $this->assertNotSame('', $log, 'The run has to leave a log to look into.');
        $this->assertStringNotContainsString(self::PASSWORD, $log);
    }

    /**
     * Sends one action to the entry point the way a portal sends it.
     *
     * @param array<string, mixed> $input
     *
     * @return array{status: int, body: mixed, raw: string}
     */
    private function call(string $action, array $input, string $connectionType = 'clickhouse'): array
    {
        $response = $this->httpClient->request('POST', self::baseUrl() . '/?' . http_build_query([
            'action' => $action,
            'connection_type' => $connectionType,
        ]), [
            'body' => $input,
            'timeout' => 30,
            'max_duration' => 60,
        ]);

        $raw = $response->getContent(false);

        return [
            'status' => $response->getStatusCode(),
            'body' => json_decode($raw, true),
            'raw' => $raw,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function connection(): array
    {
        return [
            'host' => self::endpoint(),
            'database' => self::DATABASE,
            'username' => self::USERNAME,
            'password' => self::PASSWORD,
        ];
    }

    /**
     * The web server of the run: the entry point is a script, so it is reached over HTTP and not by an
     * include. The environment is handed over to the process, and `variables_order` is widened for it
     * because the application reads its settings from `$_ENV`.
     */
    private static function startServer(): void
    {
        if (self::$server !== null) {
            return;
        }

        $root = dirname(__DIR__, 2);
        self::$logPath = sys_get_temp_dir() . '/bi-connector-e2e-' . bin2hex(random_bytes(6));
        $port = self::findFreePort();

        $command = sprintf(
            'exec php -d variables_order=EGPCS -S 127.0.0.1:%d -t %s',
            $port,
            escapeshellarg($root . '/public')
        );

        $descriptors = [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']];
        $process = proc_open($command, $descriptors, $pipes, $root, [
            'PATH' => (string)getenv('PATH'),
            'LOG_LEVEL' => 'DEBUG',
            'LOG_PATH' => self::$logPath,
            'LOG_ROTATION_DAYS' => '1',
        ]);

        if (!is_resource($process)) {
            throw new \RuntimeException('The web server of the test could not be started.');
        }

        self::$server = $process;
        self::$baseUrl = 'http://127.0.0.1:' . $port;
        self::waitForServer();
    }

    private static function waitForServer(): void
    {
        $deadline = microtime(true) + self::SERVER_START_TIMEOUT_SECONDS;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', (int)parse_url(self::baseUrl(), PHP_URL_PORT), $code, $message, 1);

            if (is_resource($connection)) {
                fclose($connection);

                return;
            }

            usleep(100000);
        }

        throw new \RuntimeException('The web server of the test did not answer in time.');
    }

    private static function stopServer(): void
    {
        if (self::$server === null) {
            return;
        }

        proc_terminate(self::$server);
        proc_close(self::$server);

        self::$server = null;
        self::$baseUrl = null;
    }

    private static function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $code, $message);

        if ($socket === false) {
            throw new \RuntimeException('No free port for the web server of the test: ' . $message);
        }

        $name = (string)stream_socket_get_name($socket, false);
        fclose($socket);

        return (int)substr($name, (int)strrpos($name, ':') + 1);
    }

    /**
     * The whole log of the run, all rotated files of the day included.
     */
    private static function readLog(): string
    {
        $files = glob((string)self::$logPath . '/application*.log') ?: [];
        $log = '';

        foreach ($files as $file) {
            $log .= (string)file_get_contents($file);
        }

        return $log;
    }

    private static function removeLogPath(): void
    {
        $files = glob((string)self::$logPath . '/*') ?: [];

        foreach ($files as $file) {
            @unlink($file);
        }

        if (self::$logPath !== null && is_dir(self::$logPath)) {
            @rmdir(self::$logPath);
        }

        self::$logPath = null;
    }

    /**
     * The table of the scenario is created once per run and carries a name of its own, so a run never
     * meets the rows or the cached answers of another one.
     */
    private static function prepareTable(): void
    {
        if (self::$table !== null) {
            return;
        }

        self::$table = 'e2e_ch_' . bin2hex(random_bytes(6));

        self::execute('CREATE TABLE `' . self::$table . '` ('
            . '`ID` UInt32,'
            . '`LABEL` String,'
            . '`AMOUNT` Decimal(10, 2)'
            . ') ENGINE = MergeTree ORDER BY `ID`');
        self::execute('INSERT INTO `' . self::$table . '` VALUES '
            . "(1, 'first', 10.50), (2, 'second', 20.25), (3, 'third', 30.00)");
    }

    private static function dropTable(): void
    {
        if (self::$table === null || self::$endpoint === null) {
            return;
        }

        self::execute('DROP TABLE IF EXISTS `' . self::$table . '`');
        self::$table = null;
    }

    private static function table(): string
    {
        return self::$table ?? throw new \RuntimeException('The table of the scenario is not created.');
    }

    private static function baseUrl(): string
    {
        return self::$baseUrl ?? throw new \RuntimeException('The web server of the test is not started.');
    }

    private static function endpoint(): string
    {
        return self::$endpoint ?? throw new \RuntimeException('The ClickHouse endpoint is not resolved.');
    }

    private static function execute(string $sql): void
    {
        $response = HttpClient::create()->request(
            'POST',
            'http://' . self::endpoint() . '/?database=' . self::DATABASE,
            [
                'headers' => [
                    'X-ClickHouse-User' => self::USERNAME,
                    'X-ClickHouse-Key' => self::PASSWORD,
                ],
                'body' => $sql,
                'timeout' => 30,
            ]
        );

        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('The table of the scenario could not be prepared: '
                . $response->getContent(false));
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
