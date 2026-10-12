<?php

declare(strict_types=1);

$directory = getenv('AUT2FA_TEST_DIRECTORY');
$response = json_decode(file_get_contents($directory . '/response.json'), true, 512, JSON_THROW_ON_ERROR);
$headers = [];
foreach (getallheaders() as $name => $value) {
    $headers[strtolower($name)] = $value;
}

file_put_contents($directory . '/requests.jsonl', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'],
    'path' => $_SERVER['REQUEST_URI'],
    'headers' => $headers,
    'body' => file_get_contents('php://input'),
], JSON_THROW_ON_ERROR) . PHP_EOL, FILE_APPEND | LOCK_EX);

if ($response['delay_ms'] > 0) {
    // This delay models a slow API response, so cURL's real timeout is exercised.
    usleep($response['delay_ms'] * 1000);
}

http_response_code($response['status']);
header('Content-Type: application/json');
foreach ($response['headers'] as $name => $value) {
    header($name . ': ' . $value);
}
echo $response['body'];
