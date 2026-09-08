<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

use App\DataSource\RowLimit;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\Response\StreamWrapper;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Transport over the ClickHouse HTTP interface.
 *
 * The class owns the endpoint, the credentials and the read boundaries of a statement. Reading the answer
 * belongs to the caller: a successful request hands over the body as a stream, never as a collected string.
 */
final class ClickHouseHttpClient
{
    public const DEFAULT_PORT = 8123;
    public const DEFAULT_SECURE_PORT = 8443;

    private const RESPONSE_FORMAT = 'JSONCompactEachRowWithNamesAndTypes';

    // Level 2 forbids writes and schema changes on the server side while still accepting settings,
    // which level 1 does not.
    private const READONLY_LEVEL = 2;

    // Crossing any of the read boundaries has to fail the statement instead of truncating its answer.
    private const OVERFLOW_MODE = 'throw';

    /**
     * The transport is given both of its time boundaries explicitly; no default of the transport is relied
     * upon. Symfony offers the inactivity ceiling and the ceiling for the whole exchange, and no separate
     * connection timeout: the inactivity ceiling covers the connection phase as well. It outlasts
     * `max_execution_time` on purpose, because ClickHouse answers only once the first block of the result
     * is ready and the exchange stays silent while the statement runs.
     */
    private const IDLE_TIMEOUT_SECONDS = 300;
    private const MAX_DURATION_SECONDS = 600;

    /**
     * The availability check gets a budget of its own. The thread pool of the runtime is fixed, so a handful
     * of requests to an address that drops packets makes the whole application unreachable; and the check is
     * exactly the action a wrong address is entered against. Telling whether an address answers at all does
     * not need the budget of a statement that reads a report.
     */
    private const DEFAULT_CHECK_TIMEOUT_SECONDS = 10;

    private const ERROR_MESSAGE_MAX_LENGTH = 4096;

    // ClickHouse marks every failed answer with the numeric code of its exception. Symfony hands the names
    // of the headers over in lower case.
    private const FAILURE_CODE_HEADER = 'x-clickhouse-exception-code';

    private const DEFAULT_MAX_ROWS_TO_READ = 100000000;
    private const DEFAULT_MAX_EXECUTION_TIME = 60;

    /** @var array<string, mixed> */
    private array $connectionParams;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;
    private RowLimit $rowLimit;
    private int $maxRowsToRead;
    private int $maxExecutionTime;
    private int $checkTimeout;

    /**
     * @param array<string, mixed> $connectionParams host, database, username and password of the source
     * @param HttpClientInterface|null $httpClient transport to send through; the default one is built here
     */
    public function __construct(
        array $connectionParams,
        LoggerInterface $logger,
        ?HttpClientInterface $httpClient = null
    ) {
        $this->connectionParams = $connectionParams;
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? HttpClient::create();

        $this->rowLimit = RowLimit::fromEnvironment();
        $this->maxRowsToRead = self::readBoundaryFromEnvironment(
            'CLICKHOUSE_MAX_ROWS_TO_READ',
            self::DEFAULT_MAX_ROWS_TO_READ
        );
        $this->maxExecutionTime = self::readBoundaryFromEnvironment(
            'CLICKHOUSE_MAX_EXECUTION_TIME',
            self::DEFAULT_MAX_EXECUTION_TIME
        );
        $this->checkTimeout = self::readBoundaryFromEnvironment(
            'CLICKHOUSE_CHECK_TIMEOUT_SECONDS',
            self::DEFAULT_CHECK_TIMEOUT_SECONDS
        );

        $this->logger->debug('ClickHouseHttpClient.__construct', [
            'class' => self::class,
            'method' => '__construct',
            'connectionParams' => array_keys($connectionParams),
            'maxResultRows' => $this->rowLimit->getMaximum(),
            'maxRowsToRead' => $this->maxRowsToRead,
            'maxExecutionTime' => $this->maxExecutionTime,
            'checkTimeout' => $this->checkTimeout,
        ]);
    }

