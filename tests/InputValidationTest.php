<?php

declare(strict_types=1);

namespace Aut2fa\Tests;

use Aut2fa\Aut2fa;
use Aut2fa\Tests\Support\LocalHttpServer;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Guards local input boundaries so malformed headers and paths cannot send credentials or alter requests. */
final class InputValidationTest extends TestCase
{
    #[DataProvider('invalidKeys')]
    public function testCredentialsRejectBlankValuesAndHeaderControls(string $key): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Aut2fa($key);
    }

    public static function invalidKeys(): array
    {
        return [
            'empty' => [''],
            'blank' => ['   '],
            'line feed' => ["ak_test\nX-Injected: value"],
            'carriage return' => ["ak_test\rvalue"],
            'tab' => ["ak_test\tvalue"],
            'null byte' => ["ak_test\0value"],
            'DEL' => ["ak_test\x7fvalue"],
        ];
    }

    #[DataProvider('invalidOptions')]
    public function testInvalidConfigurationFailsAtConstruction(array $options): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Aut2fa('ak_test_contract', $options);
    }

    public static function invalidOptions(): array
    {
        return [
            'blank base URL' => [['baseUrl' => '']],
            'relative base URL' => [['baseUrl' => '/api']],
            'non-HTTP scheme' => [['baseUrl' => 'ftp://api.aut2fa.com']],
            'URL credentials' => [['baseUrl' => 'https://user:password@api.aut2fa.com']],
            'URL username' => [['baseUrl' => 'https://user@api.aut2fa.com']],
            'URL query' => [['baseUrl' => 'https://api.aut2fa.com?mode=test']],
            'empty query' => [['baseUrl' => 'https://api.aut2fa.com?']],
            'URL fragment' => [['baseUrl' => 'https://api.aut2fa.com#fragment']],
            'empty fragment' => [['baseUrl' => 'https://api.aut2fa.com#']],
            'URL controls' => [['baseUrl' => "http://localhost\n"]],
            'URL backslash' => [['baseUrl' => 'https://api.aut2fa.com\\other']],
            'zero port' => [['baseUrl' => 'http://localhost:0']],
            'non-string URL' => [['baseUrl' => 12]],
            'null URL' => [['baseUrl' => null]],
            'zero timeout' => [['timeout' => 0]],
            'negative timeout' => [['timeout' => -1]],
            'nonfinite timeout' => [['timeout' => INF]],
            'NaN timeout' => [['timeout' => NAN]],
            'numeric string timeout' => [['timeout' => '10']],
            'boolean timeout' => [['timeout' => true]],
            'null timeout' => [['timeout' => null]],
            'timeout exceeds cURL range' => [['timeout' => 2147483.648]],
            'misspelled option' => [['timeoutMs' => 10]],
        ];
    }

    public function testIdsCannotModifyTheHttpPath(): void
    {
        $server = new LocalHttpServer();
        try {
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl]);
            $ids = ['', ' ', '../other', 'snd/other', 'snd\\other', 'snd?query=1', 'snd#fragment', 'snd%2fother', 'snd_é', "snd\nvalue", "snd\0value"];
            foreach ($ids as $id) {
                foreach (['status', 'verify'] as $method) {
                    try {
                        $method === 'status' ? $client->status($id) : $client->verify($id, ['code' => '000123']);
                        self::fail('Unsafe IDs must be rejected before HTTP: ' . $method);
                    } catch (InvalidArgumentException $error) {
                        self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
                    }
                }
            }
            self::assertSame([], $server->requests());
        } finally {
            $server->stop();
        }
    }

    public function testIdempotencyKeysRejectControlsAndMoreThan255Bytes(): void
    {
        $server = new LocalHttpServer();
        try {
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl]);
            foreach ([str_repeat('a', 256), str_repeat('é', 128), "key\nHeader: value", "key\r", "key\t", "key\0", "key\x7f"] as $key) {
                try {
                    $client->send(['to' => '+5531999999999'], $key);
                    self::fail('Unsafe idempotency keys must be rejected before HTTP.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
                }
            }
            self::assertSame([], $server->requests());
        } finally {
            $server->stop();
        }
    }

    public function testTheIdempotencyLimitCountsBytes(): void
    {
        $server = new LocalHttpServer();
        try {
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl]);
            $key = str_repeat('é', 127) . 'a';

            $client->send(['to' => '+5531999999999'], $key);

            [$request] = $server->requests();
            self::assertSame($key, $request['headers']['idempotency-key']);
            self::assertSame(255, strlen($request['headers']['idempotency-key']));
        } finally {
            $server->stop();
        }
    }

    public function testSendRequiresAStringRecipient(): void
    {
        $server = new LocalHttpServer();
        try {
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl]);
            foreach ([[], ['to' => ''], ['to' => '   '], ['to' => 123], ['to' => null], ['to' => '+5531999999999', 'code' => 123], ['to' => '+5531999999999', 'locale' => null]] as $params) {
                try {
                    $client->send($params);
                    self::fail('Malformed send parameters must be rejected before HTTP.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
                }
            }
            self::assertSame([], $server->requests());
        } finally {
            $server->stop();
        }
    }

    public function testVerificationRequiresAStringCode(): void
    {
        $server = new LocalHttpServer();
        try {
            $client = new Aut2fa('ak_test_contract', ['baseUrl' => $server->baseUrl]);
            foreach ([[], ['code' => ''], ['code' => '   '], ['code' => 123], ['code' => null]] as $params) {
                try {
                    $client->verify('snd_8Kq2wR7tXz', $params);
                    self::fail('Malformed verification parameters must be rejected before HTTP.');
                } catch (InvalidArgumentException $error) {
                    self::assertStringNotContainsString('ak_test_contract', $error->getMessage());
                }
            }
            self::assertSame([], $server->requests());
        } finally {
            $server->stop();
        }
    }

    #[DataProvider('unencodablePayloads')]
    public function testUnencodableJsonIsReportedAsInvalidInput(array $params): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new Aut2fa('ak_test_contract'))->send($params);
    }

    public static function unencodablePayloads(): array
    {
        return [
            'invalid UTF-8' => [['to' => "+5531999999999\xff"]],
            'nonfinite additional field' => [['to' => '+5531999999999', 'future_field' => NAN]],
        ];
    }
}
