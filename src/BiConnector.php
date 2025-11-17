<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\Response;

class BiConnector
{
    private array $connectionParams;
    private string $connectionType;
    private LoggerInterface $logger;
    private ?Connection $connection = null;
    private FilesystemAdapter $cache;

    public function __construct(array $connectionParams, string $connectionType, LoggerInterface $logger)
    {
        $this->logger = $logger;
        $this->connectionParams = $connectionParams;
        $this->connectionType = $connectionType;

        // Initialize cache with proper directory creation
        $cacheDir = dirname(__DIR__) . '/cache';
        $this->initializeCacheDirectory($cacheDir);

        $this->cache = new FilesystemAdapter('biconnector', 0, $cacheDir);

        $this->logger->debug('BiConnector.__construct', [
            'class' => self::class,
            'method' => '__construct',
            'connectionParams' => array_keys($connectionParams),
            'connectionType' => $connectionType,
            'cacheDir' => $cacheDir
        ]);
    }

    /**
     * Check database connection availability
     */
    public function check(): Response
    {
        $this->logger->debug('BiConnector.check.start', [
            'class' => self::class,
            'method' => 'check'
        ]);

        try {
            $connection = $this->getConnection();

            // Test connection with a simple query
            $result = $connection->executeQuery('SELECT 1 as test');
            $testResult = $result->fetchAssociative();

            $this->logger->info('BiConnector.check.success', [
                'class' => self::class,
                'method' => 'check',
                'testResult' => $testResult
            ]);

            return new Response(
                json_encode(['status' => 'OK', 'message' => 'Connection successful']) ?: '{}',
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $this->logger->error('BiConnector.check.error', [
                'class' => self::class,
                'method' => 'check',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new Response(
                json_encode([
                    'status' => 'ERROR',
                    'message' => $e->getMessage()
                ]) ?: '{"status":"ERROR","message":"Unknown error"}',
                200,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Get list of available tables
     */
    public function tableList(string $searchString = ''): Response
    {
        $this->logger->debug('BiConnector.tableList.start', [
            'class' => self::class,
            'method' => 'tableList',
            'searchString' => $searchString
        ]);

        try {
            $cacheKey = 'table_list_' . md5(
                json_encode($this->connectionParams) . $this->connectionType . $searchString
            );
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $tables = $cacheItem->get();
                $this->logger->info('BiConnector.tableList.fromCache', [
                    'class' => self::class,
                    'method' => 'tableList',
                    'tablesCount' => count($tables),
                    'cacheKey' => $cacheKey
                ]);
            } else {
                $connection = $this->getConnection();
                $tables = $this->fetchTables($connection, $searchString);

                // Cache for configured time
                $ttl = (int)($_ENV['CACHE_TTL_TABLE_LIST'] ?? 3600);
                $cacheItem->set($tables);
                $cacheItem->expiresAfter($ttl);

                $saved = $this->cache->save($cacheItem);

                $this->logger->info('BiConnector.tableList.fromDatabase', [
                    'class' => self::class,
                    'method' => 'tableList',
                    'tablesCount' => count($tables),
                    'cacheTtl' => $ttl,
                    'cacheKey' => $cacheKey,
                    'cacheSaved' => $saved
                ]);
            }

            return new Response(
                json_encode($tables) ?: '[]',
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $this->logger->error('BiConnector.tableList.error', [
                'class' => self::class,
                'method' => 'tableList',
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new Response(
                json_encode(['error' => $e->getMessage()]) ?: '{"error":"Unknown error"}',
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Get table structure description
     */
    public function tableDescription(string $tableName): Response
    {
        $this->logger->debug('BiConnector.tableDescription.start', [
            'class' => self::class,
            'method' => 'tableDescription',
            'tableName' => $tableName
        ]);

        if (empty($tableName)) {
            $this->logger->warning('BiConnector.tableDescription.emptyTableName', [
                'class' => self::class,
                'method' => 'tableDescription'
            ]);

            return new Response(
                json_encode(['error' => 'Table name is required']) ?: '{"error":"Table name is required"}',
                400,
                ['Content-Type' => 'application/json']
            );
        }

        try {
            $cacheKey = 'table_desc_' . md5(
                json_encode($this->connectionParams) . $this->connectionType . $tableName
            );
            $cacheItem = $this->cache->getItem($cacheKey);

            if ($cacheItem->isHit()) {
                $fields = $cacheItem->get();
                $this->logger->info('BiConnector.tableDescription.fromCache', [
                    'class' => self::class,
                    'method' => 'tableDescription',
                    'tableName' => $tableName,
                    'fieldsCount' => count($fields),
                    'cacheKey' => $cacheKey
                ]);
            } else {
                $connection = $this->getConnection();
                $fields = $this->fetchTableFields($connection, $tableName);

                // Cache for configured time
                $ttl = (int)($_ENV['CACHE_TTL_TABLE_DESCRIPTION'] ?? 1800);
                $cacheItem->set($fields);
                $cacheItem->expiresAfter($ttl);

                $saved = $this->cache->save($cacheItem);

                $this->logger->info('BiConnector.tableDescription.fromDatabase', [
                    'class' => self::class,
                    'method' => 'tableDescription',
                    'tableName' => $tableName,
                    'fieldsCount' => count($fields),
                    'cacheTtl' => $ttl,
                    'cacheKey' => $cacheKey,
                    'cacheSaved' => $saved
                ]);
            }

            return new Response(
                json_encode($fields) ?: '[]',
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $this->logger->error('BiConnector.tableDescription.error', [
                'class' => self::class,
                'method' => 'tableDescription',
                'tableName' => $tableName,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new Response(
                json_encode(['error' => $e->getMessage()]) ?: '{"error":"Unknown error"}',
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Get table data with filtering, sorting and pagination
     */
    public function getData(string $tableName, array $select, array $filter, int $limit): Response
    {
        $this->logger->debug('BiConnector.getData.start', [
            'class' => self::class,
            'method' => 'getData',
            'tableName' => $tableName,
            'selectCount' => count($select),
            'filterCount' => count($filter),
            'limit' => $limit
        ]);

        if (empty($tableName)) {
            $this->logger->warning('BiConnector.getData.emptyTableName', [
                'class' => self::class,
                'method' => 'getData'
            ]);

            return new Response(
                json_encode(['error' => 'Table name is required']) ?: '{"error":"Table name is required"}',
                400,
                ['Content-Type' => 'application/json']
            );
        }

        try {
            $connection = $this->getConnection();
            $queryBuilder = new QueryBuilder($connection, $this->logger);

            $data = $queryBuilder->buildAndExecuteQuery($tableName, $select, $filter, $limit);

            $this->logger->info('BiConnector.getData.fromDatabase', [
                'class' => self::class,
                'method' => 'getData',
                'tableName' => $tableName,
                'rowsCount' => count($data) - 1, // Subtract header row
            ]);

            return new Response(
                json_encode($data) ?: '[]',
                200,
                ['Content-Type' => 'application/json']
            );
        } catch (\Throwable $e) {
            $this->logger->error('BiConnector.getData.error', [
                'class' => self::class,
                'method' => 'getData',
                'tableName' => $tableName,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return new Response(
                json_encode(['error' => $e->getMessage()]) ?: '{"error":"Unknown error"}',
                500,
                ['Content-Type' => 'application/json']
            );
        }
    }

    /**
     * Initialize cache directory
     */
    private function initializeCacheDirectory(string $cacheDir): void
    {
        $biconnectorCacheDir = $cacheDir . '/biconnector';

        try {
            if (!is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
                $this->logger->info('BiConnector.initializeCacheDirectory.created', [
                    'class' => self::class,
                    'method' => 'initializeCacheDirectory',
                    'directory' => $cacheDir
                ]);
            }

            if (!is_dir($biconnectorCacheDir)) {
                mkdir($biconnectorCacheDir, 0755, true);
                $this->logger->info('BiConnector.initializeCacheDirectory.createdBiconnector', [
                    'class' => self::class,
                    'method' => 'initializeCacheDirectory',
                    'directory' => $biconnectorCacheDir
                ]);
            }

            if (!is_writable($cacheDir)) {
                $this->logger->warning('BiConnector.initializeCacheDirectory.notWritable', [
                    'class' => self::class,
                    'method' => 'initializeCacheDirectory',
                    'directory' => $cacheDir
                ]);
            }
        } catch (\Exception $e) {
            $this->logger->error('BiConnector.initializeCacheDirectory.error', [
                'class' => self::class,
                'method' => 'initializeCacheDirectory',
                'directory' => $cacheDir,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get database connection
     */
    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->logger->debug('BiConnector.getConnection.creating', [
                'class' => self::class,
                'method' => 'getConnection'
            ]);

            $dsn = $this->buildDsn($this->connectionType);

            $connectionParams = [
                'url' => $dsn,
                'driverOptions' => [
                    \PDO::ATTR_TIMEOUT => (int)($_ENV['DB_CONNECTION_TIMEOUT'] ?? 30),
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                ]
            ];

            $this->connection = DriverManager::getConnection($connectionParams);

            $this->logger->info('BiConnector.getConnection.created', [
                'class' => self::class,
                'method' => 'getConnection',
                'connectionType' => $this->connectionType
            ]);
        }

        return $this->connection;
    }

    /**
     * Build DSN string based on connection type
     */
    private function buildDsn(string $connectionType): string
    {
        $host = $this->connectionParams['host'] ?? 'localhost';
        $database = $this->connectionParams['database'] ?? '';
        $username = $this->connectionParams['username'] ?? '';
        $password = $this->connectionParams['password'] ?? '';

        return match ($connectionType) {
            'mysql' => sprintf(
                'mysql://%s:%s@%s:%s/%s',
                urlencode($username),
                urlencode($password),
                $host,
                $this->connectionParams['port'] ?? '3306',
                $database
            ),
            'postgresql' => sprintf(
                'postgresql://%s:%s@%s:%s/%s',
                urlencode($username),
                urlencode($password),
                $host,
                $this->connectionParams['port'] ?? '5432',
                $database
            ),
            default => throw new \InvalidArgumentException('Unsupported connection type: ' . $connectionType)
        };
    }

    /**
     * Fetch tables from database
     */
    private function fetchTables(Connection $connection, string $searchString): array
    {
        $this->logger->debug('BiConnector.fetchTables.start', [
            'class' => self::class,
            'method' => 'fetchTables',
            'searchString' => $searchString
        ]);

        if ($this->connectionType === 'mysql') {
            $sql = "SHOW TABLES";
            if (!empty($searchString)) {
                $sql .= " LIKE :search";
            }
        } else { // PostgreSQL
            $sql = "SELECT tablename as table_name FROM pg_tables WHERE schemaname = 'public'";
            if (!empty($searchString)) {
                $sql .= " AND tablename LIKE :search";
            }
        }

        $stmt = $connection->prepare($sql);

        if (!empty($searchString)) {
            $stmt->bindValue('search', '%' . $searchString . '%');
        }

        $result = $stmt->executeQuery();
        $tables = [];

        while ($row = $result->fetchAssociative()) {
            $tableName = $this->connectionType === 'mysql'
                ? array_values($row)[0]
                : $row['table_name'];

            $tables[] = [
                'code' => $tableName,
                'title' => $tableName
            ];
        }

        $this->logger->info('BiConnector.fetchTables.success', [
            'class' => self::class,
            'method' => 'fetchTables',
            'tablesFound' => count($tables)
        ]);

        return $tables;
    }

    /**
     * Fetch table fields information
     */
    private function fetchTableFields(Connection $connection, string $tableName): array
    {
        $this->logger->debug('BiConnector.fetchTableFields.start', [
            'class' => self::class,
            'method' => 'fetchTableFields',
            'tableName' => $tableName
        ]);

        if ($this->connectionType === 'mysql') {
            $sql = "DESCRIBE `{$tableName}`";
        } else { // PostgreSQL
            $sql = "SELECT column_name, data_type, is_nullable 
                   FROM information_schema.columns 
                   WHERE table_name = :table_name 
                   AND table_schema = 'public'";
        }

        $stmt = $connection->prepare($sql);

        if ($this->connectionType === 'postgresql') {
            $stmt->bindValue('table_name', $tableName);
        }

        $result = $stmt->executeQuery();
        $fields = [];

        while ($row = $result->fetchAssociative()) {
            if ($this->connectionType === 'mysql') {
                $fields[] = [
                    'code' => $row['Field'],
                    'name' => $row['Field'],
                    'type' => $this->mapMySQLTypeToBitrix($row['Type'])
                ];
            } else { // PostgreSQL
                $fields[] = [
                    'code' => $row['column_name'],
                    'name' => $row['column_name'],
                    'type' => $this->mapPostgreSQLTypeToBitrix($row['data_type'])
                ];
            }
        }

        $this->logger->info('BiConnector.fetchTableFields.success', [
            'class' => self::class,
            'method' => 'fetchTableFields',
            'tableName' => $tableName,
            'fieldsFound' => count($fields)
        ]);

        return $fields;
    }

    /**
     * Map MySQL data types to Bitrix24 BI Connector types
     */
    private function mapMySQLTypeToBitrix(string $mysqlType): string
    {
        $mysqlType = strtolower($mysqlType);

        if (
            str_contains($mysqlType, 'int') || str_contains($mysqlType, 'tinyint') ||
            str_contains($mysqlType, 'smallint') || str_contains($mysqlType, 'mediumint') ||
            str_contains($mysqlType, 'bigint')
        ) {
            return 'int';
        }

        if (
            str_contains($mysqlType, 'float') || str_contains($mysqlType, 'double') ||
            str_contains($mysqlType, 'decimal') || str_contains($mysqlType, 'numeric')
        ) {
            return 'double';
        }

        if (str_contains($mysqlType, 'date') && !str_contains($mysqlType, 'time')) {
            return 'date';
        }

        if (str_contains($mysqlType, 'datetime') || str_contains($mysqlType, 'timestamp')) {
            return 'datetime';
        }

        return 'string';
    }

    /**
     * Map PostgreSQL data types to Bitrix24 BI Connector types
     */
    private function mapPostgreSQLTypeToBitrix(string $pgType): string
    {
        $pgType = strtolower($pgType);

        if (in_array($pgType, ['integer', 'bigint', 'smallint', 'serial', 'bigserial'])) {
            return 'int';
        }

        if (in_array($pgType, ['real', 'double precision', 'numeric', 'decimal'])) {
            return 'double';
        }

        if ($pgType === 'date') {
            return 'date';
        }

        if (in_array($pgType, ['timestamp', 'timestamp with time zone', 'timestamp without time zone'])) {
            return 'datetime';
        }

        return 'string';
    }
}