    /**
     * The row limit actually applied to a statement: the caller asks for one, the application caps it.
     *
     * The value is public because the same number belongs both to `max_result_rows` and to the `LIMIT`
     * clause of the statement; a `LIMIT` above the cap would fail the statement instead of shortening it.
     */
    public function resolveRowLimit(int $requestedLimit): int
    {
        return $this->rowLimit->resolve($requestedLimit);
    }

    /**
     * Sends a statement and hands the body of the answer over as a stream.
     *
     * @param int|null $rowLimit row limit of the statement; the application cap applies when it is omitted
     *
     * @return resource body of the answer; the caller closes it
     *
     * @throws \RuntimeException when the source answers with a redirect or with any other non successful status
     */
    public function query(string $sql, ?int $rowLimit = null)
    {
        return $this->send($sql, $rowLimit ?? 0, self::IDLE_TIMEOUT_SECONDS, self::MAX_DURATION_SECONDS);
    }

    /**
     * Sends the statement of an availability check under the short budget of that action.
     *
     * @return resource body of the answer; the caller closes it
     *
     * @throws \RuntimeException when the source answers with a redirect or with any other non successful status
     */
    public function queryAvailability(string $sql)
    {
        return $this->send($sql, 0, $this->checkTimeout, $this->checkTimeout);
    }

    /**
     * @return resource
     *
     * @throws \RuntimeException
     */
    private function send(string $sql, int $rowLimit, int $idleTimeout, int $maxDuration)
    {
        $maxResultRows = $this->resolveRowLimit($rowLimit);
        $baseUrl = $this->buildBaseUrl();

        $this->logger->debug('ClickHouseHttpClient.query.start', [
            'class' => self::class,
            'method' => 'query',
            'endpoint' => $baseUrl,
            'maxResultRows' => $maxResultRows,
            'idleTimeout' => $idleTimeout,
            'maxDuration' => $maxDuration,
        ]);

        $response = $this->httpClient->request('POST', $baseUrl . '/?' . http_build_query(
            $this->buildQueryParameters($maxResultRows)
        ), [
            'headers' => $this->buildHeaders(),
            'body' => $sql,
            // A redirect is not followed: the source must not be able to move the statement, the
            // credentials and the answer to another address.
            'max_redirects' => 0,
            'timeout' => $idleTimeout,
            'max_duration' => $maxDuration,
            // The body is read from the wire by the caller and is never collected in memory here.
            'buffer' => false,
        ]);

        $statusCode = $response->getStatusCode();

        if ($statusCode >= 300 && $statusCode < 400) {
            $response->cancel();

            throw new \RuntimeException(sprintf(
                'ClickHouse answered with redirect status %d, which is not followed.',
                $statusCode
            ));
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \RuntimeException($this->readFailureReason($response, $statusCode));
        }

        $this->logger->debug('ClickHouseHttpClient.query.success', [
            'class' => self::class,
            'method' => 'query',
            'endpoint' => $baseUrl,
            'statusCode' => $statusCode,
        ]);

        return StreamWrapper::createResource($response, $this->httpClient);
    }

    /**
     * The connection form asks for a single address string, so the host setting may carry a scheme and a
     * port. Neither the user name nor the password ever reaches the address: both travel as headers.
     */
    private function buildBaseUrl(): string
    {
        $host = trim((string)($this->connectionParams['host'] ?? ''));

        if (!preg_match('#^https?://#i', $host)) {
            $host = 'http://' . $host;
        }

        $parts = parse_url($host);
        $hostName = is_array($parts) ? ($parts['host'] ?? '') : '';

        if ($hostName === '') {
            throw new \InvalidArgumentException('ClickHouse connection has no readable host.');
        }

        $scheme = strtolower(is_array($parts) ? ($parts['scheme'] ?? 'http') : 'http');
        $port = is_array($parts) ? (int)($parts['port'] ?? 0) : 0;

        if ($port <= 0) {
            $port = $scheme === 'https' ? self::DEFAULT_SECURE_PORT : self::DEFAULT_PORT;
        }

        return $scheme . '://' . $hostName . ':' . $port;
    }

