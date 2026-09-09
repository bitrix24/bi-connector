<?php

declare(strict_types=1);

namespace App;

use App\Connector\ConnectorApiClient;
use App\DataSource\ConnectionType;
use App\Log\SecretMaskingProcessor;
use Bitrix24\SDK\Application\Local\Entity\LocalAppAuth;
use Bitrix24\SDK\Application\Local\Infrastructure\Filesystem\AppAuthFileStorage;
use Bitrix24\SDK\Application\Local\Repository\LocalAppAuthRepositoryInterface;
use Bitrix24\SDK\Application\Requests\Events\OnApplicationInstall\OnApplicationInstall;
use Bitrix24\SDK\Services\RemoteEventsFactory;
use Bitrix24\SDK\Core\Credentials\ApplicationProfile;
use Bitrix24\SDK\Core\Exceptions\InvalidArgumentException;
use Bitrix24\SDK\Core\Exceptions\UnknownScopeCodeException;
use Bitrix24\SDK\Core\Exceptions\WrongConfigurationException;
use Bitrix24\SDK\Events\AuthTokenRenewedEvent;
use Bitrix24\SDK\Services\ServiceBuilder;
use Bitrix24\SDK\Services\ServiceBuilderFactory;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger;
use Monolog\Processor\MemoryUsageProcessor;
use Monolog\Processor\UidProcessor;
use Psr\Log\LoggerInterface;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Throwable;

/**
 * @phpstan-type ConnectorSetting array{name: string, type: string, code: string}
 * @phpstan-type ConnectorDescription array{
 *     title: string,
 *     logo: string,
 *     description: string,
 *     urlCheck: string,
 *     urlTableList: string,
 *     urlTableDescription: string,
 *     urlData: string,
 *     settings: list<ConnectorSetting>,
 *     sort: int,
 *     sourceCode: string,
 * }
 */
class Application
{
    private const CONFIG_FILE_NAME = '/.env';
    private const LOG_FILE_NAME = '/application.log';

    /**
     * Names a portal only accepts once its module is new enough. Everything outside this list belongs to
     * the set every version of the module has ever accepted.
     *
     * @var list<string>
     */
    private const FIELDS_OUTSIDE_BASE_SET = ['sourceCode'];

    /**
     * Settings every connector of the application asks the user for.
     *
     * @var list<ConnectorSetting>
     */
    private const CONNECTION_SETTINGS = [
        ['name' => 'Host', 'type' => 'STRING', 'code' => 'host'],
        ['name' => 'Port', 'type' => 'STRING', 'code' => 'port'],
        ['name' => 'Database', 'type' => 'STRING', 'code' => 'database'],
        ['name' => 'Username', 'type' => 'STRING', 'code' => 'username'],
        ['name' => 'Password', 'type' => 'STRING', 'code' => 'password'],
    ];

    /**
     * Processes the installation request from Bitrix24.
     *
     * @param Request $incomingRequest The incoming installation request
     *
     * @return Response The response to send back to Bitrix24
     */
    public static function processInstallation(Request $incomingRequest): Response
    {
        self::getLog()->debug('Application.processInstallation.start', [
            'request' => $incomingRequest->request->all(),
            'baseUrl' => $incomingRequest->getBaseUrl(),
        ]);

        try {
            $b24Event = RemoteEventsFactory::init(self::getLog())->createEvent($incomingRequest, null);

            self::getLog()->debug('Application.processInstallation.eventRequest', [
                'eventClassName' => $b24Event::class,
                'eventCode' => $b24Event->getEventCode(),
                'eventPayload' => $b24Event->getEventPayload(),
            ]);

            if (!$b24Event instanceof OnApplicationInstall) {
                throw new InvalidArgumentException(
                    'Installation controller can process only install events from Bitrix24'
                );
            }

            // Save admin auth token without application_token key
            self::getAuthRepository()->save(
                new LocalAppAuth(
                    $b24Event->getAuth()->authToken,
                    $b24Event->getAuth()->domain,
                    $b24Event->getAuth()->application_token
                )
            );

            // Register connectors using direct API calls
            self::registerConnectorsDirectly($b24Event->getAuth());

            $response = new Response('OK', 200);

            self::getLog()->info('Application.processInstallation.finish', [
                'response' => $response->getContent(),
                'statusCode' => $response->getStatusCode(),
            ]);

            return $response;
        } catch (Throwable $throwable) {
            self::getLog()->error('Application.processInstallation.error', [
                'message' => $throwable->getMessage(),
                'trace' => $throwable->getTraceAsString(),
            ]);

            return new Response(
                sprintf('Error on installation processing: %s', $throwable->getMessage()),
                500
            );
        }
    }

