<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseHttpClient;
use App\DataSource\RowLimit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ClickHouseHttpClientTest extends TestCase
{
    private const BOUNDARY_VARIABLES = [
        'MAX_RESULT_ROWS',
        'CLICKHOUSE_MAX_ROWS_TO_READ',
        'CLICKHOUSE_MAX_EXECUTION_TIME',
        'CLICKHOUSE_CHECK_TIMEOUT_SECONDS',
    ];

    // ClickHouse marks a failed answer with the numeric code of its exception; the application reads a body
    // as a failure only when the answer carries that header.
    private const FAILURE_HEADERS = ['X-ClickHouse-Exception-Code' => '62'];

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    /** @var array<string, mixed>|null */
    private ?array $capturedRequest = null;

    protected function setUp(): void
    {
        // The boundaries are read from the environment, so every test starts from the defaults of the
        // application and not from whatever the shell of the developer carries.
        foreach (self::BOUNDARY_VARIABLES as $name) {
            $this->environmentBackup[$name] = $_ENV[$name] ?? null;
            unset($_ENV[$name]);
        }

        $this->capturedRequest = null;
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
     * @return list<array{0: string, 1: string}>
     */
    public static function endpointProvider(): array
    {
        return [
            'bare host takes the plain scheme and its port' => [
                'ch.example.com',
                'http://ch.example.com:8123/',
            ],
            'explicit plain scheme keeps its port' => [
                'http://ch.example.com',
                'http://ch.example.com:8123/',
            ],
            'secure scheme takes the secure port' => [
                'https://ch.example.com',
                'https://ch.example.com:8443/',
            ],
            'explicit port wins over the default one' => [
                'ch.example.com:9000',
                'http://ch.example.com:9000/',
            ],
            'a path of the address is kept' => [
                'https://gateway.example.com/clickhouse/',
                'https://gateway.example.com:8443/clickhouse/',
            ],
        ];
    }

    /**
     * @return list<array{0: string, 1: mixed, 2: string}>
     */
    public static function portSettingProvider(): array
    {
        return [
            'the port setting is used when the address names none' => [
                'ch.example.com',
                '8124',
                'http://ch.example.com:8124/',
            ],
            'the port of the address wins over the setting' => [
                'ch.example.com:9000',
                '8124',
                'http://ch.example.com:9000/',
            ],
            'an empty port setting falls back to the default of the scheme' => [
                'ch.example.com',
                '',
                'http://ch.example.com:8123/',
            ],
            'the secure default applies to a secure address without a port setting' => [
                'https://ch.example.com',
                '0',
                'https://ch.example.com:8443/',
            ],
        ];
    }

    #[DataProvider('portSettingProvider')]
    public function testThePortSettingOfTheConnectionIsUsed(
        string $host,
        mixed $port,
        string $expectedEndpoint
    ): void {
        $client = $this->createClient($this->createCapturingTransport(), [
            'host' => $host,
            'port' => $port,
        ]);

        $this->closeStream($client->query('SELECT 1'));

        $this->assertSame($expectedEndpoint, $this->requestEndpoint());
    }

    #[DataProvider('endpointProvider')]
    public function testEndpointIsBuiltFromHost(string $host, string $expectedEndpoint): void
    {
        $client = $this->createClient($this->createCapturingTransport(), ['host' => $host]);

        $this->closeStream($client->query('SELECT 1'));

        $this->assertSame($expectedEndpoint, $this->requestEndpoint());
    }

    #[DataProvider('endpointProvider')]
    public function testCredentialsNeverReachTheUrl(string $host): void
    {
        $client = $this->createClient($this->createCapturingTransport(), [
            'host' => $host,
            'username' => 'reporting_user',
            'password' => 'S3cr3t-Pass',
        ]);

        $this->closeStream($client->query('SELECT 1'));

        $url = $this->requestUrl();
        $this->assertStringNotContainsString('reporting_user', $url);
        $this->assertStringNotContainsString('S3cr3t-Pass', $url);
        $this->assertStringNotContainsString('password', $url);
        $this->assertStringNotContainsString('user', $url);
    }

    public function testCredentialsTravelAsHeaders(): void
    {
        $client = $this->createClient($this->createCapturingTransport(), [
            'username' => 'reporting_user',
            'password' => 'S3cr3t-Pass',
        ]);

        $this->closeStream($client->query('SELECT 1'));

        $headers = $this->requestHeaders();
        $this->assertContains('X-ClickHouse-User: reporting_user', $headers);
        $this->assertContains('X-ClickHouse-Key: S3cr3t-Pass', $headers);
    }

    public function testStatementTravelsAsThePostBody(): void
    {
        $client = $this->createClient($this->createCapturingTransport());

        $this->closeStream($client->query('SELECT name FROM system.tables'));

        $this->assertSame('POST', $this->capturedRequest['method']);
        $this->assertSame('SELECT name FROM system.tables', $this->capturedRequest['options']['body']);
    }

    public function testRequestCarriesTheWholeParameterSet(): void
    {
        $client = $this->createClient($this->createCapturingTransport(), ['database' => 'analytics']);

        $this->closeStream($client->query('SELECT 1', 500));

        $this->assertSame([
            'default_format' => 'JSONCompactEachRowWithNamesAndTypes',
            'readonly' => '2',
            'output_format_json_quote_64bit_integers' => '1',
            'output_format_json_quote_decimals' => '1',
            'output_format_json_quote_denormals' => '1',
            'max_result_rows' => '500',
            'result_overflow_mode' => 'throw',
            'max_rows_to_read' => '100000000',
            'read_overflow_mode' => 'throw',
            'max_execution_time' => '60',
            'timeout_overflow_mode' => 'throw',
            'database' => 'analytics',
        ], $this->requestParameters());
    }

    public function testBoundariesFollowTheEnvironment(): void
    {
        $_ENV['CLICKHOUSE_MAX_ROWS_TO_READ'] = '250000';
        $_ENV['CLICKHOUSE_MAX_EXECUTION_TIME'] = '15';

        $client = $this->createClient($this->createCapturingTransport());

        $this->closeStream($client->query('SELECT 1', 10));

        $parameters = $this->requestParameters();
        $this->assertSame('250000', $parameters['max_rows_to_read']);
        $this->assertSame('15', $parameters['max_execution_time']);
    }

    public function testRowLimitAboveTheApplicationBoundIsRefused(): void
    {
        $_ENV['MAX_RESULT_ROWS'] = '1000';

        $client = $this->createClient($this->createCapturingTransport());

        try {
            $client->query('SELECT 1', 5000);
            $this->fail('A row limit above the bound of the application must not be lowered silently.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertStringContainsString('5000', $exception->getMessage());
            $this->assertStringContainsString('1000', $exception->getMessage());
        }

        $this->assertNull($this->capturedRequest, 'A refused statement must not reach the source.');
    }

    public function testRowLimitBelowTheApplicationCapIsKept(): void
    {
        $_ENV['MAX_RESULT_ROWS'] = '1000';

        $client = $this->createClient($this->createCapturingTransport());

        $this->assertSame(700, $client->resolveRowLimit(700));

        $this->closeStream($client->query('SELECT 1', 700));

        $this->assertSame('700', $this->requestParameters()['max_result_rows']);
    }

    public function testMissingRowLimitFallsBackToTheApplicationCap(): void
    {
        $_ENV['MAX_RESULT_ROWS'] = '1000';

        $client = $this->createClient($this->createCapturingTransport());

        $this->assertSame(1000, $client->resolveRowLimit(0));

        $this->closeStream($client->query('SELECT 1'));

        $this->assertSame('1000', $this->requestParameters()['max_result_rows']);
    }

    public function testCatalogueStatementCarriesTheSameBoundaries(): void
    {
        $client = $this->createClient($this->createCapturingTransport(), ['database' => 'analytics']);

        $this->closeStream($client->query("SELECT name FROM system.columns WHERE database = 'analytics'"));

        $parameters = $this->requestParameters();
        $this->assertSame((string)(new RowLimit(0))->getMaximum(), $parameters['max_result_rows']);
        $this->assertSame('100000000', $parameters['max_rows_to_read']);
        $this->assertSame('60', $parameters['max_execution_time']);
        $this->assertSame('throw', $parameters['result_overflow_mode']);
        $this->assertSame('throw', $parameters['read_overflow_mode']);
        $this->assertSame('throw', $parameters['timeout_overflow_mode']);
    }

    public function testTransportGetsExplicitTimeoutsAndNoRedirects(): void
    {
        $client = $this->createClient($this->createCapturingTransport());

        $this->closeStream($client->query('SELECT 1'));

        $options = $this->capturedRequest['options'];
        $this->assertSame(0, $options['max_redirects']);
        $this->assertSame(300.0, $options['timeout']);
        $this->assertSame(600.0, $options['max_duration']);
        $this->assertFalse($options['buffer']);
    }

    public function testAnswerIsHandedOverAsAStream(): void
    {
        $body = "[\"id\"]\n[\"UInt64\"]\n[\"1\"]\n";
        $transport = new MockHttpClient(new MockResponse($body, ['http_code' => 200]));

        $client = $this->createClient($transport);
        $stream = $client->query('SELECT id FROM report');

        $this->assertIsResource($stream);
        $this->assertSame($body, stream_get_contents($stream));

        fclose($stream);
    }

    public function testRedirectFailsTheStatementWithoutASecondRequest(): void
    {
        $transport = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location' => 'http://elsewhere.example.com/'],
        ]));

        $client = $this->createClient($transport);

        try {
            $client->query('SELECT 1');
            $this->fail('A redirect answer must fail the statement.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('302', $exception->getMessage());
        }

        $this->assertSame(1, $transport->getRequestsCount());
    }

    public function testFailedStatementReportsTheMessageOfTheSource(): void
    {
        $transport = new MockHttpClient(new MockResponse(
            'Code: 62. DB::Exception: Syntax error',
            ['http_code' => 500, 'response_headers' => self::FAILURE_HEADERS]
        ));

        $client = $this->createClient($transport);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Code: 62. DB::Exception: Syntax error');

        $client->query('SELECT bad');
    }

    public function testTheBodyOfAnAnswerThatIsNotClickHouseDoesNotReachTheCaller(): void
    {
        // The address of the source is chosen by the caller, so the answer of a failed request may belong
        // to any service reachable from the network of the application.
        $transport = new MockHttpClient(new MockResponse(
            "SECRET-INTERNAL-PAGE: token=abcdef\nsecond line",
            ['http_code' => 404]
        ));

        $client = $this->createClient($transport);

        try {
            $client->query('SELECT 1');
            $this->fail('An answer outside the successful range must fail the statement.');
        } catch (\RuntimeException $exception) {
            $this->assertStringNotContainsString('SECRET-INTERNAL-PAGE', $exception->getMessage());
            $this->assertStringNotContainsString('token=abcdef', $exception->getMessage());
            $this->assertSame('ClickHouse answered with HTTP status 404.', $exception->getMessage());
        }
    }

    public function testOnlyTheFailureTextOfAPartlyWrittenAnswerReachesTheCaller(): void
    {
        // A statement that fails while its answer is being put together is answered with the part of the
        // result the server had already written and the display text of the failure after it.
        $transport = new MockHttpClient(new MockResponse(
            '["number"]' . "\n" . '["UInt64"]' . "\n"
            . '["Code: 396. DB::Exception: Limit for result exceeded (TOO_MANY_ROWS_OR_BYTES)"]',
            ['http_code' => 500, 'response_headers' => self::FAILURE_HEADERS]
        ));

        $client = $this->createClient($transport);

        try {
            $client->query('SELECT number FROM system.numbers');
            $this->fail('An answer outside the successful range must fail the statement.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Limit for result exceeded', $exception->getMessage());
            $this->assertStringNotContainsString('["number"]', $exception->getMessage());
            $this->assertStringNotContainsString('UInt64', $exception->getMessage());
        }
    }

    public function testAFailureTextReflectedByAnotherServiceDoesNotReachTheCaller(): void
    {
        // The caller chooses the address, so it can send the pattern of a failure text out inside the
        // request. A service that reflects what it is sent answers with that pattern and with whatever it
        // writes after it, and none of that belongs to the caller.
        $transport = new MockHttpClient(new MockResponse(
            "unknown database: Code: 1. DB::Exception\nservice=metadata-v1 token=eyJhbGciOiJIUzI1NiJ9.SECRET\n",
            ['http_code' => 404]
        ));

        $client = $this->createClient($transport);

        try {
            $client->query('SELECT 1');
            $this->fail('An answer outside the successful range must fail the statement.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ClickHouse answered with HTTP status 404.', $exception->getMessage());
            $this->assertStringNotContainsString('token=', $exception->getMessage());
            $this->assertStringNotContainsString('metadata-v1', $exception->getMessage());
        }
    }

    public function testAFailureTextThatDoesNotOpenALineDoesNotReachTheCaller(): void
    {
        // Even with the header of ClickHouse on the answer, the display text is read only where the server
        // writes it: at the beginning of a line of the body.
        $transport = new MockHttpClient(new MockResponse(
            "unknown database: Code: 1. DB::Exception\nservice=metadata-v1 token=eyJhbGciOiJIUzI1NiJ9.SECRET\n",
            ['http_code' => 404, 'response_headers' => self::FAILURE_HEADERS]
        ));

        $client = $this->createClient($transport);

        try {
            $client->query('SELECT 1');
            $this->fail('An answer outside the successful range must fail the statement.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('ClickHouse answered with HTTP status 404.', $exception->getMessage());
        }
    }

    public function testTheAvailabilityCheckGetsAShortTimeBudget(): void
    {
        $client = $this->createClient($this->createCapturingTransport());

        $this->closeStream($client->queryAvailability('SELECT 1'));

        $options = $this->capturedRequest['options'];
        $this->assertSame(10.0, $options['timeout']);
        $this->assertSame(10.0, $options['max_duration']);
    }

    public function testTheTimeBudgetOfTheCheckFollowsTheEnvironment(): void
    {
        $_ENV['CLICKHOUSE_CHECK_TIMEOUT_SECONDS'] = '3';

        $client = $this->createClient($this->createCapturingTransport());

        $this->closeStream($client->queryAvailability('SELECT 1'));

        $options = $this->capturedRequest['options'];
        $this->assertSame(3.0, $options['timeout']);
        $this->assertSame(3.0, $options['max_duration']);
    }

    public function testUnreadableHostIsRejected(): void
    {
        $client = $this->createClient(new MockHttpClient(), ['host' => '   ']);

        $this->expectException(\InvalidArgumentException::class);

        $client->query('SELECT 1');
    }

    /**
     * @param array<string, mixed> $connectionParams
     */
    private function createClient(MockHttpClient $transport, array $connectionParams = []): ClickHouseHttpClient
    {
        return new ClickHouseHttpClient(
            $connectionParams + [
                'host' => 'ch.example.com',
                'database' => 'test_db',
                'username' => 'test_user',
                'password' => 'test_pass',
            ],
            new NullLogger(),
            $transport
        );
    }

    private function createCapturingTransport(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('', ['http_code' => 200]);
        });
    }

    private function requestUrl(): string
    {
        $this->assertNotNull($this->capturedRequest, 'No request reached the transport.');

        return (string)$this->capturedRequest['url'];
    }

    private function requestEndpoint(): string
    {
        return explode('?', $this->requestUrl(), 2)[0];
    }

    /**
     * @return array<string, string>
     */
    private function requestParameters(): array
    {
        $query = explode('?', $this->requestUrl(), 2)[1] ?? '';
        parse_str($query, $parameters);

        /** @var array<string, string> $parameters */
        return $parameters;
    }

    /**
     * @return list<string>
     */
    private function requestHeaders(): array
    {
        $this->assertNotNull($this->capturedRequest, 'No request reached the transport.');

        return array_values($this->capturedRequest['options']['headers']);
    }

    /**
     * @param resource $stream
     */
    private function closeStream($stream): void
    {
        $this->assertIsResource($stream);

        fclose($stream);
    }
}