    /**
     * Every statement carries the full set of parameters, statements against the system catalogue included.
     *
     * @return array<string, int|string>
     */
    private function buildQueryParameters(int $maxResultRows): array
    {
        return [
            'default_format' => self::RESPONSE_FORMAT,
            'readonly' => self::READONLY_LEVEL,
            // A JSON number is a double once PHP has parsed it, and a double holds 15 significant digits.
            // Unquoted, an Int64 past 2^53, a Decimal(38, 4) and a UInt64 all arrive already rounded.
            'output_format_json_quote_64bit_integers' => 1,
            'output_format_json_quote_decimals' => 1,
            // Without this the server prints NaN and Infinity as `null`, which is a different answer:
            // `null` means the group was empty, NaN means it was not.
            'output_format_json_quote_denormals' => 1,
            'max_result_rows' => $maxResultRows,
            'result_overflow_mode' => self::OVERFLOW_MODE,
            'max_rows_to_read' => $this->maxRowsToRead,
            'read_overflow_mode' => self::OVERFLOW_MODE,
            'max_execution_time' => $this->maxExecutionTime,
            'timeout_overflow_mode' => self::OVERFLOW_MODE,
            'database' => trim((string)($this->connectionParams['database'] ?? '')),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = ['Content-Type' => 'text/plain; charset=utf-8'];

        $username = (string)($this->connectionParams['username'] ?? '');

        if ($username !== '') {
            // Credentials go into headers and not into the query string, so that they do not end up in
            // the access log of the source.
            $headers['X-ClickHouse-User'] = $username;
            $headers['X-ClickHouse-Key'] = (string)($this->connectionParams['password'] ?? '');
        }

        return $headers;
    }

    /**
     * The reason a failed answer is reported with.
     *
     * A failed statement answers with the display text of the failure, and that text is handed back as it
     * is. Any other body belongs to a service that is not ClickHouse: the address of the source is chosen
     * by the caller, so such a body stays inside the application, reaches the log alone and the caller is
     * told the status of the answer.
     *
     * Two things have to hold before a body is read as a failure. The answer carries the header ClickHouse
     * marks a failure with, which a service that merely reflects what was sent to it does not write; and
     * the display text stands at the beginning of a line of that body, so a text that travelled out inside
     * the request and came back inside the body cannot pass for the reason.
     */
    private function readFailureReason(ResponseInterface $response, int $statusCode): string
    {
        $carriesFailureCode = $this->carriesFailureCode($response);
        $body = $this->readBody($response);
        $failureText = $carriesFailureCode ? ClickHouseQueryException::readFailureTextFromBody($body) : null;

        if ($failureText !== null) {
            return sprintf('ClickHouse answered with HTTP status %d: %s', $statusCode, $failureText);
        }

        $this->logger->error('ClickHouseHttpClient.query.failed', [
            'class' => self::class,
            'method' => 'query',
            'statusCode' => $statusCode,
            'body' => $body,
        ]);

        return sprintf('ClickHouse answered with HTTP status %d.', $statusCode);
    }

    /**
     * Whether the answer carries the failure code of ClickHouse.
     *
     * Confirmed against 24.8: the header is on every failed answer, 400, 403, 404 and 500 alike, no matter
     * whether the body carries the display text alone or the opening lines of the output format before it.
     */
    private function carriesFailureCode(ResponseInterface $response): bool
    {
        $code = $response->getHeaders(false)[self::FAILURE_CODE_HEADER][0] ?? '';

        return preg_match('/^\d+$/', $code) === 1;
    }

    private function readBody(ResponseInterface $response): string
    {
        $stream = StreamWrapper::createResource($response, $this->httpClient);
        $body = trim((string)stream_get_contents($stream, self::ERROR_MESSAGE_MAX_LENGTH));
        fclose($stream);

        return $body;
    }

    /**
     * A boundary is never left unbounded: a missing, unreadable or non positive setting falls back to the
     * default of the application.
     */
    private static function readBoundaryFromEnvironment(string $name, int $default): int
    {
        $value = (int)($_ENV[$name] ?? $default);

        return $value > 0 ? $value : $default;
    }
}