    /**
     * Brings the connector catalogue of the portal in line with the descriptions of the application.
     */
    private static function registerConnectorsDirectly(mixed $auth): void
    {
        self::getLog()->debug('Application.registerConnectorsDirectly.start');

        // Load configuration first
        self::loadConfigFromEnvFile();

        $accessToken = $auth->authToken->accessToken;
        $domain = $auth->domain;

        self::registerConnectors(new ConnectorApiClient($domain, $accessToken, self::getLog()));
    }

    /**
     * Registers or updates every connector of the application through the given client.
     *
     * The composition of a description belongs here; the exchange with the portal belongs to the client.
     */
    private static function registerConnectors(ConnectorApiClient $apiClient): void
    {
        $connectorsToRegister = self::buildConnectorDescriptions();
        $existingConnectors = $apiClient->getConnectors();

        // A portal that did not answer with its catalogue tells nothing about what it already holds.
        // Registering against that silence adds every connector a second time, so the catalogue is left
        // untouched instead and the installation is repeated once the portal answers again.
        if ($existingConnectors === null) {
            self::getLog()->error('Application.registerConnectors.catalogueUnavailable', [
                'method' => ConnectorApiClient::METHOD_LIST,
                'connectorsToRegister' => count($connectorsToRegister),
            ]);

            return;
        }

        // A field the portal does not know fails the whole call and would take the connectors that do
        // work down with it, so the accepted set is read before anything is sent.
        $portalFieldNames = $apiClient->getSupportedFieldNames();

        self::logFieldSetInUse($connectorsToRegister, $portalFieldNames);

        foreach ($connectorsToRegister as $connectorData) {
            $fields = self::selectFieldsKnownToPortal($connectorData, $portalFieldNames);
            $existingConnectorId = self::getExistingConnectorId(
                $existingConnectors,
                $connectorData['title'],
                $connectorData['description'],
                $connectorData['sourceCode']
            );

            if ($existingConnectorId === null) {
                $apiClient->addConnector($fields);
            } else {
                $apiClient->updateConnector($existingConnectorId, $fields);
            }
        }
    }

