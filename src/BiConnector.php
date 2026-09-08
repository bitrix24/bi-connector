<?php

declare(strict_types=1);

namespace App;

use App\DataSource\DataSourceFactory;
use App\DataSource\DataSourceInterface;
use App\Response\JsonRowsFileWriter;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class BiConnector
{
    private array $connectionParams;
    private string $connectionType;
    private LoggerInterface $logger;
    private FilesystemAdapter $cache;
    private string $cacheDir;
    private DataSourceFactory $dataSourceFactory;
    private ?DataSourceInterface $dataSource;

    public function __construct(
        array $connectionParams,
        string $connectionType,
        LoggerInterface $logger,
        ?DataSourceInterface $dataSource = null
    ) {
        $this->logger = $logger;
        $this->connectionParams = $connectionParams;
        $this->connectionType = $connectionType;
        $this->dataSourceFactory = new DataSourceFactory($logger);
        $this->dataSource = $dataSource;

        // Initialize cache with proper directory creation
        $cacheDir = dirname(__DIR__) . '/cache';
        $this->initializeCacheDirectory($cacheDir);

        $this->cacheDir = $cacheDir;
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
            $this->getDataSource()->check();

            $this->logger->info('BiConnector.check.success', [
                'class' => self::class,
                'method' => 'check'
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
            $cacheKey = $this->buildCacheKey('table_list_', $searchString);
            $cacheItem = $cacheKey === null ? null : $this->cache->getItem($cacheKey);

            if ($cacheItem !== null && $cacheItem->isHit()) {
                $tables = $cacheItem->get();
                $this->logger->info('BiConnector.tableList.fromCache', [
                    'class' => self::class,
                    'method' => 'tableList',
                    'tablesCount' => count($tables),
                    'cacheKey' => $cacheKey
                ]);
            } else {
                $tables = $this->getDataSource()->listTables($searchString);

                // Cache for configured time
                $ttl = (int)($_ENV['CACHE_TTL_TABLE_LIST'] ?? 3600);
                $saved = false;

                if ($cacheItem !== null) {
                    $cacheItem->set($tables);
                    $cacheItem->expiresAfter($ttl);

                    $saved = $this->cache->save($cacheItem);
                }

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
            $cacheKey = $this->buildCacheKey('table_desc_', $tableName);
            $cacheItem = $cacheKey === null ? null : $this->cache->getItem($cacheKey);

            if ($cacheItem !== null && $cacheItem->isHit()) {
                $fields = $cacheItem->get();
                $this->logger->info('BiConnector.tableDescription.fromCache', [
                    'class' => self::class,
                    'method' => 'tableDescription',
                    'tableName' => $tableName,
                    'fieldsCount' => count($fields),
                    'cacheKey' => $cacheKey
                ]);
            } else {
                $fields = $this->getDataSource()->describeTable($tableName);

                // Cache for configured time
                $ttl = (int)($_ENV['CACHE_TTL_TABLE_DESCRIPTION'] ?? 1800);
                $saved = false;

                if ($cacheItem !== null) {
                    $cacheItem->set($fields);
                    $cacheItem->expiresAfter($ttl);

                    $saved = $this->cache->save($cacheItem);
                }

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

        $filePath = null;

        try {
            $writer = new JsonRowsFileWriter($this->cacheDir);
            $filePath = $writer->write($this->getDataSource()->fetchData($tableName, $select, $filter, $limit));

            $this->logger->info('BiConnector.getData.fromDatabase', [
                'class' => self::class,
                'method' => 'getData',
                'tableName' => $tableName,
                'rowsCount' => $writer->getDataRowCount(),
            ]);

            $response = new BinaryFileResponse($filePath, 200, ['Content-Type' => 'application/json']);
            $response->deleteFileAfterSend(true);

            return $response;
        } catch (\Throwable $e) {
            // No body is served on failure: the partially written file is dropped
            if ($filePath !== null && is_file($filePath)) {
                unlink($filePath);
            }

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
     * Key of a cache entry, or null when the parameters of the connection cannot be encoded.
     *
     * The encoding is not allowed to fail quietly: json_encode() answers false on a broken UTF-8 sequence,
     * false becomes an empty string inside the concatenation, and the key stops depending on the address
     * and the credentials of the connection, so two connections would share one entry. Substituting the
     * broken sequences keeps the key of a well formed set of parameters exactly as it was.
     */
    private function buildCacheKey(string $prefix, string $suffix): ?string
    {
        $encodedParams = json_encode($this->connectionParams, JSON_INVALID_UTF8_SUBSTITUTE);

        if ($encodedParams === false) {
            $this->logger->warning('BiConnector.buildCacheKey.notEncodable', [
                'class' => self::class,
                'method' => 'buildCacheKey',
                'prefix' => $prefix,
                'message' => json_last_error_msg()
            ]);

            return null;
        }

        return $prefix . md5($encodedParams . $this->connectionType . $suffix);
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
     * Get the data source serving the configured connection type
     */
    private function getDataSource(): DataSourceInterface
    {
        return $this->dataSource ??= $this->dataSourceFactory->create($this->connectionType, $this->connectionParams);
    }
}
