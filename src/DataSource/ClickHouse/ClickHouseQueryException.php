<?php

declare(strict_types=1);

namespace App\DataSource\ClickHouse;

/**
 * A statement that the source refused to finish.
 *
 * The failure is told apart from a transport failure on purpose: the answer already carried a successful
 * status and a header, and the reason arrived inside the body afterwards.
 */
final class ClickHouseQueryException extends \RuntimeException
{
    // A failure is written by the server as its own display text: the numeric code, the class of the
    // exception and the message. Matching the class loosely keeps the subclasses of DB::Exception, such as
    // DB::NetException, recognizable.
    private const FAILURE_TEXT_AT_START_PATTERN = '/^Code: \d+\. DB::\w*Exception/';

    // The opening of a line the output format has already begun to write: the display text of the failure
    // replaces the answer and becomes the only value of the list.
    private const JSON_LIST_PREFIX = '["';

    private const FAILURE_TEXT_MAX_LENGTH = 4096;

    /**
     * The text of a failure of ClickHouse when the whole text is one, or null when it belongs to something
     * else.
     *
     * The address of the source is chosen by the caller, so an answer may belong to any service reachable
     * from the network of the application and must not be handed back as it is. The display text of a
     * failure is the one exception: it is what a failed statement reports its reason with, and no other
     * service writes it. The text is shortened to a length a message can carry.
     */
    public static function readFailureText(string $text): ?string
    {
        if (preg_match(self::FAILURE_TEXT_AT_START_PATTERN, $text) !== 1) {
            return null;
        }

        return substr($text, 0, self::FAILURE_TEXT_MAX_LENGTH);
    }

    /**
     * The text of a failure of ClickHouse carried by the body of a failed answer, or null when the body
     * carries none.
     *
     * The body takes one of two shapes. A statement that fails before the output format has written
     * anything is answered with the display text alone. A statement that fails once the format has already
     * written its opening lines is answered with those lines and the display text as the only value of a
     * JSON list after them. Both are read line by line and the text is pinned to the beginning of a line,
     * so a text that travelled out inside the request and came back inside a body of a service that
     * reflects what it is sent cannot pass for the reason of a failure.
     */
    public static function readFailureTextFromBody(string $body): ?string
    {
        foreach (preg_split('/\R/', $body) ?: [] as $line) {
            $failureText = self::readFailureTextFromLine(trim($line));

            if ($failureText !== null) {
                return $failureText;
            }
        }

        return null;
    }

    private static function readFailureTextFromLine(string $line): ?string
    {
        $failureText = self::readFailureText($line);

        if ($failureText !== null) {
            return $failureText;
        }

        $decoded = json_decode($line, true);

        if (is_array($decoded) && array_is_list($decoded) && count($decoded) === 1 && is_string($decoded[0])) {
            return self::readFailureText($decoded[0]);
        }

        // The body is read only up to a boundary, so a long display text may be cut in the middle and stop
        // decoding as JSON. It still begins where the list does.
        if (str_starts_with($line, self::JSON_LIST_PREFIX)) {
            return self::readFailureText(substr($line, strlen(self::JSON_LIST_PREFIX)));
        }

        return null;
    }
}