    /**
     * Descriptions of every connector the application offers.
     *
     * @return list<ConnectorDescription>
     */
    private static function buildConnectorDescriptions(): array
    {
        $appDomain = self::readEnvString('APP_DOMAIN', 'https://localhost');

        $appDir = realpath(__DIR__ . '/..');
        $mySqlLogoPublicPath = '/assets/img/logo_mysql.png';
        $pgSqlLogoPublicPath = '/assets/img/logo_pgsql.png';
        $clickHouseLogoPublicPath = '/assets/img/logo_clickhouse.png';
        $mySqlLogoAbsPath = $appDir . '/public' . $mySqlLogoPublicPath;
        $pgSqlLogoAbsPath = $appDir . '/public' . $pgSqlLogoPublicPath;
        $clickHouseLogoAbsPath = $appDir . '/public' . $clickHouseLogoPublicPath;

        $mysqlConnectorTitle = 'MySQL Database Connector';
        $postgresqlConnectorTitle = 'PostgreSQL Database Connector';
        $clickhouseConnectorTitle = 'ClickHouse Database Connector';
        $mysqlConnectorDefaultDescription = 'Connector for MySQL databases with authentication';
        $postgresqlConnectorDescription = 'Connector for PostgreSQL databases with authentication';
        $clickhouseConnectorDescription = 'Connector for ClickHouse databases with authentication';

        return [
            [
                'title' => self::readEnvString('MYSQL_CONNECTOR_TITLE', $mysqlConnectorTitle),
                'logo' => self::getPngLogoBase64Data($mySqlLogoAbsPath),
                'description' => self::readEnvString(
                    'MYSQL_CONNECTOR_DESCRIPTION',
                    $mysqlConnectorDefaultDescription
                ),
                'urlCheck' => $appDomain . '/?connection_type=mysql&action=check',
                'urlTableList' => $appDomain . '/?connection_type=mysql&action=table_list',
                'urlTableDescription' => $appDomain
                    . '/?connection_type=mysql&action=table_description',
                'urlData' => $appDomain . '/?connection_type=mysql&action=data',
                'settings' => self::CONNECTION_SETTINGS,
                'sort' => 100,
                'sourceCode' => ConnectionType::Mysql->sourceCode(),
            ],
            [
                'title' => self::readEnvString('POSTGRESQL_CONNECTOR_TITLE', $postgresqlConnectorTitle),
                'logo' => self::getPngLogoBase64Data($pgSqlLogoAbsPath),
                'description' => self::readEnvString(
                    'POSTGRESQL_CONNECTOR_DESCRIPTION',
                    $postgresqlConnectorDescription
                ),
                'urlCheck' => $appDomain . '/?connection_type=postgresql&action=check',
                'urlTableList' => $appDomain . '/?connection_type=postgresql&action=table_list',
                'urlTableDescription' => $appDomain
                    . '/?connection_type=postgresql&action=table_description',
                'urlData' => $appDomain . '/?connection_type=postgresql&action=data',
                'settings' => self::CONNECTION_SETTINGS,
                'sort' => 200,
                'sourceCode' => ConnectionType::Postgresql->sourceCode(),
            ],
            [
                'title' => self::readEnvString('CLICKHOUSE_CONNECTOR_TITLE', $clickhouseConnectorTitle),
                'logo' => self::getPngLogoBase64Data($clickHouseLogoAbsPath),
                'description' => self::readEnvString(
                    'CLICKHOUSE_CONNECTOR_DESCRIPTION',
                    $clickhouseConnectorDescription
                ),
                'urlCheck' => $appDomain . '/?connection_type=clickhouse&action=check',
                'urlTableList' => $appDomain . '/?connection_type=clickhouse&action=table_list',
                'urlTableDescription' => $appDomain
                    . '/?connection_type=clickhouse&action=table_description',
                'urlData' => $appDomain . '/?connection_type=clickhouse&action=data',
                'settings' => self::CONNECTION_SETTINGS,
                'sort' => 300,
                'sourceCode' => ConnectionType::Clickhouse->sourceCode(),
            ],
        ];
    }

    /**
     * Narrows a connector description down to the field names the portal accepts.
     *
     * @param array<string, mixed> $connectorData
     * @param list<string>|null $portalFieldNames names the portal knows, null when they could not be read
     *
     * @return array<string, mixed>
     */
    private static function selectFieldsKnownToPortal(array $connectorData, ?array $portalFieldNames): array
    {
        if ($portalFieldNames === null) {
            // Nothing is known about the portal, so only the set every version has accepted goes out.
            return array_diff_key($connectorData, array_flip(self::FIELDS_OUTSIDE_BASE_SET));
        }

        return array_intersect_key($connectorData, array_flip($portalFieldNames));
    }

