<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Reader over the body of a successful ClickHouse answer.
 *
 * The body arrives in the JSONCompactEachRowWithNamesAndTypes format: the first line carries the column
 * names, the second one the ClickHouse type names, every line after that is one data row. The body is read
 * one line at a time, so the memory a request needs is bounded by the longest line and not by the size of
 * the answer. Both boundaries are held here and not left to the source: the settings of the statement are
 * kept by a well behaved ClickHouse alone, while the address of the source is chosen by the caller.
 */
final class ClickHouseRowStream
{
    private const JSON_DEPTH = 512;

    // fgets() allocates the whole limit it is given, so the reading walks the line in chunks of this size
    // instead of asking for the boundary of the line at once.
    private const READ_CHUNK_BYTES = 65536;

    // How much of an unreadable line reaches the log. The line itself belongs to the answer of a source the
    // caller has chosen and never leaves the application.
    private const LOG_FRAGMENT_LENGTH = 512;

    // Boundaries of the reading, both overridable through the environment. A row of a report is a list of
    // field values, so four megabytes of a single line is already far beyond any answer of a source, and a
    // gigabyte of a body matches the default cap of a million rows at a kilobyte each.
    private const DEFAULT_MAX_LINE_BYTES = 4194304;
    private const DEFAULT_MAX_RESPONSE_BYTES = 1073741824;

    /** @var resource|null */
    private $stream;

    /** @var list<string> */
    private array $columnNames;

    /** @var list<string> */
    private array $columnTypes;

    private LoggerInterface $logger;
    private int $maxLineBytes;
    private int $maxResponseBytes;
    private int $bytesRead = 0;

