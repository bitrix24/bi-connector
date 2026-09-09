<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

use App\Config\Boundary;
use App\DataSource\RowLimit;
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
    // field values, so four megabytes of a single line is already far beyond any answer of a source.
    private const DEFAULT_MAX_LINE_BYTES = 4194304;

    // The boundary of the whole body follows the boundary on the number of rows, so that lowering the one
    // lowers the other and a legitimate answer is not stopped by the byte boundary long before the row
    // boundary. The width is what the pair is tuned with: rows wider than this need it raised.
    private const DEFAULT_EXPECTED_ROW_BYTES = 1024;

    /** @var resource|null */
    private $stream;

    /** @var list<string> */
    private array $columnNames;

    /** @var list<string> */
    private array $columnTypes;

    private LoggerInterface $logger;
    private int $maxLineBytes;
    private int $maxResponseBytes;
    private int $maxResultRows;
    private int $expectedRowBytes;
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
        $this->maxLineBytes = Boundary::readPositiveInt(
            'CLICKHOUSE_MAX_LINE_BYTES',
            self::DEFAULT_MAX_LINE_BYTES
        );
        $this->maxResultRows = RowLimit::fromEnvironment()->getMaximum();
        $this->expectedRowBytes = Boundary::readPositiveInt(
            'CLICKHOUSE_EXPECTED_ROW_BYTES',
            self::DEFAULT_EXPECTED_ROW_BYTES
        );
        $this->maxResponseBytes = Boundary::readPositiveInt(
            'CLICKHOUSE_MAX_RESPONSE_BYTES',
            $this->maxResultRows * $this->expectedRowBytes
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
     * @return list<scalar|null>|null values of the row, or null once the data is exhausted
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

        return $this->alignRow($this->decodeLine($line, count($this->columnNames)));
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

        $decoded = $this->decodeLine($line, null);
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
     * message of the failure alone. Taking such a line for data is what hands a truncated answer to the
     * portal under a successful status, so a line of a single value is read as a failure as soon as it
     * carries the display text of a ClickHouse exception.
     *
     * How far that reading is certain depends on the width of the answer. Under a header of more than one
     * column a list of a single value is no data row at all -- this format writes a value per column --
     * so such a line ends the reading whatever it carries. Under a header of exactly one column the
     * failure has the width of a data row, and the body holds nothing else to tell the two apart: the
     * answer already carries the successful status and its headers, and a run against 24.8 shows the
     * appended reason arriving inside a body that ends as a complete one, with neither
     * `X-ClickHouse-Exception-Code` nor any other trailer after it. A single column answer whose values
     * are themselves ClickHouse failure texts -- a table of stored log messages -- therefore ends with
     * such a value reported as a failure. The limitation is written down in the README.
     *
     * @param int|null $columnCount width of the answer, or null while the header itself is being read
     *
     * @return list<mixed>
     *
     * @throws ClickHouseQueryException
     */
    private function decodeLine(string $line, ?int $columnCount): array
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

        if ($columnCount !== null && $columnCount > 1 && count($values) === 1) {
            $this->failOnUnreadableLine($line);
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
            $chunk = $this->readChunk();

            if ($chunk === null) {
                return $line === '' ? null : $line;
            }

            $this->bytesRead += strlen($chunk);
            $line .= $chunk;

            if ($this->bytesRead > $this->maxResponseBytes) {
                $this->close();

                throw new ClickHouseQueryException(sprintf(
                    'The answer of ClickHouse is longer than the %d bytes the application reads '
                    . '(%d rows at %d bytes each).',
                    $this->maxResponseBytes,
                    $this->maxResultRows,
                    $this->expectedRowBytes
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
     * Reads the next piece of the body, telling the end of the data apart from a failure of the reading.
     *
     * A connection that is lost while the answer is still arriving reaches the reader the only way a stream
     * wrapper can report it: `fgets()` hands back false and a warning is raised, and the stream calls itself
     * exhausted afterwards exactly as it does at the end of a complete body. Reading that as the end of the
     * data is what hands a truncated answer to the caller under a successful status, so the warning is what
     * the two are told apart by: it is caught for the length of the read alone and turns into a failure of
     * the statement.
     *
     * @return string|null the piece that was read, or null once the body is exhausted
     *
     * @throws ClickHouseQueryException when the body could not be read to its end
     */
    private function readChunk(): ?string
    {
        $stream = $this->stream;

        if ($stream === null) {
            return null;
        }

        $failure = null;
        set_error_handler(static function (int $severity, string $message) use (&$failure): bool {
            $failure = $message;

            return true;
        });

        try {
            $chunk = fgets($stream, self::READ_CHUNK_BYTES + 1);
        } finally {
            restore_error_handler();
        }

        if ($failure !== null) {
            $this->close();

            throw new ClickHouseQueryException(sprintf(
                'The answer of ClickHouse broke off before its end: %s',
                $failure
            ));
        }

        return $chunk === false ? null : $chunk;
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
     * Brings a row to the width of the header: a short row is filled up with nulls, so that a value is
     * always read under the column it belongs to. The values are brought to their published shape in the
     * same pass, because a second walk over every row of a large answer costs as much as the first one.
     *
     * @param list<mixed> $values
     *
     * @return list<scalar|null>
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
     * false into an empty string. Numbers keep the shape the parsing gave them: a whole number and a
     * decimal both arrive as exact text and a cast would round them away. Only a value that has no scalar
     * form at all, the one of an Array or a Map column, becomes text, and such a column is described as a
     * string anyway.
     */
    private static function normalizeValue(mixed $value): string|int|float|null
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null || is_scalar($value)) {
            return $value;
        }

        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $encoded === false ? null : $encoded;
    }
}