    /**
     * Records which half of the compatibility matrix the installation went through.
     *
     * @param list<ConnectorDescription> $connectorsToRegister
     * @param list<string>|null $portalFieldNames
     */
    private static function logFieldSetInUse(array $connectorsToRegister, ?array $portalFieldNames): void
    {
        // Every description carries the same field names, so one of them shows what is actually sent.
        $sample = $connectorsToRegister[0] ?? [];
        $sentFields = array_keys(self::selectFieldsKnownToPortal($sample, $portalFieldNames));

        if ($portalFieldNames === null) {
            self::getLog()->info('Application.registerConnectors.portalFieldSetDegraded', [
                'reason' => 'fieldSetUnavailable',
                'sentFields' => $sentFields,
            ]);

            return;
        }

        foreach (self::FIELDS_OUTSIDE_BASE_SET as $fieldName) {
            if (!in_array($fieldName, $portalFieldNames, true)) {
                self::getLog()->info('Application.registerConnectors.portalFieldSetDegraded', [
                    'reason' => 'fieldNotSupportedByPortal',
                    'fieldName' => $fieldName,
                    'sentFields' => $sentFields,
                ]);

                return;
            }
        }

        self::getLog()->info('Application.registerConnectors.portalFieldSet', [
            'sentFields' => $sentFields,
        ]);
    }

