<?php

declare(strict_types=1);

require __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/utils.php';

if (gethostname() !== 'api01') {
    for (;;) {
        sleep(1);
    }
}

const REDIS_KEY = 'best_payment_processor';

$redis = createRedisConnection();

logInfo('PaymentProcessorHealthChecker started.' . PHP_EOL);

while (true) {
    $responses = [];

    foreach (BASE_URLS as $i => $url) {
        logInfo("Checking health of processor [$i]" . PHP_EOL);

        try {
            $response = doRequest(intval($i), 'GET', '/payments/service-health', []);
        } catch (\Throwable $e) {
            logInfo("Request failed for processor [$i]: " . $e->getMessage() . PHP_EOL);
            continue;
        }

        if (! $response['is_ok']) {
            logInfo("Non-2xx response from processor [$i]" . PHP_EOL);

            if ($response['status'] === 429) {
                sleep(5);
            }

            continue;
        }

        $body = $response['body'];

        $json = json_decode($body, true);

        if (! is_array($json) || ! isset($json['failing'], $json['minResponseTime'])) {
            logInfo("Invalid or missing JSON fields from processor [$i]: " . $body . PHP_EOL);
            continue;
        }

        logInfo("Processor [$i] is healthy. Response time: {$json['minResponseTime']}ms" . PHP_EOL);

        $responses[$i] = $json + ['payment_processor' => $i];
    }

    $validPaymentProcessors = array_filter(
        $responses,
        fn ($item) => $item['failing'] === false,
    );

    if (empty($validPaymentProcessors)) {
        logInfo("No valid payment processors available at this time." . PHP_EOL);
        sleep(5);
        continue;
    }

    usort(
        $validPaymentProcessors,
        fn ($itemA, $itemB) => $itemA['minResponseTime'] <=> $itemB['minResponseTime'],
    );

    $bestPaymentProcessor = $validPaymentProcessors[0]['payment_processor'] ?? null;

    if (is_null($bestPaymentProcessor)) {
        logInfo("Could not determine best payment processor after sorting." . PHP_EOL);
        sleep(5);
        continue;
    }

    $redis->set(REDIS_KEY, intval($bestPaymentProcessor));

    logInfo("Best payment processor selected: [$bestPaymentProcessor]. Stored in Redis under key '" . REDIS_KEY . "'" . PHP_EOL);

    sleep(5);
}
