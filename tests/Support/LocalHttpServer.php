<?php

declare(strict_types=1);

namespace Aut2fa\Tests\Support;

use RuntimeException;

final class LocalHttpServer
{
    private mixed $process;
    private string $directory;
    public readonly string $baseUrl;

    public function __construct()
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException('Cannot reserve a local test port: ' . $errorMessage);
        }

        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if ($address === false) {
            throw new RuntimeException('Cannot read the local test port.');
        }

        $this->baseUrl = 'http://' . $address;
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aut2fa-php-' . bin2hex(random_bytes(8));
        if (!mkdir($this->directory)) {
            throw new RuntimeException('Cannot create the test server directory.');
        }

        $this->respond(202, self::sendResponse());
        $this->process = proc_open(
            [PHP_BINARY, '-S', $address, __DIR__ . '/router.php'],
            [
                0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'],
                1 => ['file', $this->directory . '/server.log', 'a'],
                2 => ['file', $this->directory . '/server.log', 'a'],
            ],
            $pipes,
            null,
            array_merge(getenv(), ['AUT2FA_TEST_DIRECTORY' => $this->directory]),
            ['bypass_shell' => true],
        );
        if (!is_resource($this->process)) {
            throw new RuntimeException('Cannot start the local HTTP server.');
        }

        $deadline = microtime(true) + 5;
        do {
            $connection = @stream_socket_client('tcp://' . $address, $errorCode, $errorMessage, 0.05);
            if ($connection !== false) {
                fclose($connection);
                return;
            }
        } while (microtime(true) < $deadline && proc_get_status($this->process)['running']);

        $log = file_get_contents($this->directory . '/server.log');
        $this->stop();
        throw new RuntimeException('Local HTTP server did not start: ' . $log);
    }

    public function respond(int $status, string $body, array $headers = [], int $delayMs = 0): void
    {
        file_put_contents($this->directory . '/response.json', json_encode([
            'status' => $status,
            'body' => $body,
            'headers' => $headers,
            'delay_ms' => $delayMs,
        ], JSON_THROW_ON_ERROR));
    }

    public function requests(): array
    {
        $path = $this->directory . '/requests.jsonl';
        if (!is_file($path)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR),
            file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
        );
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        foreach (glob($this->directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($this->directory);
    }

    public static function sendResponse(): string
    {
        return '{"id":"snd_8Kq2wR7tXz","status":"queued","verification":"pending","mode":"test","channel":null,"from":null,"expires_at":"2026-10-11T18:10:00Z","created_at":"2026-10-11T18:00:00Z","sent_at":null,"delivered_at":null,"read_at":null,"verified_at":null,"failed_at":null,"failure_reason":null,"future_field":{"enabled":true}}';
    }
}
