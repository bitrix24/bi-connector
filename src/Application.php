<?php

declare(strict_types=1);

namespace App;

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

class Application
{
    private const CONFIG_FILE_NAME = '/.env';
    private const LOG_FILE_NAME = '/application.log';

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
     * Register connectors using direct HTTP API calls
     */
    private static function registerConnectorsDirectly(mixed $auth): void
    {
        self::getLog()->debug('Application.registerConnectorsDirectly.start');

        // Load configuration first
        self::loadConfigFromEnvFile();

        $accessToken = $auth->authToken->accessToken;
        $domain = $auth->domain;

        // Get existing connectors first
        $existingConnectors = self::getExistingConnectors($domain, $accessToken);

        // Get application domain with fallback
        $appDomain = $_ENV['APP_DOMAIN'] ?? 'https://localhost';

        // Prepare connector configurations
        $connectorsToRegister = [
            [
                'title' => 'MySQL Database Connector',
                'logo' => self::getMySQLLogo(),
                'description' => 'Connector for MySQL databases with authentication',
                'urlCheck' => $appDomain . '/?connection_type=mysql&action=check',
                'urlTableList' => $appDomain . '/?connection_type=mysql&action=table_list',
                'urlTableDescription' => $appDomain
                    . '/?connection_type=mysql&action=table_description',
                'urlData' => $appDomain . '/?connection_type=mysql&action=data',
                'settings' => [
                    ['name' => 'Host', 'type' => 'STRING', 'code' => 'host'],
                    ['name' => 'Port', 'type' => 'STRING', 'code' => 'port'],
                    ['name' => 'Database', 'type' => 'STRING', 'code' => 'database'],
                    ['name' => 'Username', 'type' => 'STRING', 'code' => 'username'],
                    ['name' => 'Password', 'type' => 'STRING', 'code' => 'password']
                ],
                'sort' => 100
            ],
            [
                'title' => 'PostgreSQL Database Connector',
                'logo' => self::getPostgreSQLLogo(),
                'description' => 'Connector for PostgreSQL databases with authentication',
                'urlCheck' => $appDomain . '/?connection_type=postgresql&action=check',
                'urlTableList' => $appDomain . '/?connection_type=postgresql&action=table_list',
                'urlTableDescription' => $appDomain
                    . '/?connection_type=postgresql&action=table_description',
                'urlData' => $appDomain . '/?connection_type=postgresql&action=data',
                'settings' => [
                    ['name' => 'Host', 'type' => 'STRING', 'code' => 'host'],
                    ['name' => 'Port', 'type' => 'STRING', 'code' => 'port'],
                    ['name' => 'Database', 'type' => 'STRING', 'code' => 'database'],
                    ['name' => 'Username', 'type' => 'STRING', 'code' => 'username'],
                    ['name' => 'Password', 'type' => 'STRING', 'code' => 'password']
                ],
                'sort' => 200
            ]
        ];

        // Register or update connectors
        foreach ($connectorsToRegister as $connectorData) {
            $existingConnectorId = self::getExistingConnectorId($existingConnectors, $connectorData['title']);
            if ($existingConnectorId === null) {
                self::registerConnectorViaAPI($domain, $accessToken, $connectorData);
            } else {
                self::updateConnectorViaAPI($domain, $accessToken, $existingConnectorId, $connectorData);
            }
        }
    }

    /**
     * Get list of existing connectors from Bitrix24
     */
    private static function getExistingConnectors(string $domain, string $accessToken): array
    {
        $url = "https://{$domain}/rest/biconnector.connector.list";

        $postData = [
            'auth' => $accessToken
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::getLog()->info('Application.getExistingConnectors', [
            'httpCode' => $httpCode,
            'response' => $response
        ]);

        if ($httpCode === 200 && $response) {
            if (is_string($response)) {
                $decodedResponse = json_decode($response, true);
                if (isset($decodedResponse['result'])) {
                    return $decodedResponse['result'];
                }
            }
        }

        return [];
    }

    /**
     * Get existing connector ID by title
     */
    private static function getExistingConnectorId(array $existingConnectors, string $title): ?int
    {
        foreach ($existingConnectors as $connector) {
            if (isset($connector['title']) && $connector['title'] === $title) {
                return $connector['id'] ?? null;
            }
        }
        return null;
    }

    /**
     * Register single connector via direct API call
     */
    private static function registerConnectorViaAPI(string $domain, string $accessToken, array $connectorData): void
    {
        $url = "https://{$domain}/rest/biconnector.connector.add";

        $postData = [
            'auth' => $accessToken,
            'fields' => $connectorData
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::getLog()->info('Application.registerConnectorViaAPI', [
            'title' => $connectorData['title'],
            'httpCode' => $httpCode,
            'response' => $response
        ]);
    }

    /**
     * Update existing connector via direct API call
     */
    private static function updateConnectorViaAPI(
        string $domain,
        string $accessToken,
        int $connectorId,
        array $connectorData
    ): void {
        $url = "https://{$domain}/rest/biconnector.connector.update";

        $postData = [
            'auth' => $accessToken,
            'id' => $connectorId,
            'fields' => $connectorData
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        self::getLog()->info('Application.updateConnectorViaAPI', [
            'id' => $connectorId,
            'title' => $connectorData['title'],
            'httpCode' => $httpCode,
            'response' => $response
        ]);
    }

    /**
     * Get MySQL logo as base64 encoded image
     */
    private static function getMySQLLogo(): string
    {
        // Simple MySQL-like logo in base64
        return 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR'
            . '42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
    }

    /**
     * Get PostgreSQL logo as base64 encoded image
     */
    private static function getPostgreSQLLogo(): string
    {
        // Simple PostgreSQL-like logo in base64
        return 'data:image/png;base64,'
            . 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42m'
            . 'NkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
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
