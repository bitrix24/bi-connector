<?php

declare(strict_types=1);

namespace App\DataSource;

use App\DataSource\Dbal\DbalDataSource;
use Psr\Log\LoggerInterface;

/**
 * Resolves the data source implementation serving a `connection_type` request value.
 */
final class DataSourceFactory
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<string, mixed> $connectionParams
     *
     * @throws \InvalidArgumentException when the connection type is outside ConnectionType
     */
    public function create(string $connectionType, array $connectionParams): DataSourceInterface
    {
        $type = ConnectionType::tryFrom($connectionType) ?? throw new \InvalidArgumentException(sprintf(
            'Unsupported connection type "%s"; supported types: %s',
            $connectionType,
            implode(', ', ConnectionType::values())
        ));

        $this->logger->debug('DataSourceFactory.create', [
            'class' => self::class,
            'method' => 'create',
            'connectionType' => $type->value,
            'connectionParams' => array_keys($connectionParams)
        ]);

        return match ($type) {
            ConnectionType::Mysql,
            ConnectionType::Postgresql => new DbalDataSource($connectionParams, $type, $this->logger),
            default => throw new \RuntimeException(
                sprintf('No data source implementation is registered for connection type "%s"', $type->value)
            )
        };
    }
}
