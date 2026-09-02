<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

/**
 * Reader over the body of a successful ClickHouse answer.
 *
 * The body arrives in the JSONCompactEachRowWithNamesAndTypes format: the first line carries the column
 * names, the second one the ClickHouse type names, every line after that is one data row. The body is read
 * line by line and is never collected into a string, so the size of the answer does not become the size of
 * the memory the request needs.
 */
final class ClickHouseRowStream
{
    private const JSON_DEPTH = 512;

    // A failure that reaches the body after the answer has started is written by the server as its own
    // display text: the numeric code, the class of the exception and the message. Matching the class
    // loosely keeps the subclasses of DB::Exception, such as DB::NetException, recognizable.
    private const FAILURE_MESSAGE_PATTERN = '/^Code: \d+\. DB::\w*Exception/';

    /** @var resource|null */
    private $stream;

    /** @var list<string> */
    private array $columnNames;

    /** @var list<string> */
    private array $columnTypes;

    /**
     * @param resource $stream body of the answer, positioned at the first line
     *
     * @throws ClickHouseQueryException when the body carries a failure instead of the header
     */
    public function __construct($stream)
    {
        if (!is_resource($stream)) {
            throw new \InvalidArgumentException('ClickHouseRowStream needs an open stream to read from.');
        }

        $this->stream = $stream;
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

        while (($line = fgets($this->stream)) !== false) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            return $this->alignRow($this->decodeLine($line));
        }

        $this->close();

        return null;
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
                $this->close();

                throw new ClickHouseQueryException($line);
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
            $this->close();

            throw new ClickHouseQueryException($line);
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

        return preg_match(self::FAILURE_MESSAGE_PATTERN, $values[0]) === 1 ? $values[0] : null;
    }

    private function readNonEmptyLine(): ?string
    {
        if ($this->stream === null) {
            return null;
        }

        while (($line = fgets($this->stream)) !== false) {
            $line = trim($line);

            if ($line !== '') {
                return $line;
            }
        }

        return null;
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
