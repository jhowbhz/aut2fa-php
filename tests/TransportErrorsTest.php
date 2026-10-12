<?php

declare(strict_types=1);

namespace Aut2fa\Tests;

use Aut2fa\Aut2fa;
use Aut2fa\NetworkError;
use Aut2fa\Tests\Support\LocalHttpServer;
use PHPUnit\Framework\TestCase;

/** Guards identifiable transport failures so callers can distinguish API rejection from delivery uncertainty. */
final class TransportErrorsTest extends TestCase
{
    public function testConnectionFailuresRemainIdentifiable(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage, STREAM_SERVER_BIND);
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        // Windows reports connection refusal after about two seconds; avoid confusing it with our timeout.
        $client = new Aut2fa('ak_test_contract', ['baseUrl' => 'http://' . $address, 'timeout' => 3]);

        try {
            $error = $this->networkError(fn () => $client->send(['to' => '+5531999999999']));
            self::assertSame(CURLE_COULDNT_CONNECT, $error->curlCode);
            self::assertSame(CURLE_COULDNT_CONNECT, $error->getCode());
            self::assertFalse($error->timedOut);
            self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
        } finally {
            fclose($socket);
        }
    }

    public function testFractionalTimeoutsAbortSlowRequestsWithoutRetrying(): void
    {
        $server = new LocalHttpServer();
        try {
            $server->respond(202, LocalHttpServer::sendResponse(), [], 300);
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl, 'timeout' => 0.05]);

            $error = $this->networkError(fn () => $client->send(['to' => '+5531999999999']));
            self::assertSame(CURLE_OPERATION_TIMEDOUT, $error->curlCode);
            self::assertTrue($error->timedOut);
            self::assertCount(1, $server->requests());
            self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
        } finally {
            $server->stop();
        }
    }

    private function networkError(callable $request): NetworkError
    {
        $error = null;
        try {
            $request();
        } catch (\Throwable $exception) {
            $error = $exception;
        }

        self::assertTrue($error instanceof NetworkError, 'Expected NetworkError; got ' . ($error === null ? 'no exception.' : $error::class));
        return $error;
    }
}
