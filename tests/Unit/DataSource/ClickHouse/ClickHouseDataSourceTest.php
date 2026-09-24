<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataSource\ClickHouse;

use App\DataSource\ClickHouse\ClickHouseDataSource;
use App\DataSource\ClickHouse\ClickHouseHttpClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ClickHouseDataSourceTest extends TestCase
{
    private const HEADER_ONLY_BODY = '["one"]' . "\n" . '["UInt8"]' . "\n" . '[1]' . "\n";

    /** @var array<string, mixed>|null */
    private ?array $capturedRequest = null;

    private mixed $timeoutBackup = null;

    protected function setUp(): void
    {
        $this->capturedRequest = null;
        $this->timeoutBackup = $_ENV['CLICKHOUSE_CHECK_TIMEOUT_SECONDS'] ?? null;
        unset($_ENV['CLICKHOUSE_CHECK_TIMEOUT_SECONDS']);
    }

    protected function tearDown(): void
    {
        if ($this->timeoutBackup === null) {
            unset($_ENV['CLICKHOUSE_CHECK_TIMEOUT_SECONDS']);

            return;
        }

        $_ENV['CLICKHOUSE_CHECK_TIMEOUT_SECONDS'] = $this->timeoutBackup;
    }

    public function testTheCheckRunsUnderTheShortTimeBudget(): void
    {
        // The pool of worker threads is fixed, so a handful of checks against an address that drops packets
        // must not be able to hold the whole application.
        $this->createDataSource()->check();

        $options = $this->capturedRequest['options'];
        $this->assertSame(10.0, $options['timeout']);
        $this->assertSame(10.0, $options['max_duration']);
    }

    public function testTheCatalogueKeepsTheLongTimeBudget(): void
    {
        $this->createDataSource()->listTables('');

        $options = $this->capturedRequest['options'];
        $this->assertSame(300.0, $options['timeout']);
        $this->assertSame(600.0, $options['max_duration']);
    }

    private function createDataSource(): ClickHouseDataSource
    {
        $transport = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedRequest = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse(self::HEADER_ONLY_BODY, ['http_code' => 200]);
        });

        $connectionParams = [
            'host' => 'ch.example.com',
            'database' => 'test_db',
            'username' => 'test_user',
            'password' => 'test_pass',
        ];

        return new ClickHouseDataSource(
            $connectionParams,
            new NullLogger(),
            new ClickHouseHttpClient($connectionParams, new NullLogger(), $transport)
        );
    }
}
