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
    private const FAILURE_TEXT_PATTERN = '/Code: \d+\. DB::\w*Exception/';

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
     * The text of a failure of ClickHouse carried somewhere inside a body, or null when the body carries
     * none.
     *
     * A statement that fails while its answer is still being put together is answered with the part of the
     * result the server had already written and the display text of the failure after it, so the text is
     * looked for and not expected at the start. Everything written before it stays inside the application.
     */
    public static function findFailureText(string $body): ?string
    {
        if (preg_match(self::FAILURE_TEXT_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE) !== 1) {
            return null;
        }

        return substr($body, (int)$matches[0][1], self::FAILURE_TEXT_MAX_LENGTH);
    }
}