    /**
     * @param resource $stream body of the answer, positioned at the first line
     *
     * @throws ClickHouseQueryException when the body carries a failure instead of the header
     */
    public function __construct($stream, ?LoggerInterface $logger = null)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('ClickHouseRowStream needs an open stream to read from.');
        }

        $this->stream = $stream;
        $this->logger = $logger ?? new NullLogger();
        $this->maxLineBytes = self::readBoundaryFromEnvironment(
            'CLICKHOUSE_MAX_LINE_BYTES',
            self::DEFAULT_MAX_LINE_BYTES
        );
        $this->maxResponseBytes = self::readBoundaryFromEnvironment(
            'CLICKHOUSE_MAX_RESPONSE_BYTES',
            self::DEFAULT_MAX_RESPONSE_BYTES
        );
        $this->columnNames = $this->readHeaderLine('column names');
        $this->columnTypes = $this->readHeaderLine('column types');
    }

    /**
     * The stream belongs to this object once it has been handed over, so a consumer that stops reading
     * early still releases it.
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        return $this->columnNames;
    }

    /**
     * @return list<string> ClickHouse type names, aligned with the column names
     */
    public function getColumnTypes(): array
    {
        return $this->columnTypes;
    }

    /**
     * Reads the next data row as a list of values in the order of the columns.
     *
     * The values keep the shape the JSON parsing gave them: a number is not cast to a PHP type here, because
     * a whole number that does not fit a float arrives as text and a cast would round it away.
     *
     * @return list<mixed>|null values of the row, or null once the data is exhausted
     *
     * @throws ClickHouseQueryException when the body carries a failure instead of a row
     */
    public function fetchRow(): ?array
    {
        if ($this->stream === null) {
            return null;
        }

        $line = $this->readNonEmptyLine();

        if ($line === null) {
            $this->close();

            return null;
        }

        return $this->alignRow($this->decodeLine($line));
    }

    /**
     * Releases the stream. Calling it more than once is safe.
     */
    public function close(): void
    {
        if ($this->stream === null) {
            return;
        }

        $stream = $this->stream;
        $this->stream = null;

        if (is_resource($stream)) {
            fclose($stream);
        }
    }

    /**
     * @return list<string>
     *
     * @throws ClickHouseQueryException
     */
    private function readHeaderLine(string $expected): array
    {
        $line = $this->readNonEmptyLine();

        if ($line === null) {
            $this->close();

            throw new ClickHouseQueryException(sprintf('The answer of ClickHouse carries no %s.', $expected));
        }

        $decoded = $this->decodeLine($line);
        $names = [];

        foreach ($decoded as $name) {
            if (!is_string($name)) {
                $this->failOnUnreadableLine($line);
            }

            $names[] = $name;
        }

        return $names;
    }

    /**
     * Reads one line of the body as a list of values, and stops the reading when the line carries a
     * failure instead.
     *
     * A failure that the server appends to an answer it has already started to send reaches the body in
     * two shapes. An unreadable line is one of them. The other one is a readable JSON list holding the
     * message of the failure alone, which no data row of this answer format looks like: a data row always
     * carries a value per column, and a value of a source never begins with the display text of a
     * ClickHouse exception. Taking such a line for data is what hands a truncated answer to the portal
     * under a successful status, so the message wins over the shape of the line.
     *
     * @return list<mixed>
     *
     * @throws ClickHouseQueryException
     */
    private function decodeLine(string $line): array
    {
        $values = json_decode($line, true, self::JSON_DEPTH, JSON_BIGINT_AS_STRING);

        if (!is_array($values) || !array_is_list($values)) {
            $this->failOnUnreadableLine($line);
        }

        $message = self::readFailureMessage($values);

        if ($message !== null) {
            $this->close();

            throw new ClickHouseQueryException($message);
        }

        return $values;
    }

    /**
     * @param list<mixed> $values
     *
     * @return string|null the message the line carries, or null when the line is a row of data
     */
    private static function readFailureMessage(array $values): ?string
    {
        if (count($values) !== 1 || !is_string($values[0])) {
            return null;
        }

        return ClickHouseQueryException::readFailureText($values[0]);
    }

    /**
     * @throws ClickHouseQueryException when a boundary of the reading is crossed
     */
    private function readNonEmptyLine(): ?string
    {
        while (($line = $this->readBoundedLine()) !== null) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return null;
    }

    /**
     * Reads one line of the body with both boundaries of the reading applied.
     *
     * Crossing a boundary fails the reading instead of shortening the line: a shortened line would either
     * be unreadable or, worse, pass for a complete row of the answer.
     *
     * @throws ClickHouseQueryException when the line or the whole body outgrows its boundary
     */
    private function readBoundedLine(): ?string
    {
        $line = '';

        while ($this->stream !== null) {
            $chunk = fgets($this->stream, self::READ_CHUNK_BYTES + 1);

            if ($chunk === false) {
                return $line === '' ? null : $line;
            }

            $this->bytesRead += strlen($chunk);
            $line .= $chunk;

            if ($this->bytesRead > $this->maxResponseBytes) {
                $this->close();

                throw new ClickHouseQueryException(sprintf(
                    'The answer of ClickHouse is longer than the %d bytes the application reads.',
                    $this->maxResponseBytes
                ));
            }

            if (strlen($line) > $this->maxLineBytes) {
                $this->close();

                throw new ClickHouseQueryException(sprintf(
                    'A line of the answer of ClickHouse is longer than the %d bytes the application reads.',
                    $this->maxLineBytes
                ));
            }

            if (str_ends_with($chunk, "\n")) {
                return $line;
            }
        }

        return null;
    }

    /**
     * Stops the reading over a line that is not a row of the answer.
     *
     * A failure that ClickHouse writes as plain text is recognized by its display text and is handed back
     * as it is: this is how a failed statement reports its reason. Anything else is a piece of the answer
     * of a source the caller has chosen and stays inside the application, because that source may be any
     * service reachable from the network of the application: a truncated fragment goes to the log and the
     * caller is told the shape of the failure alone.
     *
     * @throws ClickHouseQueryException
     */
    private function failOnUnreadableLine(string $line): never
    {
        $this->close();
        $failureText = ClickHouseQueryException::readFailureText($line);

        if ($failureText !== null) {
            throw new ClickHouseQueryException($failureText);
        }

        $this->logger->error('ClickHouseRowStream.unreadableLine', [
            'class' => self::class,
            'method' => 'failOnUnreadableLine',
            'fragment' => substr($line, 0, self::LOG_FRAGMENT_LENGTH),
        ]);

        throw new ClickHouseQueryException('The answer of ClickHouse could not be read.');
    }

    /**
     * A boundary is never left unbounded: a missing, unreadable or non positive setting falls back to the
     * default of the application.
     */
    private static function readBoundaryFromEnvironment(string $name, int $default): int
    {
        $value = (int)($_ENV[$name] ?? $default);

        return $value > 0 ? $value : $default;
    }

    /**
     * Brings a row to the width of the header: a short row is filled up with nulls, so that a value is
     * always read under the column it belongs to.
     *
     * @param list<mixed> $values
     *
     * @return list<mixed>
     */
    private function alignRow(array $values): array
    {
        $row = [];
        $width = count($this->columnNames);

        for ($index = 0; $index < $width; $index++) {
            $row[] = self::normalizeValue($values[$index] ?? null);
        }

        return $row;
    }

    /**
     * A Bool column arrives as a JSON boolean while it is published as text, and a PHP cast would turn
     * false into an empty string.
     */
    private static function normalizeValue(mixed $value): mixed
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return $value;
    }
}
