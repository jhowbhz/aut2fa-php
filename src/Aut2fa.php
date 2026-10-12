<?php

declare(strict_types=1);

namespace Aut2fa;

use InvalidArgumentException;
use JsonException;
use stdClass;

/** Sends aut2fa requests with the application's credentials. */
final class Aut2fa
{
    private const MAX_IDEMPOTENCY_KEY_BYTES = 255;
    // cURL's millisecond option must also fit a signed 32-bit long on Windows.
    private const MAX_TIMEOUT_SECONDS = 2147483.647;

    private string $apiKey;
    private string $baseUrl;
    private float $timeout;

    public function __construct(string $apiKey, array $options = [])
    {
        if (trim($apiKey) === '' || preg_match('/[\x00-\x1F\x7F]/', $apiKey)) {
            throw new InvalidArgumentException('The application key must be nonempty and contain no control characters.');
        }
        if (array_diff(array_keys($options), ['baseUrl', 'timeout']) !== []) {
            throw new InvalidArgumentException('Supported client options are baseUrl and timeout.');
        }

        $timeout = array_key_exists('timeout', $options) ? $options['timeout'] : 10;
        if ((!is_int($timeout) && !is_float($timeout)) || !is_finite((float) $timeout) || $timeout <= 0 || $timeout > self::MAX_TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('The timeout must be a finite, positive number of seconds within cURL\'s supported range.');
        }

        $this->apiKey = $apiKey;
        $this->baseUrl = $this->validateBaseUrl(array_key_exists('baseUrl', $options) ? $options['baseUrl'] : 'https://api.aut2fa.com');
        $this->timeout = (float) $timeout;
    }

    public function send(array $params, ?string $idempotencyKey = null): stdClass
    {
        $this->requireString($params, 'to');
        foreach (['code', 'locale'] as $field) {
            if (array_key_exists($field, $params) && !is_string($params[$field])) {
                throw new InvalidArgumentException($field . ' must be a string when provided.');
            }
        }
        if ($idempotencyKey !== null && (strlen($idempotencyKey) > self::MAX_IDEMPOTENCY_KEY_BYTES || preg_match('/[\x00-\x1F\x7F]/', $idempotencyKey))) {
            throw new InvalidArgumentException('Idempotency-Key must contain no control characters and be at most 255 bytes.');
        }

        return $this->request('POST', '/v1/send', $params, $idempotencyKey);
    }

    public function status(string $id): stdClass
    {
        $this->validateId($id);
        return $this->request('GET', '/v1/send/' . $id . '/status');
    }

    public function verify(string $id, array $params): stdClass
    {
        $this->validateId($id);
        $this->requireString($params, 'code');
        return $this->request('POST', '/v1/send/' . $id . '/verify', $params);
    }

    private function request(string $method, string $path, ?array $params = null, ?string $idempotencyKey = null): stdClass
    {
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json',
        ];
        $handle = curl_init($this->baseUrl . $path);
        $retryAfter = null;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => (int) ceil($this->timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$retryAfter): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $retryAfter = null;
                } elseif (stripos($line, 'retry-after:') === 0) {
                    $retryAfter = trim(explode(':', $line, 2)[1]);
                }

                return strlen($line);
            },
        ];
        if ($params !== null) {
            $headers[] = 'Content-Type: application/json';
            try {
                $options[CURLOPT_POSTFIELDS] = json_encode((object) $params, JSON_THROW_ON_ERROR);
            } catch (JsonException $error) {
                throw new InvalidArgumentException('Request parameters must be encodable as JSON.', 0, $error);
            }
        }
        if ($idempotencyKey !== null) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }
        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($handle, $options);

        $body = curl_exec($handle);
        if ($body === false) {
            throw new NetworkError(curl_errno($handle), 'HTTP transport failed: ' . curl_error($handle));
        }
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $response = json_decode($body);
        if ($status < 200 || $status >= 300) {
            throw $this->apiError($status, $response, $retryAfter);
        }

        return $this->validateResponse($response, $status, str_ends_with($path, '/verify'));
    }

    private function apiError(int $status, mixed $response, ?string $retryAfter): ApiError
    {
        $detail = 'HTTP request failed with status ' . $status . '.';
        if (
            $response instanceof stdClass
            && isset($response->code)
            && is_string($response->code)
            && $response->code !== ''
            && (!property_exists($response, 'detail') || is_string($response->detail))
        ) {
            return new ApiError($status, $response->code, $response->detail ?? $detail, $response->errors ?? null, $retryAfter);
        }

        return new ApiError($status, 'http_error', $detail, null, $retryAfter);
    }

    private function validateBaseUrl(mixed $baseUrl): string
    {
        if (!is_string($baseUrl) || str_contains($baseUrl, '\\') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('baseUrl must be an absolute HTTP or HTTPS URL.');
        }
        $parts = parse_url($baseUrl);
        if (
            !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || !isset($parts['host'])
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || array_key_exists('query', $parts)
            || array_key_exists('fragment', $parts)
            || (isset($parts['port']) && $parts['port'] === 0)
        ) {
            throw new InvalidArgumentException('baseUrl must use HTTP or HTTPS and contain no credentials, query, or fragment.');
        }

        return rtrim($baseUrl, '/');
    }

    private function validateId(string $id): void
    {
        if (!preg_match('/\A[A-Za-z0-9_-]+\z/', $id)) {
            throw new InvalidArgumentException('The send ID must contain only ASCII letters, numbers, underscores, and hyphens.');
        }
    }

    private function requireString(array $params, string $field): void
    {
        if (!isset($params[$field]) || !is_string($params[$field]) || trim($params[$field]) === '') {
            throw new InvalidArgumentException($field . ' must be a nonempty string.');
        }
    }

    private function validateResponse(mixed $response, int $status, bool $verification): stdClass
    {
        if (!$response instanceof stdClass) {
            throw new InvalidResponseError($status);
        }
        if ($verification) {
            if (
                !is_bool($response->valid ?? null)
                || !is_string($response->verification ?? null)
                || $response->verification === ''
                || !is_int($response->attempts_left ?? null)
                || $response->attempts_left < 0
            ) {
                throw new InvalidResponseError($status);
            }
        } elseif (
            !is_string($response->id ?? null)
            || $response->id === ''
            || !is_string($response->status ?? null)
            || $response->status === ''
        ) {
            throw new InvalidResponseError($status);
        }

        return $response;
    }
}
