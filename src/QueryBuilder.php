<?php

declare(strict_types=1);

namespace App;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder as DBALQueryBuilder;
use Psr\Log\LoggerInterface;

class QueryBuilder
{
    private Connection $connection;
    private LoggerInterface $logger;

    public function __construct(Connection $connection, LoggerInterface $logger)
    {
        $this->connection = $connection;
        $this->logger = $logger;

        $this->logger->debug('QueryBuilder.__construct', [
            'class' => self::class,
            'method' => '__construct'
        ]);
    }

    /**
     * Build and execute SQL query with filters, select fields and limit
     */
    public function buildAndExecuteQuery(string $tableName, array $select, array $filter, int $limit): array
    {
        $this->logger->debug('QueryBuilder.buildAndExecuteQuery.start', [
            'class' => self::class,
            'method' => 'buildAndExecuteQuery',
            'tableName' => $tableName,
            'select' => $select,
            'filter' => $filter,
            'limit' => $limit
        ]);

        $queryBuilder = $this->connection->createQueryBuilder();

        // Select fields
        if (empty($select)) {
            $queryBuilder->select('*');
            $this->logger->info('QueryBuilder.buildAndExecuteQuery.selectAll', [
                'class' => self::class,
                'method' => 'buildAndExecuteQuery'
            ]);
        } else {
            $quotedFields = array_map([$this, 'quoteIdentifier'], $select);
            $queryBuilder->select(...$quotedFields);
            $this->logger->info('QueryBuilder.buildAndExecuteQuery.selectFields', [
                'class' => self::class,
                'method' => 'buildAndExecuteQuery',
                'fields' => $select
            ]);
        }

        // From table
        $queryBuilder->from($this->quoteIdentifier($tableName));

        // Apply filters
        $this->applyFilters($queryBuilder, $filter);

        // Apply limit
        if ($limit > 0) {
            $queryBuilder->setMaxResults($limit);
        }

        $sql = $queryBuilder->getSQL();
        $parameters = $queryBuilder->getParameters();

        $this->logger->info('QueryBuilder.buildAndExecuteQuery.executing', [
            'class' => self::class,
            'method' => 'buildAndExecuteQuery',
            'sql' => $sql,
            'parametersCount' => count($parameters)
        ]);

        $result = $queryBuilder->executeQuery();
        $rows = $result->fetchAllAssociative();

        // Format data according to Bitrix24 BI Connector format
        $formattedData = $this->formatDataForBitrix($rows, $select);

        $this->logger->info('QueryBuilder.buildAndExecuteQuery.success', [
            'class' => self::class,
            'method' => 'buildAndExecuteQuery',
            'rowsReturned' => count($rows)
        ]);

        return $formattedData;
    }

    /**
     * Apply filters to query builder
     */
    private function applyFilters(DBALQueryBuilder $queryBuilder, array $filter): void
    {
        $this->logger->debug('QueryBuilder.applyFilters.start', [
            'class' => self::class,
            'method' => 'applyFilters',
            'filterCount' => count($filter)
        ]);

        if (empty($filter)) {
            return;
        }

        foreach ($filter as $field => $condition) {
            $this->applyFieldFilter($queryBuilder, $field, $condition);
        }

        $this->logger->info('QueryBuilder.applyFilters.applied', [
            'class' => self::class,
            'method' => 'applyFilters',
            'filtersApplied' => count($filter)
        ]);
    }

