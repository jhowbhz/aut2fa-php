<?php

declare(strict_types=1);

namespace Aut2fa\Tests;

use Aut2fa\ApiError;
use Aut2fa\Aut2fa;
use Aut2fa\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guards rejection details and single attempts: accidental retries can duplicate sends or consume verification attempts. */
final class ApiErrorsTest extends TestCase
{
    private LocalHttpServer $server;

    protected function setUp(): void
    {
        $this->server = new LocalHttpServer();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
    }

    #[DataProvider('problemResponses')]
    public function testProblemResponsesUseTheActualHttpStatus(int $status, string $code): void
    {
        $this->server->respond($status, json_encode([
            'type' => 'https://aut2fa.com/docs/errors#' . $code,
            'title' => 'Rejected',
            'status' => 200,
            'code' => $code,
            'detail' => 'This request cannot be processed.',
            'errors' => ['to' => 'Recipient is invalid.', 'future_field' => null],
        ], JSON_THROW_ON_ERROR), ['Content-Type' => 'application/problem+json', 'Retry-After' => '12']);

        $error = $this->sendError();

        self::assertSame($status, $error->status);
        self::assertSame($code, $error->code);
        self::assertSame('This request cannot be processed.', $error->detail);
        self::assertSame('Recipient is invalid.', $error->errors->to);
        self::assertNull($error->errors->future_field);
        self::assertSame('12', $error->retryAfter);
        self::assertSame('This request cannot be processed.', $error->getMessage());
        self::assertCount(1, $this->server->requests());
    }

    public static function problemResponses(): array
    {
        return [
            'unauthorized' => [401, 'invalid_key'],
            'payment required' => [402, 'insufficient_credit'],
            'idempotency conflict' => [409, 'idempotency_conflict'],
            'validation error' => [422, 'invalid_recipient'],
            'rate limited' => [429, 'rate_limited'],
            'server error' => [500, 'internal_error'],
            'service unavailable' => [503, 'service_unavailable'],
        ];
    }

    #[DataProvider('unexpectedErrorBodies')]
    public function testUnexpectedErrorBodiesDoNotExposeArbitraryContent(string $body): void
    {
        $this->server->respond(502, $body, ['Content-Type' => 'text/html', 'Retry-After' => '5']);

        $error = $this->sendError();

        self::assertSame(502, $error->status);
        self::assertSame('http_error', $error->code);
        self::assertSame('HTTP request failed with status 502.', $error->detail);
        self::assertNull($error->errors);
        self::assertSame('5', $error->retryAfter);
        self::assertStringNotContainsString('PRIVATE_CONTENT', $error->getMessage());
        self::assertCount(1, $this->server->requests());
    }

    public static function unexpectedErrorBodies(): array
    {
        return [
            'HTML proxy error' => ['<html>PRIVATE_CONTENT</html>'],
            'truncated JSON' => ['{"code":"PRIVATE_CONTENT"'],
            'JSON list' => ['["PRIVATE_CONTENT"]'],
            'unrelated JSON object' => ['{"message":"PRIVATE_CONTENT"}'],
            'wrong code type' => ['{"code":123,"detail":"PRIVATE_CONTENT"}'],
            'wrong detail type' => ['{"code":"failure","detail":["PRIVATE_CONTENT"]}'],
        ];
    }

    public function testRetryAfterHttpDatesRemainUnmodified(): void
    {
        $this->server->respond(429, '{"code":"rate_limited","detail":"Wait before sending."}', [
            'Content-Type' => 'application/problem+json',
            'rEtRy-AfTeR' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        ]);

        $error = $this->sendError();

        self::assertSame('Wed, 21 Oct 2026 07:28:00 GMT', $error->retryAfter);
        self::assertNull($error->errors);
    }

    public function testRedirectsNeverReceiveTheApplicationKeyAtTheirTarget(): void
    {
        $target = new LocalHttpServer();
        try {
            $this->server->respond(307, '', ['Location' => $target->baseUrl . '/redirect-target']);

            $error = $this->sendError();

            self::assertSame(307, $error->status);
            self::assertSame('http_error', $error->code);
            self::assertNull($error->retryAfter);
            self::assertCount(1, $this->server->requests());
            self::assertSame([], $target->requests());
        } finally {
            $target->stop();
        }
    }

    public function testRejectedVerificationIsAttemptedOnlyOnce(): void
    {
        $this->server->respond(503, '{"code":"service_unavailable","detail":"Try again later."}', [
            'Content-Type' => 'application/problem+json',
        ]);
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);

        try {
            $client->verify('snd_8Kq2wR7tXz', ['code' => '004829']);
            self::fail('A rejected verification must raise ApiError.');
        } catch (ApiError $error) {
            self::assertSame(503, $error->status);
            self::assertCount(1, $this->server->requests());
        }
    }

    private function sendError(): ApiError
    {
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);
        try {
            $client->send(['to' => '+5531999999999']);
            self::fail('A rejected send must raise ApiError.');
        } catch (ApiError $error) {
            return $error;
        }
    }
}
