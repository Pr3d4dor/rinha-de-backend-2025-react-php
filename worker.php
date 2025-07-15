<?php

declare(strict_types=1);

require_once 'utils.php';

const REDIS_KEY = 'best_payment_processor';

$redis = createRedisConnection();

while ($data = $redis->brpop('pending_payments', 0)) {
    $jobData = json_decode($data[1], true);

    if (! $jobData) {
        continue;
    }

    $paymentProcessor = $redis->get(REDIS_KEY);

    if (! $paymentProcessor) {
        logInfo('No payment processor available in Redis.'.PHP_EOL);

        queueJob($redis, [
            'correlation_id' => $jobData['correlation_id'],
            'amount' => $jobData['amount'],
        ]);

        continue;
    }

    $payload = [
        'correlationId' => $jobData['correlation_id'],
        'amount' => $jobData['amount'],
        'requestedAt' => (new DateTimeImmutable)->format('Y-m-d\TH:i:s.v\Z'),
    ];

    $response = doRequest(intval($paymentProcessor), 'POST', '/payments', $payload);

    if (! $response['is_ok']) {
        logInfo('Request to payment processor failed.'.PHP_EOL);

        queueJob($redis, [
            'correlation_id' => $jobData['correlation_id'],
            'amount' => $jobData['amount'],
        ]);

        continue;
    }

    $redis->lPush(
        key: 'payments',
        values: (array) json_encode($payload + ['paymentProcessor' => intval($paymentProcessor)])
    );
}
