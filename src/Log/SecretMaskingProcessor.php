<?php

declare(strict_types=1);

namespace App\Log;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Replaces the values of credential bearing context keys before a record reaches a handler.
 *
 * Masking happens for every record regardless of its level, so no call site can leak a secret by
 * passing it in the context.
 */
final class SecretMaskingProcessor implements ProcessorInterface
{
    public const MASK = '***';

    // Guards against a self referencing context array, which would otherwise be walked forever.
    private const MAX_DEPTH = 16;

    /**
     * Key names whose value never reaches the log. Stored lowercase: lookup is case insensitive.
     *
     * @var list<string>
     */
    private const SENSITIVE_KEYS = [
        'password',
        'connection',
        'auth',
        'accesstoken',
        'access_token',
        'refreshtoken',
        'refresh_token',
    ];

    public function __invoke(LogRecord $record): LogRecord
    {
        return $record->with(context: $this->maskValues($record->context, 1));
    }

    /**
     * @param array<array-key, mixed> $values
     *
     * @return array<array-key, mixed>
     */
    private function maskValues(array $values, int $depth): array
    {
        $masked = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitive($key)) {
                $masked[$key] = self::MASK;
                continue;
            }

            if (is_array($value)) {
                $masked[$key] = $depth < self::MAX_DEPTH ? $this->maskValues($value, $depth + 1) : self::MASK;
                continue;
            }

            $masked[$key] = $value;
        }

        return $masked;
    }

    private function isSensitive(string $key): bool
    {
        return in_array(strtolower($key), self::SENSITIVE_KEYS, true);
    }
}
