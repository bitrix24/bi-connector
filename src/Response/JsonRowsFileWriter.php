<?php

declare(strict_types=1);

namespace App\Response;

/**
 * Writes rows as a JSON array into a temporary file, encoding one row at a time.
 *
 * The produced bytes are identical to json_encode() over the same list of rows,
 * so the response body stays byte compatible while no more than a single row is held in memory.
 */
class JsonRowsFileWriter
{
    private const FILE_PREFIX = 'rows_';

    private string $directory;
    private int $rowsWritten = 0;

    public function __construct(string $directory)
    {
        $this->directory = $directory;
    }

    /**
     * Writes the rows into a new temporary file and returns its path.
     *
     * The file is removed when writing fails; on success the caller owns the file and must remove it.
     *
     * @param iterable<list<scalar|null>> $rows
     *
     * @throws \RuntimeException
     */
    public function write(iterable $rows): string
    {
        $this->rowsWritten = 0;

        $path = tempnam($this->directory, self::FILE_PREFIX);

        if ($path === false) {
            throw new \RuntimeException('Unable to create a temporary file in directory: ' . $this->directory);
        }

        $stream = fopen($path, 'wb');

        if ($stream === false) {
            unlink($path);

            throw new \RuntimeException('Unable to open the temporary file: ' . $path);
        }

        try {
            $this->writeRows($stream, $rows);
        } catch (\Throwable $e) {
            fclose($stream);
            unlink($path);

            throw $e;
        }

        fclose($stream);

        return $path;
    }

    /**
     * Number of data rows written, the leading column-name row excluded.
     *
     * Nothing written at all gives -1: the value the caller logged before rows became a stream.
     */
    public function getDataRowCount(): int
    {
        return $this->rowsWritten - 1;
    }

    /**
     * @param resource $stream
     * @param iterable<list<scalar|null>> $rows
     */
    private function writeRows($stream, iterable $rows): void
    {
        $this->writeChunk($stream, '[');

        foreach ($rows as $row) {
            if ($this->rowsWritten > 0) {
                $this->writeChunk($stream, ',');
            }

            $encoded = json_encode($row);

            if ($encoded === false) {
                throw new \RuntimeException(sprintf(
                    'Unable to encode row %d: %s',
                    $this->rowsWritten,
                    json_last_error_msg()
                ));
            }

            $this->writeChunk($stream, $encoded);
            $this->rowsWritten++;
        }

        $this->writeChunk($stream, ']');
    }

    /**
     * @param resource $stream
     */
    private function writeChunk($stream, string $chunk): void
    {
        $written = fwrite($stream, $chunk);

        if ($written === false || $written < strlen($chunk)) {
            throw new \RuntimeException('Unable to write the response body to the temporary file');
        }
    }
}
