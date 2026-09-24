<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

use Psr\Log\LoggerInterface;

/**
 * Assembles the statements of the ClickHouse source as text.
 *
 * The class only writes SQL: it neither opens a connection nor reads an answer. Every name and every value
 * that reaches a statement passes through ClickHouseDialect, so a value of the portal never becomes a piece
 * of the statement itself.
 */
final class ClickHouseQueryBuilder
{
    /**
     * The catalogue is read through the database of the current session, the one the transport names in
     * every request, so the statement carries no database name of its own.
     */
    private const CURRENT_DATABASE = 'currentDatabase()';

    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * The statement of the availability check: it reaches the source without touching any table.
     */
    public function buildCheck(): string
    {
        return 'SELECT 1';
    }

    /**
     * The tables of the current database, narrowed down by the search string of the portal.
     */
    public function buildTableList(string $searchString): string
    {
        $sql = 'SELECT ' . ClickHouseDialect::quoteIdentifier('name')
            . ' FROM ' . ClickHouseDialect::quoteIdentifier('system')
            . '.' . ClickHouseDialect::quoteIdentifier('tables')
            . ' WHERE ' . ClickHouseDialect::quoteIdentifier('database') . ' = ' . self::CURRENT_DATABASE;

        if ($searchString !== '') {
            $sql .= ' AND ' . ClickHouseDialect::quoteIdentifier('name')
                . ' LIKE ' . ClickHouseDialect::quoteString('%' . $searchString . '%');
        }

        return $sql . ' ORDER BY ' . ClickHouseDialect::quoteIdentifier('name');
    }

    /**
     * The columns of a table in the order they are declared in.
     */
    public function buildTableDescription(string $tableName): string
    {
        return 'SELECT ' . ClickHouseDialect::quoteIdentifier('name')
            . ', ' . ClickHouseDialect::quoteIdentifier('type')
            . ' FROM ' . ClickHouseDialect::quoteIdentifier('system')
            . '.' . ClickHouseDialect::quoteIdentifier('columns')
            . ' WHERE ' . ClickHouseDialect::quoteIdentifier('database') . ' = ' . self::CURRENT_DATABASE
            . ' AND ' . ClickHouseDialect::quoteIdentifier('table') . ' = '
            . ClickHouseDialect::quoteString($tableName)
            . ' ORDER BY ' . ClickHouseDialect::quoteIdentifier('position');
    }

    /**
     * The statement that reads the data of a table.
     *
     * @param array<int, string> $select an empty list reads every column
     * @param array<string, mixed> $filter
     * @param int $limit row limit already agreed with the transport; the same number belongs to
     *                   `max_result_rows`, because a higher limit here fails the statement
     */
    public function buildSelect(string $tableName, array $select, array $filter, int $limit): string
    {
        $sql = 'SELECT ' . $this->buildSelectList($select)
            . ' FROM ' . ClickHouseDialect::quoteIdentifier($tableName);

        $predicates = $this->buildPredicates($filter);

        if ($predicates !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $predicates);
        }

        if ($limit > 0) {
            $sql .= ' LIMIT ' . ClickHouseDialect::quoteNumber($limit);
        }

        return $sql;
    }

    /**
     * @param array<int, string> $select
     */
    private function buildSelectList(array $select): string
    {
        if ($select === []) {
            return '*';
        }

        return implode(', ', array_map(
            static fn(string $field): string => ClickHouseDialect::quoteIdentifier($field),
            $select
        ));
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<string>
     */
    private function buildPredicates(array $filter): array
    {
        $predicates = [];

        foreach ($filter as $field => $condition) {
            $predicate = $this->buildFieldPredicate((string)$field, $condition);

            if ($predicate !== null) {
                $predicates[] = $predicate;
            }
        }

        return $predicates;
    }

    /**
     * A condition that carries no predicate is skipped, exactly as the source served by DBAL skips it.
     */
    private function buildFieldPredicate(string $field, mixed $condition): ?string
    {
        $quotedField = ClickHouseDialect::quoteIdentifier($field);

        if (!is_array($condition)) {
            return $quotedField . ' = ' . ClickHouseDialect::quoteValue($condition);
        }

        if (!isset($condition['operator'])) {
            // A plain list of values is read as a membership condition.
            return $this->buildMembershipPredicate($quotedField, 'IN', $condition);
        }

        $operator = is_string($condition['operator']) ? strtoupper($condition['operator']) : '';
        $value = $condition['value'] ?? null;

        return match ($operator) {
            '=', 'EQ' => $quotedField . ' = ' . ClickHouseDialect::quoteValue($value),
            '!=', '<>', 'NEQ' => $quotedField . ' != ' . ClickHouseDialect::quoteValue($value),
            '>', 'GT' => $quotedField . ' > ' . ClickHouseDialect::quoteValue($value),
            '>=', 'GTE' => $quotedField . ' >= ' . ClickHouseDialect::quoteValue($value),
            '<', 'LT' => $quotedField . ' < ' . ClickHouseDialect::quoteValue($value),
            '<=', 'LTE' => $quotedField . ' <= ' . ClickHouseDialect::quoteValue($value),
            'LIKE' => $quotedField . ' LIKE ' . self::quoteSubstring($value),
            'NOT LIKE' => $quotedField . ' NOT LIKE ' . self::quoteSubstring($value),
            'IN' => $this->buildMembershipPredicate($quotedField, 'IN', $value),
            'NOT IN' => $this->buildMembershipPredicate($quotedField, 'NOT IN', $value),
            'IS NULL' => $quotedField . ' IS NULL',
            'IS NOT NULL' => $quotedField . ' IS NOT NULL',
            'BETWEEN' => $this->buildRangePredicate($quotedField, $condition),
            default => $this->rejectOperator($field, $operator),
        };
    }

    /**
     * An empty list carries no condition and is skipped, so that a filter without values does not turn
     * into a statement that reads nothing.
     */
    private function buildMembershipPredicate(string $quotedField, string $operator, mixed $values): ?string
    {
        if (!is_array($values) || $values === []) {
            return null;
        }

        $literals = array_map(
            static fn(mixed $value): string => ClickHouseDialect::quoteValue($value),
            array_values($values)
        );

        return $quotedField . ' ' . $operator . ' (' . implode(', ', $literals) . ')';
    }

    /**
     * @param array<mixed> $condition
     */
    private function buildRangePredicate(string $quotedField, array $condition): ?string
    {
        if (!isset($condition['from']) || !isset($condition['to'])) {
            return null;
        }

        return $quotedField . ' BETWEEN ' . ClickHouseDialect::quoteValue($condition['from'])
            . ' AND ' . ClickHouseDialect::quoteValue($condition['to']);
    }

    /**
     * The value of a substring match is wrapped the same way the source served by DBAL wraps it.
     */
    private static function quoteSubstring(mixed $value): string
    {
        $text = is_scalar($value) ? (string)$value : '';

        return ClickHouseDialect::quoteString('%' . $text . '%');
    }

    /**
     * An operator outside the dictionary drops its filter instead of reaching the statement.
     */
    private function rejectOperator(string $field, string $operator): null
    {
        $this->logger->warning('ClickHouseQueryBuilder.buildFieldPredicate.unknownOperator', [
            'class' => self::class,
            'method' => 'buildFieldPredicate',
            'field' => $field,
            'operator' => $operator,
        ]);

        return null;
    }
}
