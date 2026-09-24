<?php

declare(strict_types=1);

namespace App\DataSource;

interface DataSourceInterface
{
    // Throws on failure; returning normally means the source is reachable.
    public function check(): void;

    /** @return list<array{code: string, title: string}> */
    public function listTables(string $searchString): array;

    /** @return list<array{code: string, name: string, type: string}> */
    public function describeTable(string $tableName): array;

    /**
     * Yields the column-name row first, then one row per record; an empty result yields nothing.
     *
     * @return iterable<list<scalar|null>>
     */
    public function fetchData(string $tableName, array $select, array $filter, int $limit): iterable;
}