    /**
     * Apply filter for a specific field
     */
    private function applyFieldFilter(DBALQueryBuilder $queryBuilder, string $field, mixed $condition): void
    {
        $this->logger->debug('QueryBuilder.applyFieldFilter.start', [
            'class' => self::class,
            'method' => 'applyFieldFilter',
            'field' => $field,
            'condition' => $condition
        ]);

        $quotedField = $this->quoteIdentifier($field);
        $paramName = 'filter_' . $field;

        if (is_array($condition)) {
            // Handle complex conditions
            if (isset($condition['operator'])) {
                $operator = strtoupper($condition['operator']);
                $value = $condition['value'] ?? null;

                switch ($operator) {
                    case '=':
                    case 'EQ':
                        $queryBuilder->andWhere($quotedField . ' = :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case '!=':
                    case '<>':
                    case 'NEQ':
                        $queryBuilder->andWhere($quotedField . ' != :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case '>':
                    case 'GT':
                        $queryBuilder->andWhere($quotedField . ' > :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case '>=':
                    case 'GTE':
                        $queryBuilder->andWhere($quotedField . ' >= :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case '<':
                    case 'LT':
                        $queryBuilder->andWhere($quotedField . ' < :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case '<=':
                    case 'LTE':
                        $queryBuilder->andWhere($quotedField . ' <= :' . $paramName);
                        $queryBuilder->setParameter($paramName, $value);
                        break;

                    case 'LIKE':
                        $queryBuilder->andWhere($quotedField . ' LIKE :' . $paramName);
                        $queryBuilder->setParameter($paramName, '%' . $value . '%');
                        break;

                    case 'NOT LIKE':
                        $queryBuilder->andWhere($quotedField . ' NOT LIKE :' . $paramName);
                        $queryBuilder->setParameter($paramName, '%' . $value . '%');
                        break;

                    case 'IN':
                        if (is_array($value) && !empty($value)) {
                            $queryBuilder->andWhere($quotedField . ' IN (:' . $paramName . ')');
                            $queryBuilder->setParameter($paramName, $value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
                        }
                        break;

                    case 'NOT IN':
                        if (is_array($value) && !empty($value)) {
                            $queryBuilder->andWhere($quotedField . ' NOT IN (:' . $paramName . ')');
                            $queryBuilder->setParameter($paramName, $value, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
                        }
                        break;

                    case 'IS NULL':
                        $queryBuilder->andWhere($quotedField . ' IS NULL');
                        break;

                    case 'IS NOT NULL':
                        $queryBuilder->andWhere($quotedField . ' IS NOT NULL');
                        break;

                    case 'BETWEEN':
                        if (isset($condition['from']) && isset($condition['to'])) {
                            $queryBuilder->andWhere(
                                $quotedField . ' BETWEEN :' . $paramName . '_from AND :' . $paramName . '_to'
                            );
                            $queryBuilder->setParameter($paramName . '_from', $condition['from']);
                            $queryBuilder->setParameter($paramName . '_to', $condition['to']);
                        }
                        break;

                    default:
                        $this->logger->warning('QueryBuilder.applyFieldFilter.unknownOperator', [
                            'class' => self::class,
                            'method' => 'applyFieldFilter',
                            'field' => $field,
                            'operator' => $operator
                        ]);
                        break;
                }
            } else {
                // Array of values - treat as IN condition
                $queryBuilder->andWhere($quotedField . ' IN (:' . $paramName . ')');
                $queryBuilder->setParameter($paramName, $condition, \Doctrine\DBAL\Connection::PARAM_STR_ARRAY);
            }
        } else {
            // Simple equality condition
            $queryBuilder->andWhere($quotedField . ' = :' . $paramName);
            $queryBuilder->setParameter($paramName, $condition);
        }

        $this->logger->info('QueryBuilder.applyFieldFilter.applied', [
            'class' => self::class,
            'method' => 'applyFieldFilter',
            'field' => $field
        ]);
    }

    /**
     * Format data according to Bitrix24 BI Connector format
     */
    private function formatDataForBitrix(array $rows, array $select): array
    {
        $this->logger->debug('QueryBuilder.formatDataForBitrix.start', [
            'class' => self::class,
            'method' => 'formatDataForBitrix',
            'rowsCount' => count($rows),
            'selectFields' => $select
        ]);

        if (empty($rows)) {
            return [];
        }

        // Get field names from first row
        $fieldNames = array_keys($rows[0]);

        // If specific fields were selected, use those, otherwise use all fields
        if (!empty($select)) {
            $fieldNames = array_intersect($select, $fieldNames);
        }

        // Start with header row containing field names
        $result = [$fieldNames];

        // Add data rows
        foreach ($rows as $row) {
            $dataRow = [];
            foreach ($fieldNames as $fieldName) {
                $dataRow[] = $row[$fieldName] ?? null;
            }
            $result[] = $dataRow;
        }

        $this->logger->info('QueryBuilder.formatDataForBitrix.success', [
            'class' => self::class,
            'method' => 'formatDataForBitrix',
            'fieldsCount' => count($fieldNames),
            'dataRowsCount' => count($result) - 1
        ]);

        return $result;
    }

    /**
     * Quote database identifier (table name, column name)
     */
    private function quoteIdentifier(string $identifier): string
    {
        return $this->connection->quoteIdentifier($identifier);
    }
}
