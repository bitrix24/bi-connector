<?php

declare(strict_types=1);

namespace App\Connector;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Client of the `biconnector.connector.*` REST methods of a portal.
 *
 * The class owns the exchange with the portal: the address of a method, the authorisation token and the
 * reading of an answer. Deciding what a connector looks like belongs to the caller.
 *
 * None of the methods throws. An unreachable portal, a failing status and an unreadable body all end the
 * same way: the answer is reported as absent, the caller degrades and the installation carries on.
 */
final class ConnectorApiClient
{
    public const METHOD_LIST = 'biconnector.connector.list';
    public const METHOD_FIELDS = 'biconnector.connector.fields';
    public const METHOD_ADD = 'biconnector.connector.add';
    public const METHOD_UPDATE = 'biconnector.connector.update';

    private const TIMEOUT_SECONDS = 30;

    private const ERROR_CODE_MAX_LENGTH = 128;

    private string $domain;
    private string $accessToken;
    private LoggerInterface $logger;
    private HttpClientInterface $httpClient;

    /**
     * @param string $domain portal domain, without a scheme
     * @param string $accessToken authorisation token; it travels in the body and never reaches the log
     * @param HttpClientInterface|null $httpClient transport to send through; the default one is built here
     */
    public function __construct(
        string $domain,
        #[\SensitiveParameter] string $accessToken,
        LoggerInterface $logger,
        ?HttpClientInterface $httpClient = null
    ) {
        $this->domain = $domain;
        $this->accessToken = $accessToken;
        $this->logger = $logger;
        $this->httpClient = $httpClient ?? HttpClient::create();
    }

    /**
     * Connectors the portal already holds for this application.
     *
     * @return list<array<string, mixed>>
     */
    public function getConnectors(): array
    {
        $decoded = $this->call(self::METHOD_LIST, []);
        $result = $decoded['result'] ?? null;

        if (!is_array($result)) {
            return [];
        }

        $connectors = [];

        foreach ($result as $connector) {
            if (is_array($connector)) {
                $connectors[] = $connector;
            }
        }

        return $connectors;
    }

    /**
     * Field names the portal accepts in a connector description.
     *
     * A field the portal does not know is rejected by the whole call with `VALIDATION_UNKNOWN_PARAMETERS`,
     * which would take the already working connectors down with it. Asking first is what keeps a newer
     * application working against an older portal.
     *
     * @return list<string>|null the known names, or null when the portal did not answer with a readable set
     */
    public function getSupportedFieldNames(): ?array
    {
        $decoded = $this->call(self::METHOD_FIELDS, []);
        $result = $decoded['result'] ?? null;

        if (!is_array($result)) {
            return null;
        }

        $fields = $result['fields'] ?? null;

        if (!is_array($fields)) {
            return null;
        }

        $names = [];

        foreach ($fields as $field) {
            // The portal carries the name of a field in the `title` key of its description.
            $name = is_array($field) ? ($field['title'] ?? null) : null;

            if (is_string($name) && $name !== '') {
                $names[] = $name;
            }
        }

        // An answer that parsed but named nothing tells as little as no answer at all.
        return $names === [] ? null : $names;
    }

    /**
     * @param array<string, mixed> $fields connector description; only names the portal knows belong here
     */
    public function addConnector(array $fields): void
    {
        $this->call(self::METHOD_ADD, ['fields' => $fields]);
    }

    /**
     * @param array<string, mixed> $fields connector description; only names the portal knows belong here
     */
    public function updateConnector(int $connectorId, array $fields): void
    {
        $this->call(self::METHOD_UPDATE, ['id' => $connectorId, 'fields' => $fields]);
    }

    /**
     * Sends one REST method and hands the parsed body over.
     *
     * @param array<string, mixed> $payload method parameters; the authorisation token is added here
     *
     * @return array<string, mixed>|null parsed body, or null when the call failed or did not parse
     */
    private function call(string $method, array $payload): ?array
    {
        $url = sprintf('https://%s/rest/%s', $this->domain, $method);
        $body = $payload;
        $body['auth'] = $this->accessToken;

        try {
            $response = $this->httpClient->request('POST', $url, [
                'body' => $body,
                // A redirect is not followed: the authorisation token travels in the body and must not be
                // resent to an address chosen by the answer.
                'max_redirects' => 0,
                'timeout' => self::TIMEOUT_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (Throwable $throwable) {
            // The message of a transport failure carries the address and never the body, so no token
            // reaches the log through it.
            $this->logger->error('ConnectorApiClient.call.transportFailure', [
                'method' => $method,
                'message' => $throwable->getMessage(),
            ]);

            return null;
        }

        $decoded = json_decode($content, true);
        $parsed = is_array($decoded) ? $decoded : null;

        // The body as a whole never reaches the log: it may carry values of the portal that are no more
        // ours to store than the token is. Only the status and the error code of the portal are kept.
        $this->logger->info('ConnectorApiClient.call', [
            'method' => $method,
            'httpCode' => $statusCode,
            'isParsed' => $parsed !== null,
            'error' => self::extractErrorCode($parsed),
        ]);

        if ($statusCode < 200 || $statusCode >= 300) {
            return null;
        }

        return $parsed;
    }

    /**
     * Error code of the portal, if the answer named one.
     *
     * The REST layer reports a refused call at the top level, while the connector methods report their own
     * refusals inside `result`, so both places are looked at.
     *
     * @param array<string, mixed>|null $decoded
     */
    private static function extractErrorCode(?array $decoded): ?string
    {
        if ($decoded === null) {
            return null;
        }

        $candidates = [$decoded['error'] ?? null];
        $result = $decoded['result'] ?? null;

        if (is_array($result)) {
            $candidates[] = $result['error'] ?? null;
        }

        foreach ($candidates as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['error'] ?? null;
            }

            if (is_string($candidate) && $candidate !== '') {
                return mb_substr($candidate, 0, self::ERROR_CODE_MAX_LENGTH);
            }
        }

        return null;
    }
}
