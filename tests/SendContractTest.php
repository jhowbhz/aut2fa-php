<?php

declare(strict_types=1);

namespace Aut2fa\Tests;

use Aut2fa\Aut2fa;
use Aut2fa\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\TestCase;

/** Guards the authenticated send contract: a wrong endpoint or payload can create or charge the wrong send. */
final class SendContractTest extends TestCase
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

    public function testSendPostsJsonWithBearerCredentials(): void
    {
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);

        $send = $client->send(['to' => '+5531999999999', 'code' => '004829', 'locale' => 'pt-BR']);

        self::assertSame('snd_8Kq2wR7tXz', $send->id);
        self::assertSame('queued', $send->status);
        self::assertSame('test', $send->mode);
        foreach (['channel', 'from', 'sent_at', 'delivered_at', 'read_at', 'verified_at', 'failed_at', 'failure_reason'] as $field) {
            self::assertNull($send->{$field});
        }
        self::assertTrue($send->future_field->enabled);
        [$request] = $this->server->requests();
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/send', $request['path']);
        self::assertSame('Bearer ak_test_contract', $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['accept']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        self::assertSame([
            'to' => '+5531999999999',
            'code' => '004829',
            'locale' => 'pt-BR',
        ], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testSendPreservesAnExplicitIdempotencyKey(): void
    {
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);

        $client->send(['to' => '+5531999999999'], 'login-attempt-001');

        [$request] = $this->server->requests();
        self::assertArrayHasKey('idempotency-key', $request['headers']);
        self::assertSame('login-attempt-001', $request['headers']['idempotency-key']);
        self::assertSame(['to' => '+5531999999999'], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    public function testStatusUsesGetWithoutAJsonBody(): void
    {
        $this->server->respond(200, LocalHttpServer::sendResponse());
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl . '/gateway/']);

        $send = $client->status('snd_Safe-01');

        self::assertSame('snd_8Kq2wR7tXz', $send->id);
        [$request] = $this->server->requests();
        self::assertSame('GET', $request['method']);
        self::assertSame('/gateway/v1/send/snd_Safe-01/status', $request['path']);
        self::assertSame('Bearer ak_test_contract', $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['accept']);
        self::assertArrayNotHasKey('content-type', $request['headers']);
        self::assertArrayNotHasKey('idempotency-key', $request['headers']);
        self::assertSame('', $request['body']);
    }

    public function testAnIncorrectCodeRemainsANormalVerificationResult(): void
    {
        $this->server->respond(200, '{"valid":false,"verification":"pending","attempts_left":2,"next_step":null}');
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => $this->server->baseUrl]);

        $result = $client->verify('snd_8Kq2wR7tXz', ['code' => '000123']);

        self::assertFalse($result->valid);
        self::assertSame('pending', $result->verification);
        self::assertSame(2, $result->attempts_left);
        self::assertNull($result->next_step);
        [$request] = $this->server->requests();
        self::assertSame('POST', $request['method']);
        self::assertSame('/v1/send/snd_8Kq2wR7tXz/verify', $request['path']);
        self::assertSame('Bearer ak_test_contract', $request['headers']['authorization']);
        self::assertSame('application/json', $request['headers']['content-type']);
        self::assertSame(['code' => '000123'], json_decode($request['body'], true, 512, JSON_THROW_ON_ERROR));
    }
}
