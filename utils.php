<?php

declare(strict_types=1);

use Predis\Client;

require_once __DIR__ . '/vendor/autoload.php';

define('PROCESSOR_DEFAULT', 1);
define('PROCESSOR_FALLBACK', 2);

define('BASE_URLS', [
    PROCESSOR_DEFAULT => $_ENV['PAYMENT_PROCESSOR_DEFAULT_URL'],
    PROCESSOR_FALLBACK => $_ENV['PAYMENT_PROCESSOR_FALLBACK_URL'],
]);

function doRequest(int $paymentProcessor, string $method, string $path, array $data): array
{
    $processorUrl = BASE_URLS[$paymentProcessor] ?? null;

    if (empty($processorUrl)) {
        throw new InvalidArgumentException("Unsupported payment processor: {$paymentProcessor}");
    }

    $url = rtrim($processorUrl, '/') . '/' . ltrim($path, '/');

    $ch = curl_init();

    $responseHeaders = [];

    curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) use (&$responseHeaders) {
        $len = strlen($header);
        $headerParts = explode(':', $header, 2);

        if (count($headerParts) === 2) {
            $key = strtolower(trim($headerParts[0]));
            $value = trim($headerParts[1]);
            $responseHeaders[$key] = $value;
        }

        return $len;
    });

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_CONNECTTIMEOUT => 1,
    ];

    switch (strtoupper($method)) {
        case 'POST':
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            break;
        case 'PUT':
        case 'PATCH':
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            $options[CURLOPT_POSTFIELDS] = json_encode($data);
            break;
        case 'GET':
            if (! empty($data)) {
                $options[CURLOPT_URL] .= '?' . http_build_query($data);
            }
            break;
        default:
            $options[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
            break;
    }

    curl_setopt_array($ch, $options);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if (curl_errno($ch)) {
        throw new RuntimeException('Request error: ' . curl_error($ch));
    }

    curl_close($ch);

    return [
        'status' => intval($status),
        'body' => $response,
        'is_ok' => str_starts_with(strval($status), '2'),
        'headers' => $responseHeaders,
    ];
}

function queueJob(Client $redis, array $data): int
{
    return $redis->lpush('pending_payments', json_encode($data));
}

function createRedisConnection()
{
    return new Client([
        'scheme' => 'tcp',
        'host' => getenv('REDIS_HOST'),
        'port' => getenv('REDIS_PORT'),
    ]);
}

function logInfo(string ...$expressions): void
{
    if (! filter_var(getenv('SHOULD_LOG'), FILTER_VALIDATE_BOOLEAN)) {
        return;
    }

    foreach ($expressions as $expression) {
        echo $expression;
    }
}
