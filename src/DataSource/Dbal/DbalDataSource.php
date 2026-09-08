<?php

declare(strict_types=1);

namespace App\DataSource\Dbal;

use App\DataSource\ConnectionType;
use App\DataSource\DataSourceInterface;
use App\QueryBuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Psr\Log\LoggerInterface;

/**
 * Data source served by Doctrine DBAL: MySQL and PostgreSQL.
 */
final class DbalDataSource implements DataSourceInterface
{
    /** @var array<string, mixed> */
    private array $connectionParams;
    private ConnectionType $connectionType;
    private LoggerInterface $logger;
    private ?Connection $connection = null;

    /**
     * @param array<string, mixed> $connectionParams
     */
    public function __construct(
        array $connectionParams,
        ConnectionType $connectionType,
        LoggerInterface $logger
    ) {
        $this->connectionParams = $connectionParams;
        $this->connectionType = $connectionType;
        $this->logger = $logger;

        $this->logger->debug('DbalDataSource.__construct', [
            'class' => self::class,
            'method' => '__construct',
            'connectionParams' => array_keys($connectionParams),
            'connectionType' => $connectionType->value
        ]);
    }

    public function check(): void
    {
        $connection = $this->getConnection();

        // Test connection with a simple query
        $result = $connection->executeQuery('SELECT 1 as test');
        $testResult = $result->fetchAssociative();

        $this->logger->info('DbalDataSource.check.success', [
            'class' => self::class,
            'method' => 'check',
            'testResult' => $testResult
        ]);
    }

    /**
     * @return list<array{code: string, title: string}>
     */
    public function listTables(string $searchString): array
    {
        $this->logger->debug('DbalDataSource.listTables.start', [
            'class' => self::class,
            'method' => 'listTables',
            'searchString' => $searchString
        ]);

        if ($this->connectionType === ConnectionType::Mysql) {
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

        $stmt = $this->getConnection()->prepare($sql);

        if (!empty($searchString)) {
            $stmt->bindValue('search', '%' . $searchString . '%');
        }

        $result = $stmt->executeQuery();
        $tables = [];

        while ($row = $result->fetchAssociative()) {
            $tableName = $this->connectionType === ConnectionType::Mysql
                ? array_values($row)[0]
                : $row['table_name'];

            $tables[] = [
                'code' => (string)$tableName,
                'title' => (string)$tableName
            ];
        }

        $this->logger->info('DbalDataSource.listTables.success', [
            'class' => self::class,
            'method' => 'listTables',
            'tablesFound' => count($tables)
        ]);

        return $tables;
    }

    /**
     * @return list<array{code: string, name: string, type: string}>
     */
    public function describeTable(string $tableName): array
    {
        $this->logger->debug('DbalDataSource.describeTable.start', [
            'class' => self::class,
            'method' => 'describeTable',
            'tableName' => $tableName
        ]);

        if ($this->connectionType === ConnectionType::Mysql) {
            $sql = "DESCRIBE `{$tableName}`";
        } else { // PostgreSQL
            $sql = "SELECT column_name, data_type, is_nullable
                   FROM information_schema.columns
                   WHERE table_name = :table_name
                   AND table_schema = 'public'";
        }

        $stmt = $this->getConnection()->prepare($sql);

        if ($this->connectionType === ConnectionType::Postgresql) {
            $stmt->bindValue('table_name', $tableName);
        }

        $result = $stmt->executeQuery();
        $fields = [];

        while ($row = $result->fetchAssociative()) {
            if ($this->connectionType === ConnectionType::Mysql) {
                $fields[] = [
                    'code' => (string)$row['Field'],
                    'name' => (string)$row['Field'],
                    'type' => $this->mapMySQLTypeToBitrix((string)$row['Type'])
                ];
            } else { // PostgreSQL
                $fields[] = [
                    'code' => (string)$row['column_name'],
                    'name' => (string)$row['column_name'],
                    'type' => $this->mapPostgreSQLTypeToBitrix((string)$row['data_type'])
                ];
            }
        }

        $this->logger->info('DbalDataSource.describeTable.success', [
            'class' => self::class,
            'method' => 'describeTable',
            'tableName' => $tableName,
            'fieldsFound' => count($fields)
        ]);

        return $fields;
    }

    /**
     * The connection stays open until the caller has drained the rows, and is released afterwards on
     * every exit path.
     *
     * @param array<int, string> $select
     * @param array<string, mixed> $filter
     *
     * @return iterable<list<scalar|null>>
     */
    public function fetchData(string $tableName, array $select, array $filter, int $limit): iterable
    {
        $connection = $this->getConnection();
        $queryBuilder = new QueryBuilder($connection, $this->logger);

        try {
            yield from $queryBuilder->buildAndExecuteQuery($tableName, $select, $filter, $limit);
        } finally {
            $connection->close();
        }
    }

    /**
     * Get database connection
     */
    private function getConnection(): Connection
    {
        if ($this->connection === null) {
            $this->logger->debug('DbalDataSource.getConnection.creating', [
                'class' => self::class,
                'method' => 'getConnection'
            ]);

            $this->connection = DriverManager::getConnection(
                $this->buildConnectionParams($this->connectionType)
            );

            $this->logger->info('DbalDataSource.getConnection.created', [
                'class' => self::class,
                'method' => 'getConnection',
                'connectionType' => $this->connectionType->value
            ]);
        }

        return $this->connection;
    }

    /**
     * Connection parameters of the source, one entry per value.
     *
     * No value is written into a DSN string. DBAL merges the query part of the `url` parameter into the
     * parameters of the connection, so a value carrying a question mark used to replace the driver, the
     * path of the database file or the driver options the application sets.
     *
     * @return array<string, mixed>
     */
    private function buildConnectionParams(ConnectionType $connectionType): array
    {
        $driver = match ($connectionType) {
            ConnectionType::Mysql => 'pdo_mysql',
            ConnectionType::Postgresql => 'pdo_pgsql',
            default => throw new \InvalidArgumentException(
                'Unsupported connection type: ' . $connectionType->value
            )
        };

        $port = (int)($this->connectionParams['port'] ?? 0);

        if ($port <= 0) {
            $port = $connectionType === ConnectionType::Mysql ? 3306 : 5432;
        }

        return [
            'driver' => $driver,
            'host' => $this->readAddressPart('host', 'localhost'),
            'port' => $port,
            'dbname' => $this->readAddressPart('database', ''),
            'user' => (string)($this->connectionParams['username'] ?? ''),
            'password' => (string)($this->connectionParams['password'] ?? ''),
            'driverOptions' => [
                \PDO::ATTR_TIMEOUT => (int)($_ENV['DB_CONNECTION_TIMEOUT'] ?? 30),
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ],
        ];
    }

    /**
     * The address and the name of the database are the two values the driver writes into its own DSN, where
     * a semicolon separates the parameters and a blank separates them once the DSN of PostgreSQL is turned
     * into a connection string. A value carrying one of them is refused instead of being passed on, so that
     * no value can add a parameter of its own.
     */
    private function readAddressPart(string $name, string $default): string
    {
        $value = (string)($this->connectionParams[$name] ?? $default);

        if (preg_match('/[;\x00-\x20]/', $value) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of the connection carries a character that is not allowed in an address.',
                $name
            ));
        }

        return $value;
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
