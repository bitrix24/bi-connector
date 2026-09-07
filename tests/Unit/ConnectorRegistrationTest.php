<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Application;
use App\Connector\ConnectorApiClient;
use App\DataSource\ConnectionType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ConnectorRegistrationTest extends TestCase
{
    /**
     * Field names every version of the module of the portal has ever accepted.
     *
     * @var list<string>
     */
    private const BASE_FIELD_NAMES = [
        'title',
        'logo',
        'description',
        'urlCheck',
        'urlTableList',
        'urlTableDescription',
        'urlData',
        'settings',
        'sort',
    ];

    private const PNG_DATA_URL_PREFIX = 'data:image/png;base64,';

    /**
     * What `getPngLogoBase64Data` falls back to when the file of a connector is missing: a single
     * transparent pixel the portal accepts as silently as a real logo.
     */
    private const MISSING_LOGO_PLACEHOLDER = self::PNG_DATA_URL_PREFIX
        . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR'
        . '42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * Settings of the application the registration reads from the environment.
     *
     * @var list<string>
     */
    private const ENVIRONMENT_VARIABLES = [
        'APP_DOMAIN',
        'MYSQL_CONNECTOR_TITLE',
        'MYSQL_CONNECTOR_DESCRIPTION',
        'POSTGRESQL_CONNECTOR_TITLE',
        'POSTGRESQL_CONNECTOR_DESCRIPTION',
        'CLICKHOUSE_CONNECTOR_TITLE',
        'CLICKHOUSE_CONNECTOR_DESCRIPTION',
        'LOG_LEVEL',
        'LOG_PATH',
    ];

    private ReflectionClass $appReflection;

    /** @var array<string, mixed> */
    private array $environmentBackup = [];

    /** @var list<array{method: string, fields: array<string, string|array<mixed>>, id: string|null}> */
    private array $catalogueChanges = [];

    protected function setUp(): void
    {
        $this->appReflection = new ReflectionClass(Application::class);
        $this->catalogueChanges = [];

        foreach (self::ENVIRONMENT_VARIABLES as $name) {
            $this->environmentBackup[$name] = $_ENV[$name] ?? null;
        }

        $_ENV['APP_DOMAIN'] = 'https://connector.example.com';
        $_ENV['MYSQL_CONNECTOR_TITLE'] = 'MySQL Connector';
        $_ENV['MYSQL_CONNECTOR_DESCRIPTION'] = 'MySQL Description';
        $_ENV['POSTGRESQL_CONNECTOR_TITLE'] = 'PostgreSQL Connector';
        $_ENV['POSTGRESQL_CONNECTOR_DESCRIPTION'] = 'PostgreSQL Description';
        $_ENV['CLICKHOUSE_CONNECTOR_TITLE'] = 'ClickHouse Connector';
        $_ENV['CLICKHOUSE_CONNECTOR_DESCRIPTION'] = 'ClickHouse Description';
        $_ENV['LOG_LEVEL'] = 'DEBUG';
        $_ENV['LOG_PATH'] = sys_get_temp_dir();
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

    public function testGetExistingConnectorIdReturnsCorrectId(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'Existing MySQL connector'
            ],
            [
                'id' => 2,
                'title' => 'Other Connector',
                'description' => 'Some other connector'
            ]
        ];

        // Test existing connector by title
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertEquals(1, $result, 'Should return ID 1 for existing MySQL connector by title');

        // Test non-existing connector by title
        $result = $method->invoke(null, $existingConnectors, 'PostgreSQL Database Connector', '');
        $this->assertNull($result, 'Should return null for non-existing PostgreSQL connector by title');

        // Test existing connector by description
        $result = $method->invoke(null, $existingConnectors, 'Non-existing Title', 'Existing MySQL connector');
        $this->assertEquals(1, $result, 'Should return ID 1 for existing MySQL connector by description');

        // Test non-existing connector by description
        $result = $method->invoke(null, $existingConnectors, 'Non-existing Title', 'Non-existing description');
        $this->assertNull($result, 'Should return null for non-existing connector by description');
    }

    public function testGetExistingConnectorIdWithEmptyList(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $result = $method->invoke(null, [], 'MySQL Database Connector', 'Some description');
        $this->assertNull($result, 'Should return null for empty connector list');
    }

    public function testGetExistingConnectorIdIsCaseSensitive(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'mysql database connector', '');
        $this->assertNull($result, 'Should be case sensitive for title');

        $result = $method->invoke(null, $existingConnectors, '', 'mysql description');
        $this->assertNull($result, 'Should be case sensitive for description');
    }

    private function getPrivateMethod(string $methodName): ReflectionMethod
    {
        $method = $this->appReflection->getMethod($methodName);
        $method->setAccessible(true);
        return $method;
    }

    public function testGetExistingConnectorIdHandlesMissingIdField(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
                // Missing 'id' field
            ]
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertNull($result, 'Should return null when id field is missing for title match');

        $result = $method->invoke(null, $existingConnectors, '', 'MySQL Description');
        $this->assertNull($result, 'Should return null when id field is missing for description match');
    }

    public function testGetExistingConnectorIdWithTitleOrDescriptionLogic(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'First MySQL Description'
            ],
            [
                'id' => 2,
                'title' => 'PostgreSQL Database Connector',
                'description' => 'Second PostgreSQL Description'
            ]
        ];

        // Test title match with different description
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'Different Description');
        $this->assertEquals(1, $result, 'Should find connector by title even with different description');

        // Test description match with different title
        $result = $method->invoke(null, $existingConnectors, 'Different Title', 'First MySQL Description');
        $this->assertEquals(1, $result, 'Should find connector by description even with different title');

        // Test both title and description match (should return first match by title)
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'First MySQL Description');
        $this->assertEquals(1, $result, 'Should find connector when both title and description match');
    }

    public function testGetExistingConnectorIdWithEmptyDescription(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector',
                'description' => 'MySQL Description'
            ]
        ];

        // Test with empty description - should only check by title
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', '');
        $this->assertEquals(1, $result, 'Should find connector by title when description is empty');

        // Test with non-matching title and empty description
        $result = $method->invoke(null, $existingConnectors, 'Non-matching Title', '');
        $this->assertNull($result, 'Should not find connector when title does not match and description is empty');
    }

    public function testGetExistingConnectorIdWithMissingTitleOrDescription(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            [
                'id' => 1,
                'title' => 'MySQL Database Connector'
                // Missing 'description' field
            ],
            [
                'id' => 2,
                'description' => 'PostgreSQL Description'
                // Missing 'title' field
            ]
        ];

        // Test with missing description field
        $result = $method->invoke(null, $existingConnectors, 'MySQL Database Connector', 'Some Description');
        $this->assertEquals(1, $result, 'Should find connector by title when description field is missing');

        // Test with missing title field
        $result = $method->invoke(null, $existingConnectors, 'Some Title', 'PostgreSQL Description');
        $this->assertEquals(2, $result, 'Should find connector by description when title field is missing');
    }

    public function testExistingConnectorIsFoundByItsSourceFamily(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            ['id' => 5, 'title' => 'A title chosen by the administrator', 'sourceCode' => 'mysql'],
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Connector', 'MySQL Description', 'mysql');
        $this->assertSame(5, $result, 'The source family identifies a connector whatever it is called');
    }

    public function testSourceFamilyIsPreferredOverAMatchingTitle(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            ['id' => 1, 'title' => 'MySQL Connector', 'sourceCode' => 'pgsql'],
            ['id' => 2, 'title' => 'Renamed by the administrator', 'sourceCode' => 'mysql'],
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Connector', '', 'mysql');
        $this->assertSame(2, $result, 'A matching source family wins over a matching title');
    }

    public function testSearchDegradesToTheTitleWhenTheSourceFamilyIsUnknown(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        // An older portal answers the catalogue without the field, so nothing carries a source family.
        $existingConnectors = [
            ['id' => 7, 'title' => 'MySQL Connector', 'description' => 'MySQL Description'],
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Connector', 'MySQL Description', 'mysql');
        $this->assertSame(7, $result, 'Without a source family the title still finds the connector');

        $result = $method->invoke(null, $existingConnectors, 'Another Title', 'MySQL Description', null);
        $this->assertSame(7, $result, 'The description still finds the connector when no family is asked for');
    }

    public function testAForeignSourceFamilyDoesNotClaimAConnector(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            ['id' => 3, 'title' => 'PostgreSQL Connector', 'sourceCode' => 'pgsql'],
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Connector', 'MySQL Description', 'mysql');
        $this->assertNull($result, 'A connector of another family is never reused');
    }

    public function testSourceFamilyEntryWithoutAnIdDoesNotStopTheSearch(): void
    {
        $method = $this->getPrivateMethod('getExistingConnectorId');

        $existingConnectors = [
            ['title' => 'Broken entry', 'sourceCode' => 'mysql'],
            ['id' => 9, 'title' => 'MySQL Connector', 'sourceCode' => 'mysql'],
        ];

        $result = $method->invoke(null, $existingConnectors, 'MySQL Connector', '', 'mysql');
        $this->assertSame(9, $result, 'An entry the portal reported without an id is passed over');
    }

    public function testFieldOutsideTheBaseSetTravelsWhenThePortalKnowsIt(): void
    {
        $method = $this->getPrivateMethod('selectFieldsKnownToPortal');

        $description = ['title' => 'MySQL Connector', 'sort' => 100, 'sourceCode' => 'mysql'];

        $result = $method->invoke(null, $description, ['title', 'sort', 'sourceCode']);
        $this->assertSame($description, $result);
    }

    public function testFieldOutsideTheBaseSetIsLeftOutWhenThePortalDoesNotKnowIt(): void
    {
        $method = $this->getPrivateMethod('selectFieldsKnownToPortal');

        $description = ['title' => 'MySQL Connector', 'sort' => 100, 'sourceCode' => 'mysql'];

        $result = $method->invoke(null, $description, ['title', 'sort']);
        $this->assertSame(['title' => 'MySQL Connector', 'sort' => 100], $result);
    }

    public function testAnUnreadableFieldSetLeavesTheBaseSetUntouched(): void
    {
        $method = $this->getPrivateMethod('selectFieldsKnownToPortal');

        $description = ['title' => 'MySQL Connector', 'sort' => 100, 'sourceCode' => 'mysql'];

        $result = $method->invoke(null, $description, null);
        $this->assertSame(['title' => 'MySQL Connector', 'sort' => 100], $result);
    }

    /**
     * Compatibility matrix: what the portal answers to `biconnector.connector.fields` decides what is sent,
     * and every half of it has to register every connector of the application.
     *
     * @return array<string, array{0: list<string>|string|null, 1: list<string>}>
     */
    public static function portalFieldSetProvider(): array
    {
        $newerPortal = [...self::BASE_FIELD_NAMES, 'sourceCode'];

        return [
            'the portal knows the source family' => [$newerPortal, $newerPortal],
            'the portal does not know the source family' => [self::BASE_FIELD_NAMES, self::BASE_FIELD_NAMES],
            'the field set request failed' => [null, self::BASE_FIELD_NAMES],
            'the answer carries no fields key' => ['{"result":{"total":0}}', self::BASE_FIELD_NAMES],
            'the answer did not parse' => ['<html><body>502 Bad Gateway</body></html>', self::BASE_FIELD_NAMES],
        ];
    }

    /**
     * @param list<string>|string|null $portalFields
     * @param list<string> $expectedFieldNames
     */
    #[DataProvider('portalFieldSetProvider')]
    public function testEveryConnectorIsRegisteredWhateverThePortalAccepts(
        array|string|null $portalFields,
        array $expectedFieldNames
    ): void {
        $this->runRegistration($portalFields);

        $this->assertSame(['add', 'add', 'add'], array_column($this->catalogueChanges, 'method'));
        $this->assertSame('MySQL Connector', $this->catalogueChanges[0]['fields']['title']);
        $this->assertSame('PostgreSQL Connector', $this->catalogueChanges[1]['fields']['title']);
        $this->assertSame('ClickHouse Connector', $this->catalogueChanges[2]['fields']['title']);

        foreach ($this->catalogueChanges as $change) {
            $this->assertSame($expectedFieldNames, array_keys($change['fields']));
        }
    }

    public function testTheSourceFamilySentFollowsTheConnectionType(): void
    {
        $this->runRegistration([...self::BASE_FIELD_NAMES, 'sourceCode']);

        $this->assertSame(
            ConnectionType::Mysql->sourceCode(),
            $this->catalogueChanges[0]['fields']['sourceCode']
        );
        $this->assertSame(
            ConnectionType::Postgresql->sourceCode(),
            $this->catalogueChanges[1]['fields']['sourceCode']
        );
        $this->assertSame(
            ConnectionType::Clickhouse->sourceCode(),
            $this->catalogueChanges[2]['fields']['sourceCode']
        );
    }

    public function testLeavingTheSourceFamilyOutChangesNoOtherField(): void
    {
        $this->runRegistration([...self::BASE_FIELD_NAMES, 'sourceCode']);
        $newerPortal = $this->catalogueChanges;

        $this->catalogueChanges = [];
        $this->runRegistration(self::BASE_FIELD_NAMES);
        $olderPortal = $this->catalogueChanges;

        foreach ([0, 1, 2] as $index) {
            $expected = $newerPortal[$index]['fields'];
            unset($expected['sourceCode']);

            $this->assertSame($expected, $olderPortal[$index]['fields']);
        }
    }

    /**
     * Replaces the reflection check of the former `updateConnectorViaAPI`: what has to hold is that a
     * connector the portal already holds is changed and not registered a second time.
     */
    public function testAConnectorThePortalAlreadyHoldsIsUpdatedAndNotDuplicated(): void
    {
        $this->runRegistration(
            [...self::BASE_FIELD_NAMES, 'sourceCode'],
            [
                ['id' => 11, 'title' => 'Renamed by the administrator', 'sourceCode' => 'mysql'],
                ['id' => 12, 'title' => 'PostgreSQL Connector', 'description' => 'PostgreSQL Description'],
                ['id' => 13, 'title' => 'ClickHouse Connector', 'sourceCode' => 'clickhouse'],
            ]
        );

        $this->assertSame(['update', 'update', 'update'], array_column($this->catalogueChanges, 'method'));
        $this->assertSame('11', $this->catalogueChanges[0]['id'], 'MySQL is found by its source family');
        $this->assertSame('12', $this->catalogueChanges[1]['id'], 'PostgreSQL falls back to its title');
        $this->assertSame('13', $this->catalogueChanges[2]['id'], 'ClickHouse is found by its source family');
    }

    public function testAnOlderPortalKeepsUpdatingTheConnectorsItAlreadyHolds(): void
    {
        $this->runRegistration(
            self::BASE_FIELD_NAMES,
            [
                ['id' => 21, 'title' => 'MySQL Connector', 'description' => 'MySQL Description'],
                ['id' => 22, 'title' => 'PostgreSQL Connector', 'description' => 'PostgreSQL Description'],
                ['id' => 23, 'title' => 'ClickHouse Connector', 'description' => 'ClickHouse Description'],
            ]
        );

        $this->assertSame(['update', 'update', 'update'], array_column($this->catalogueChanges, 'method'));
        $this->assertSame(['21', '22', '23'], array_column($this->catalogueChanges, 'id'));

        foreach ($this->catalogueChanges as $change) {
            $this->assertArrayNotHasKey('sourceCode', $change['fields']);
        }
    }

    /**
     * The catalogue of the application: one description per connection type, ordered by the sort value.
     *
     * @return array<string, array{0: int, 1: ConnectionType, 2: string, 3: string, 4: string}>
     */
    public static function connectorDescriptionProvider(): array
    {
        return [
            'MySQL' => [
                0,
                ConnectionType::Mysql,
                'mysql',
                'MySQL Database Connector',
                'Connector for MySQL databases with authentication',
            ],
            'PostgreSQL' => [
                1,
                ConnectionType::Postgresql,
                'postgresql',
                'PostgreSQL Database Connector',
                'Connector for PostgreSQL databases with authentication',
            ],
            'ClickHouse' => [
                2,
                ConnectionType::Clickhouse,
                'clickhouse',
                'ClickHouse Database Connector',
                'Connector for ClickHouse databases with authentication',
            ],
        ];
    }

    #[DataProvider('connectorDescriptionProvider')]
    public function testEveryConnectorPointsItsFourActionsAtItsOwnConnectionType(
        int $index,
        ConnectionType $connectionType,
        string $requestValue
    ): void {
        $description = $this->buildConnectorDescriptions()[$index];

        $expectedUrls = [
            'urlCheck' => 'check',
            'urlTableList' => 'table_list',
            'urlTableDescription' => 'table_description',
            'urlData' => 'data',
        ];

        foreach ($expectedUrls as $field => $action) {
            $this->assertSame(
                sprintf('https://connector.example.com/?connection_type=%s&action=%s', $requestValue, $action),
                $description[$field]
            );
        }
    }

    #[DataProvider('connectorDescriptionProvider')]
    public function testEveryConnectorCarriesItsSourceFamily(int $index, ConnectionType $connectionType): void
    {
        $description = $this->buildConnectorDescriptions()[$index];

        $this->assertSame($connectionType->sourceCode(), $description['sourceCode']);
    }

    /**
     * A missing logo file is no error: the placeholder of a single transparent pixel takes its place and
     * the portal accepts it, so what the description carries has to be checked instead.
     */
    #[DataProvider('connectorDescriptionProvider')]
    public function testEveryConnectorCarriesALogoOfItsOwn(int $index): void
    {
        $description = $this->buildConnectorDescriptions()[$index];

        $this->assertStringStartsWith(self::PNG_DATA_URL_PREFIX, $description['logo']);
        $this->assertNotSame(
            self::MISSING_LOGO_PLACEHOLDER,
            $description['logo'],
            'The logo file of the connector is missing from public/assets/img'
        );
    }

    public function testTheConnectorsDoNotShareALogo(): void
    {
        $logos = array_column($this->buildConnectorDescriptions(), 'logo');

        $this->assertCount(3, array_unique($logos), 'Every connector is shown with its own logo');
    }

    #[DataProvider('connectorDescriptionProvider')]
    public function testTheTitleAndTheDescriptionComeFromTheEnvironment(
        int $index,
        ConnectionType $connectionType,
        string $requestValue
    ): void {
        $_ENV[strtoupper($requestValue) . '_CONNECTOR_TITLE'] = 'Title chosen by the administrator';
        $_ENV[strtoupper($requestValue) . '_CONNECTOR_DESCRIPTION'] = 'Description chosen by the administrator';

        $description = $this->buildConnectorDescriptions()[$index];

        $this->assertSame('Title chosen by the administrator', $description['title']);
        $this->assertSame('Description chosen by the administrator', $description['description']);
    }

    #[DataProvider('connectorDescriptionProvider')]
    public function testTheTitleAndTheDescriptionFallBackToTheirDefaults(
        int $index,
        ConnectionType $connectionType,
        string $requestValue,
        string $defaultTitle,
        string $defaultDescription
    ): void {
        unset(
            $_ENV[strtoupper($requestValue) . '_CONNECTOR_TITLE'],
            $_ENV[strtoupper($requestValue) . '_CONNECTOR_DESCRIPTION']
        );

        $description = $this->buildConnectorDescriptions()[$index];

        $this->assertSame($defaultTitle, $description['title']);
        $this->assertSame($defaultDescription, $description['description']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildConnectorDescriptions(): array
    {
        /** @var list<array<string, mixed>> $descriptions */
        $descriptions = $this->getPrivateMethod('buildConnectorDescriptions')->invoke(null);

        return $descriptions;
    }

    /**
     * Runs the whole registration against a portal that answers as described, without touching the network.
     *
     * @param list<string>|string|null $portalFields names the portal accepts, a raw answer body, or null
     *        for a portal that cannot be reached at all
     * @param list<array<string, mixed>> $existingConnectors catalogue the portal already holds
     */
    private function runRegistration(array|string|null $portalFields, array $existingConnectors = []): void
    {
        $transport = new MockHttpClient(
            function (
                string $method,
                string $url,
                array $options
            ) use (
                $portalFields,
                $existingConnectors
            ): MockResponse {
                if (str_ends_with($url, ConnectorApiClient::METHOD_LIST)) {
                    return $this->answering(['result' => $existingConnectors]);
                }

                if (str_ends_with($url, ConnectorApiClient::METHOD_FIELDS)) {
                    return $this->answeringFieldSet($portalFields);
                }

                parse_str((string)$options['body'], $body);

                $this->catalogueChanges[] = [
                    'method' => str_ends_with($url, ConnectorApiClient::METHOD_ADD) ? 'add' : 'update',
                    'fields' => $body['fields'] ?? [],
                    'id' => $body['id'] ?? null,
                ];

                return $this->answering(['result' => ['id' => 100]]);
            }
        );

        $client = new ConnectorApiClient('portal.bitrix24.ru', 'test-token', new NullLogger(), $transport);

        $this->getPrivateMethod('registerConnectors')->invoke(null, $client);
    }

    /**
     * @param list<string>|string|null $portalFields
     */
    private function answeringFieldSet(array|string|null $portalFields): MockResponse
    {
        if ($portalFields === null) {
            throw new TransportException('Could not resolve host: portal.bitrix24.ru');
        }

        if (is_string($portalFields)) {
            return new MockResponse($portalFields, ['http_code' => 200]);
        }

        return $this->answering([
            'result' => [
                'fields' => array_map(
                    static fn(string $name): array => ['title' => $name, 'type' => 'string'],
                    $portalFields
                ),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function answering(array $answer): MockResponse
    {
        return new MockResponse((string)json_encode($answer, JSON_THROW_ON_ERROR), ['http_code' => 200]);
    }
}