    /**
     * Reads an overridable setting of the application from the environment.
     */
    private static function readEnvString(string $name, string $default): string
    {
        $value = $_ENV[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    /**
     * Finds the connector the portal already holds for this description.
     *
     * The source family identifies a connector on its own, but only a portal new enough to know the field
     * reports it. The title and the description stay in use for the older ones, and because the title is
     * overridden by an environment variable, matching on it alone would create duplicates.
     *
     * @param list<array<string, mixed>> $existingConnectors
     */
    private static function getExistingConnectorId(
        array $existingConnectors,
        string $title,
        string $description = '',
        ?string $sourceCode = null
    ): ?int {
        if ($sourceCode !== null && $sourceCode !== '') {
            foreach ($existingConnectors as $connector) {
                if (($connector['sourceCode'] ?? null) !== $sourceCode) {
                    continue;
                }

                $connectorId = self::readConnectorId($connector);

                if ($connectorId !== null) {
                    return $connectorId;
                }
            }
        }

        foreach ($existingConnectors as $connector) {
            // Check by title
            if (isset($connector['title']) && $connector['title'] === $title) {
                return self::readConnectorId($connector);
            }
            // Check by description if provided
            if (
                $description !== '' && isset($connector['description'])
                && $connector['description'] === $description
            ) {
                return self::readConnectorId($connector);
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $connector
     */
    private static function readConnectorId(array $connector): ?int
    {
        $connectorId = $connector['id'] ?? null;

        return is_numeric($connectorId) ? (int)$connectorId : null;
    }

    /**
     * Get PNG logo as base64 encoded image
     */
    private static function getPngLogoBase64Data(string $imgPath = ''): string
    {
        $imgBase64Data = 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR'
            . '42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

        if (!empty($imgPath)) {
            $imgData = file_get_contents($imgPath);
            if ($imgData !== false) {
                $base64 = base64_encode($imgData);
                $imgBase64Data = 'data:image/png;base64,' . $base64;
            }
        }

        return $imgBase64Data;
    }

    /**
     * Get logger instance with proper configuration
     *
     * @throws WrongConfigurationException
     * @throws InvalidArgumentException
     */
    public static function getLog(): LoggerInterface
    {
        static $logger;

        if ($logger === null) {
            // Load config
            self::loadConfigFromEnvFile();

            // Check settings
            if (!array_key_exists('LOG_LEVEL', $_ENV)) {
                throw new InvalidArgumentException('LOG_LEVEL not found in environment variables');
            }

            $logPath = $_ENV['LOG_PATH'] ?? '/var/log';
            $logLevel = self::parseLogLevel($_ENV['LOG_LEVEL']);
            $rotationDays = (int)($_ENV['LOG_ROTATION_DAYS'] ?? 7);

            // Create log directory if it doesn't exist
            $filesystem = new Filesystem();
            if (!$filesystem->exists($logPath)) {
                $filesystem->mkdir($logPath, 0755);
            }

            // Setup rotating file handler
            $rotatingFileHandler = new RotatingFileHandler(
                $logPath . self::LOG_FILE_NAME,
                $rotationDays
            );
            $rotatingFileHandler->setLevel($logLevel);
            $rotatingFileHandler->setFilenameFormat('{filename}-{date}', 'Y-m-d');

            $logger = new Logger('BiConnectorApp');
            $logger->pushHandler($rotatingFileHandler);
            $logger->pushProcessor(new MemoryUsageProcessor(true, true));
            $logger->pushProcessor(new UidProcessor());
            $logger->pushProcessor(new SecretMaskingProcessor());
        }

        return $logger;
    }

    /**
     * Parse log level string to Monolog constant
     */
    private static function parseLogLevel(string $level): \Monolog\Level
    {
        return match (strtoupper($level)) {
            'DEBUG' => \Monolog\Level::Debug,
            'INFO' => \Monolog\Level::Info,
            'NOTICE' => \Monolog\Level::Notice,
            'WARNING' => \Monolog\Level::Warning,
            'ERROR' => \Monolog\Level::Error,
            'CRITICAL' => \Monolog\Level::Critical,
            'ALERT' => \Monolog\Level::Alert,
            'EMERGENCY' => \Monolog\Level::Emergency,
            default => \Monolog\Level::Debug,
        };
    }

    /**
     * Get event dispatcher instance
     */
    protected static function getEventDispatcher(): EventDispatcherInterface
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(
            AuthTokenRenewedEvent::class,
            function (AuthTokenRenewedEvent $authTokenRenewedEvent): void {
                self::onAuthTokenRenewedEventListener($authTokenRenewedEvent);
            }
        );
        return $eventDispatcher;
    }

    /**
     * Event listener for when the authentication token is renewed
     */
    protected static function onAuthTokenRenewedEventListener(AuthTokenRenewedEvent $authTokenRenewedEvent): void
    {
        self::getLog()->debug('Application.onAuthTokenRenewedEventListener.start', [
            'expires' => $authTokenRenewedEvent->getRenewedToken()->authToken->expires
        ]);

        // Save renewed auth token
        self::getAuthRepository()->saveRenewedToken(
            $authTokenRenewedEvent->getRenewedToken()
        );

        self::getLog()->debug('Application.onAuthTokenRenewedEventListener.finish');
    }

    /**
     * Get Bitrix24 service builder
     * Simplified version for connector registration
     */
    public static function getB24Service(?Request $request = null): ?ServiceBuilder
    {
        // For this implementation, we'll return null and handle API calls directly
        // This avoids complex SDK configuration issues
        return null;
    }

    /**
     * Get authentication repository
     */
    private static function getAuthRepository(): LocalAppAuthRepositoryInterface
    {
        static $authRepository;

        if ($authRepository === null) {
            $filesystem = new Filesystem();
            $configPath = dirname(__DIR__) . '/config';
            $authFilePath = $configPath . '/app_auth.json';

            if (!$filesystem->exists($configPath)) {
                $filesystem->mkdir($configPath, 0755);
            }

            $authRepository = new AppAuthFileStorage($authFilePath, new Filesystem(), self::getLog());
        }

        return $authRepository;
    }

    /**
     * Load configuration from .env file
     */
    private static function loadConfigFromEnvFile(): void
    {
        static $loaded = false;

        if (!$loaded) {
            $configFile = dirname(__DIR__) . self::CONFIG_FILE_NAME;

            if (file_exists($configFile)) {
                $dotenv = new Dotenv();
                $dotenv->load($configFile);
            }

            $loaded = true;
        }
    }
}
