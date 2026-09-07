<?php

declare(strict_types=1);

namespace App\Tests\Unit\Connector;

use App\Connector\ConnectorApiClient;
use ArrayObject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectorApiClientTest extends TestCase
{
    private const DOMAIN = 'portal.bitrix24.ru';
    private const TOKEN = 'super-secret-access-token-42';

    /** @var ArrayObject<int, array<string, mixed>> */
    private ArrayObject $logRecords;

    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $capturedRequests = [];

    protected function setUp(): void
    {
        $this->logRecords = new ArrayObject();
        $this->capturedRequests = [];
    }

    public function testFieldNamesAreReadFromTheTitleKeyOfEveryDescription(): void
    {
        $client = $this->createClient($this->answering([
            'result' => [
                'fields' => [
                    ['title' => 'title', 'type' => 'string'],
                    ['title' => 'description', 'type' => 'string'],
                    ['title' => 'sourceCode', 'type' => 'string'],
                ],
            ],
        ]));

        $this->assertSame(['title', 'description', 'sourceCode'], $client->getSupportedFieldNames());
    }

    public function testDescriptionsWithoutAReadableNameAreLeftOut(): void
    {
        $client = $this->createClient($this->answering([
            'result' => [
                'fields' => [
                    ['title' => 'title'],
                    ['type' => 'string'],
                    ['title' => ''],
                    ['title' => ['nested']],
                    'not a description at all',
                    ['title' => 'sourceCode'],
                ],
            ],
        ]));

        $this->assertSame(['title', 'sourceCode'], $client->getSupportedFieldNames());
    }

    /**
     * The portal answer is an untrusted input: a missing key, an unexpected type and an unreadable body all
     * end as "the set could not be read", never as an undefined state or a failure of the installation.
     *
     * @return list<array{0: string}>
     */
    public static function unreadableFieldSetProvider(): array
    {
        return [
            'no result at all' => ['{"time":{"start":1}}'],
            'result is not an array' => ['{"result":"ok"}'],
            'result carries no fields key' => ['{"result":{"total":0}}'],
            'fields is not an array' => ['{"result":{"fields":"sourceCode"}}'],
            'fields names nothing' => ['{"result":{"fields":[]}}'],
            'no description carries a name' => ['{"result":{"fields":[{"type":"string"}]}}'],
            'the portal refused the call' => ['{"error":"ACCESS_DENIED","error_description":"Access denied."}'],
            'the body is not json' => ['<html><body>502 Bad Gateway</body></html>'],
            'the body is empty' => [''],
        ];
    }

    #[DataProvider('unreadableFieldSetProvider')]
    public function testAnUnreadableAnswerReportsNoFieldSet(string $body): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse($body, ['http_code' => 200])));

        $this->assertNull($client->getSupportedFieldNames());
    }

    public function testAFailingStatusReportsNoFieldSet(): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse(
            '{"result":{"fields":[{"title":"sourceCode"}]}}',
            ['http_code' => 500]
        )));

        $this->assertNull($client->getSupportedFieldNames());
    }

    public function testATransportFailureReportsNoFieldSet(): void
    {
        $client = $this->createClient(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Could not resolve host: portal.bitrix24.ru');
        }));

        $this->assertNull($client->getSupportedFieldNames());
        $this->assertLoggedMessages(['ConnectorApiClient.call.transportFailure']);
    }

    public function testAFailureWhileReadingTheAnswerReportsNoFieldSet(): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse([
            '{"result":',
            new TransportException('Transfer closed with outstanding read data remaining'),
        ])));

        $this->assertNull($client->getSupportedFieldNames());
    }

    public function testConnectorsAreReadFromTheListAnswer(): void
    {
        $client = $this->createClient($this->answering([
            'result' => [
                ['id' => 1, 'title' => 'MySQL', 'sourceCode' => 'mysql'],
                'a value that is not a connector',
                ['id' => 2, 'title' => 'PostgreSQL', 'sourceCode' => 'pgsql'],
            ],
        ]));

        $this->assertSame(
            [
                ['id' => 1, 'title' => 'MySQL', 'sourceCode' => 'mysql'],
                ['id' => 2, 'title' => 'PostgreSQL', 'sourceCode' => 'pgsql'],
            ],
            $client->getConnectors()
        );
    }

    public function testAnUnreadableListAnswerReportsAnEmptyCatalogue(): void
    {
        $client = $this->createClient(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Connection timed out');
        }));

        $this->assertSame([], $client->getConnectors());
    }

    public function testEveryMethodTravelsToItsOwnAddress(): void
    {
        $client = $this->createClient($this->capturing());

        $client->getConnectors();
        $client->getSupportedFieldNames();
        $client->addConnector(['title' => 'MySQL']);
        $client->updateConnector(7, ['title' => 'MySQL']);

        $this->assertSame(
            [
                'https://portal.bitrix24.ru/rest/biconnector.connector.list',
                'https://portal.bitrix24.ru/rest/biconnector.connector.fields',
                'https://portal.bitrix24.ru/rest/biconnector.connector.add',
                'https://portal.bitrix24.ru/rest/biconnector.connector.update',
            ],
            array_column($this->capturedRequests, 'url')
        );
        $this->assertSame(['POST', 'POST', 'POST', 'POST'], array_column($this->capturedRequests, 'method'));
    }

    public function testTheDescriptionOfAConnectorTravelsUnderTheFieldsKey(): void
    {
        $client = $this->createClient($this->capturing());

        $client->addConnector([
            'title' => 'MySQL',
            'sourceCode' => 'mysql',
            'settings' => [['name' => 'Host', 'type' => 'STRING', 'code' => 'host']],
        ]);

        $this->assertSame([
            'title' => 'MySQL',
            'sourceCode' => 'mysql',
            'settings' => [['name' => 'Host', 'type' => 'STRING', 'code' => 'host']],
        ], $this->requestBody()['fields']);
    }

    public function testAnUpdateNamesTheConnectorItChanges(): void
    {
        $client = $this->createClient($this->capturing());

        $client->updateConnector(42, ['title' => 'MySQL']);

        $body = $this->requestBody();
        $this->assertSame('42', $body['id']);
        $this->assertSame(['title' => 'MySQL'], $body['fields']);
    }

    public function testTheTokenTravelsInTheBodyAndNeverInTheAddress(): void
    {
        $client = $this->createClient($this->capturing());

        $client->addConnector(['title' => 'MySQL']);

        $this->assertSame(self::TOKEN, $this->requestBody()['auth']);
        $this->assertStringNotContainsString(self::TOKEN, $this->capturedRequests[0]['url']);
    }

    public function testAnAnswerIsNotAllowedToRedirectTheTokenElsewhere(): void
    {
        $transport = new MockHttpClient(new MockResponse('', [
            'http_code' => 302,
            'response_headers' => ['Location' => 'https://elsewhere.example.com/rest/'],
        ]));

        $client = $this->createClient($transport);

        $this->assertNull($client->getSupportedFieldNames());
        $this->assertSame(1, $transport->getRequestsCount());
    }

    public function testTheRequestCarriesNoRedirectsAndAnExplicitTimeout(): void
    {
        $client = $this->createClient($this->capturing());

        $client->getConnectors();

        $options = $this->capturedRequests[0]['options'];
        $this->assertSame(0, $options['max_redirects']);
        $this->assertSame(30.0, $options['timeout']);
    }

    public function testTheTokenNeverReachesTheLog(): void
    {
        $client = $this->createClient(new MockHttpClient(static function (): MockResponse {
            throw new TransportException('Failed to connect to portal.bitrix24.ru port 443');
        }));

        $client->getConnectors();
        $client->addConnector(['title' => 'MySQL']);

        $this->assertNotSame('', $this->loggedText());
        $this->assertStringNotContainsString(self::TOKEN, $this->loggedText());
    }

    public function testTheAnswerOfThePortalNeverReachesTheLog(): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse(
            '{"result":{"fields":[{"title":"sourceCode"}]},"note":"CONFIDENTIAL-PAYLOAD"}',
            ['http_code' => 200]
        )));

        $client->getSupportedFieldNames();

        $this->assertNotSame('', $this->loggedText());
        $this->assertStringNotContainsString('CONFIDENTIAL-PAYLOAD', $this->loggedText());
        $this->assertStringNotContainsString('sourceCode', $this->loggedText());
    }

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function portalErrorProvider(): array
    {
        return [
            'the rest layer refused the call' => [
                '{"error":"VALIDATION_UNKNOWN_PARAMETERS","error_description":"Unknown parameters."}',
                'VALIDATION_UNKNOWN_PARAMETERS',
            ],
            'the method reported its own refusal' => [
                '{"result":{"error":{"error":"ACCESS_DENIED","error_description":"Access denied."}}}',
                'ACCESS_DENIED',
            ],
        ];
    }

    #[DataProvider('portalErrorProvider')]
    public function testTheErrorCodeOfThePortalIsKeptInTheLog(string $body, string $expectedCode): void
    {
        $client = $this->createClient(new MockHttpClient(new MockResponse($body, ['http_code' => 200])));

        $client->addConnector(['title' => 'MySQL']);

        $records = $this->logRecords->getArrayCopy();
        $this->assertCount(1, $records);
        $this->assertSame(ConnectorApiClient::METHOD_ADD, $records[0]['context']['method']);
        $this->assertSame($expectedCode, $records[0]['context']['error']);
        $this->assertArrayNotHasKey('response', $records[0]['context']);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function answering(array $answer): MockHttpClient
    {
        return new MockHttpClient(new MockResponse(
            (string)json_encode($answer, JSON_THROW_ON_ERROR),
            ['http_code' => 200]
        ));
    }

    private function capturing(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->capturedRequests[] = [
                'method' => $method,
                'url' => $url,
                'options' => $options,
            ];

            return new MockResponse('{"result":[]}', ['http_code' => 200]);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function requestBody(int $index = 0): array
    {
        $this->assertArrayHasKey($index, $this->capturedRequests, 'No request reached the transport.');

        // Symfony encodes an array body as an urlencoded form, the same shape the portal reads.
        parse_str((string)$this->capturedRequests[$index]['options']['body'], $body);

        return $body;
    }

    private function loggedText(): string
    {
        return (string)json_encode($this->logRecords->getArrayCopy());
    }

    /**
     * @param list<string> $expected
     */
    private function assertLoggedMessages(array $expected): void
    {
        $this->assertSame($expected, array_column($this->logRecords->getArrayCopy(), 'message'));
    }

    private function createClient(MockHttpClient $transport): ConnectorApiClient
    {
        return new ConnectorApiClient(self::DOMAIN, self::TOKEN, $this->createLogger(), $transport);
    }

    private function createLogger(): LoggerInterface
    {
        return new class ($this->logRecords) extends AbstractLogger {
            /**
             * @param ArrayObject<int, array<string, mixed>> $records
             */
            public function __construct(private ArrayObject $records)
            {
            }

            /**
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records->append([
                    'level' => (string)$level,
                    'message' => (string)$message,
                    'context' => $context,
                ]);
            }
        };
    }
}
