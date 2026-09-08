<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

use App\DataSource\DataSourceInterface;
use Psr\Log\LoggerInterface;

/**
 * Data source served by the HTTP interface of ClickHouse.
 *
 * The four operations of the contract are assembled out of three parts: the query builder writes the
 * statement, the transport sends it and hands the body over as a stream, the row stream reads that body
 * line by line. No operation ever holds the answer of the source in memory as a whole.
 */
final class ClickHouseDataSource implements DataSourceInterface
{
    private ClickHouseHttpClient $httpClient;
    private ClickHouseQueryBuilder $queryBuilder;
    private LoggerInterface $logger;

    /**
     * @param array<string, mixed> $connectionParams host, database, username and password of the source
     * @param ClickHouseHttpClient|null $httpClient transport to send through; the default one is built here
     */
    public function __construct(
        array $connectionParams,
        LoggerInterface $logger,
        ?ClickHouseHttpClient $httpClient = null
    ) {
        $this->httpClient = $httpClient ?? new ClickHouseHttpClient($connectionParams, $logger);
        $this->queryBuilder = new ClickHouseQueryBuilder($logger);
        $this->logger = $logger;

        $this->logger->debug('ClickHouseDataSource.__construct', [
            'class' => self::class,
            'method' => '__construct',
            'connectionParams' => array_keys($connectionParams),
        ]);
    }

    public function check(): void
    {
        // The check runs under the short time budget of the transport: it tells whether the address answers
        // at all, and an address that drops packets must not hold a worker thread of the application.
        $rowStream = new ClickHouseRowStream(
            $this->httpClient->queryAvailability($this->queryBuilder->buildCheck()),
            $this->logger
        );

        try {
            $rowStream->fetchRow();
        } finally {
            $rowStream->close();
        }

        $this->logger->info('ClickHouseDataSource.check.success', [
            'class' => self::class,
            'method' => 'check',
        ]);
    }

    /**
     * @return list<array{code: string, title: string}>
     */
    public function listTables(string $searchString): array
    {
        $this->logger->debug('ClickHouseDataSource.listTables.start', [
            'class' => self::class,
            'method' => 'listTables',
            'searchString' => $searchString,
        ]);

        $rowStream = $this->openStream($this->queryBuilder->buildTableList($searchString));
        $tables = [];

        try {
            while (($row = $rowStream->fetchRow()) !== null) {
                $tableName = self::toText($row[0] ?? null);

                $tables[] = [
                    'code' => $tableName,
                    'title' => $tableName,
                ];
            }
        } finally {
            $rowStream->close();
        }

        $this->logger->info('ClickHouseDataSource.listTables.success', [
            'class' => self::class,
            'method' => 'listTables',
            'tablesFound' => count($tables),
        ]);

        return $tables;
    }

    /**
     * @return list<array{code: string, name: string, type: string}>
     */
    public function describeTable(string $tableName): array
    {
        $this->logger->debug('ClickHouseDataSource.describeTable.start', [
            'class' => self::class,
            'method' => 'describeTable',
            'tableName' => $tableName,
        ]);

        $rowStream = $this->openStream($this->queryBuilder->buildTableDescription($tableName));
        $fields = [];

        try {
            while (($row = $rowStream->fetchRow()) !== null) {
                $columnName = self::toText($row[0] ?? null);

                $fields[] = [
                    'code' => $columnName,
                    'name' => $columnName,
                    'type' => ClickHouseTypeMap::toFieldType(self::toText($row[1] ?? null)),
                ];
            }
        } finally {
            $rowStream->close();
        }

        $this->logger->info('ClickHouseDataSource.describeTable.success', [
            'class' => self::class,
            'method' => 'describeTable',
            'tableName' => $tableName,
            'fieldsFound' => count($fields),
        ]);

        return $fields;
    }

    /**
     * Rows are delivered one by one: the column-name row first, then one row per record.
     *
     * The names of the columns are taken from the answer of the source and are published only once a
     * record has been read, so an empty result yields nothing at all. The stream is released on every exit
     * path, the one where the consumer abandons the rows included.
     *
     * @param array<int, string> $select
     * @param array<string, mixed> $filter
     *
     * @return iterable<list<scalar|null>>
     */
    public function fetchData(string $tableName, array $select, array $filter, int $limit): iterable
    {
        // The transport caps the requested limit, and the same number belongs to the statement: a `LIMIT`
        // above `max_result_rows` fails the statement instead of shortening it.
        $rowLimit = $this->httpClient->resolveRowLimit($limit);
        $sql = $this->queryBuilder->buildSelect($tableName, $select, $filter, $rowLimit);

        $this->logger->info('ClickHouseDataSource.fetchData.executing', [
            'class' => self::class,
            'method' => 'fetchData',
            'tableName' => $tableName,
            'rowLimit' => $rowLimit,
        ]);

        $rowStream = new ClickHouseRowStream($this->httpClient->query($sql, $rowLimit), $this->logger);
        $dataRowsCount = 0;

        try {
            $row = $rowStream->fetchRow();

            if ($row === null) {
                return;
            }

            yield $rowStream->getColumnNames();

            do {
                $dataRowsCount++;

                yield $row;
            } while (($row = $rowStream->fetchRow()) !== null);

            $this->logger->info('ClickHouseDataSource.fetchData.success', [
                'class' => self::class,
                'method' => 'fetchData',
                'rowsReturned' => $dataRowsCount,
            ]);
        } finally {
            $rowStream->close();
        }
    }

    /**
     * @throws ClickHouseQueryException when the answer carries a failure instead of the header
     */
    private function openStream(string $sql): ClickHouseRowStream
    {
        return new ClickHouseRowStream($this->httpClient->query($sql), $this->logger);
    }

    private static function toText(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
