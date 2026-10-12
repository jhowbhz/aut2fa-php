<?php

declare(strict_types=1);

namespace Aut2fa\Tests;

use Aut2fa\Aut2fa;
use Aut2fa\InvalidResponseError;
use Aut2fa\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guards success shapes so a proxy or incompatible API cannot be mistaken for a created send or a verified code. */
final class InvalidResponsesTest extends TestCase
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

    #[DataProvider('invalidSendBodies')]
    public function testSendAndStatusRejectInvalidSuccessfulResponses(string $body): void
    {
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);
        foreach (['send' => 202, 'status' => 200] as $method => $status) {
            $this->server->respond($status, $body);
            $error = $this->invalidResponse(fn () => $method === 'send' ? $client->send(['to' => '+5531999999999']) : $client->status('snd_8Kq2wR7tXz'));
            self::assertSame($status, $error->status);
            self::assertStringNotContainsString('PRIVATE_CONTENT', $error->getMessage());
        }
        self::assertCount(2, $this->server->requests());
    }

    public static function invalidSendBodies(): array
    {
        return [
            'empty body' => [''],
            'HTML' => ['<html>PRIVATE_CONTENT</html>'],
            'truncated JSON' => ['{"id":"snd_id","status":"queued"'],
            'JSON list' => ['[]'],
            'JSON null' => ['null'],
            'JSON scalar' => ['123'],
            'missing fields' => ['{}'],
            'missing status' => ['{"id":"snd_id"}'],
            'null ID' => ['{"id":null,"status":"queued"}'],
            'numeric ID' => ['{"id":123,"status":"queued"}'],
            'empty ID' => ['{"id":"","status":"queued"}'],
            'null status' => ['{"id":"snd_id","status":null}'],
            'numeric status' => ['{"id":"snd_id","status":123}'],
        ];
    }

    #[DataProvider('invalidVerificationBodies')]
    public function testVerificationRejectsInvalidSuccessfulResponses(string $body): void
    {
        $this->server->respond(200, $body);
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);
        $error = $this->invalidResponse(fn () => $client->verify('snd_8Kq2wR7tXz', ['code' => '000123']));
        self::assertSame(200, $error->status);
        self::assertCount(1, $this->server->requests());
    }

    public static function invalidVerificationBodies(): array
    {
        return [
            'JSON list' => ['[]'],
            'JSON null' => ['null'],
            'missing fields' => ['{}'],
            'missing attempts' => ['{"valid":false,"verification":"pending"}'],
            'numeric valid' => ['{"valid":1,"verification":"pending","attempts_left":2}'],
            'string valid' => ['{"valid":"false","verification":"pending","attempts_left":2}'],
            'null verification' => ['{"valid":false,"verification":null,"attempts_left":2}'],
            'numeric verification' => ['{"valid":false,"verification":123,"attempts_left":2}'],
            'fractional attempts' => ['{"valid":false,"verification":"pending","attempts_left":1.5}'],
            'string attempts' => ['{"valid":false,"verification":"pending","attempts_left":"2"}'],
            'negative attempts' => ['{"valid":false,"verification":"pending","attempts_left":-1}'],
        ];
    }

    private function invalidResponse(callable $request): InvalidResponseError
    {
        $error = null;
        try {
            $request();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        self::assertTrue($error instanceof InvalidResponseError, 'Expected InvalidResponseError; got ' . ($error === null ? 'no exception.' : $error::class));
        return $error;
    }
}
