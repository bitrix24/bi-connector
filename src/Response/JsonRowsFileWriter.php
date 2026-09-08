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

    // A file of a request that was stopped between the writing and the sending is left behind: neither the
    // catch block nor the sending runs when the process is killed. Such a file is swept away on the next
    // request once it is older than this, an age no request that is still being served can reach.
    private const DEFAULT_MAX_AGE_SECONDS = 3600;

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
        $this->removeStaleFiles();

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
     * Nothing written at all gives -1, the count an answer without rows carries in the log.
     */
    public function getDataRowCount(): int
    {
        return $this->rowsWritten - 1;
    }

    /**
     * Removes the files of the requests that never got to send or to drop their own.
     */
    private function removeStaleFiles(): void
    {
        $maxAge = (int)($_ENV['ROWS_FILE_MAX_AGE_SECONDS'] ?? self::DEFAULT_MAX_AGE_SECONDS);

        if ($maxAge <= 0) {
            return;
        }

        $paths = glob($this->directory . '/' . self::FILE_PREFIX . '*');

        if ($paths === false) {
            return;
        }

        $deadline = time() - $maxAge;

        foreach ($paths as $path) {
            // A file may be taken away by another request between the listing and the reading of its age,
            // so neither step is allowed to raise here.
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false || $modifiedAt > $deadline) {
                continue;
            }

            @unlink($path);
        }
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
