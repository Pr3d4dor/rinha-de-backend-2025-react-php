<?php

declare(strict_types=1);

require_once 'utils.php';

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use React\Socket\SocketServer;
use Tnapf\Router\Router;
use Tnapf\Router\Routing\RouteRunner;

$redis = createRedisConnection();

$router = new Router;

$http = new HttpServer(
    static function (ServerRequestInterface $request) use ($router) {
        return $router->run($request);
    }
);

$router->get('/payments-summary', static function (ServerRequestInterface $request,) use ($redis) {

    $queryParams = $request->getQueryParams();

    $from = isset($queryParams['from'])
        ? new DateTimeImmutable($queryParams['from'])
        : null;

    $to = isset($queryParams['to'])
        ? new DateTimeImmutable($queryParams['to'])
        : null;

    $result = [
        'default' => [
            'totalRequests' => 0,
            'totalAmount' => 0.0,
        ],
        'fallback' => [
            'totalRequests' => 0,
            'totalAmount' => 0.0,
        ],
    ];

    $payments = $redis->lrange('payments', 0, -1);

    foreach ($payments as $data) {
        $payment = json_decode($data, true);

        if (! $payment) {
            continue;
        }

        $paymentTime = DateTimeImmutable::createFromFormat(
            'Y-m-d\TH:i:s.v\Z',
            $payment['requestedAt']
        );

        if (! empty($from) && $paymentTime < $from) {
            continue;
        }

        if (! empty($to) && $paymentTime > $to) {
            continue;
        }

        $key = $payment['paymentProcessor'] === PROCESSOR_DEFAULT
            ? 'default'
            : 'fallback';

        $result[$key]['totalAmount'] += $payment['amount'];
        $result[$key]['totalRequests']++;
    }

    return Response::json($result);
});

$router->post('/payments', static function (ServerRequestInterface $request) use ($redis) {

    $requestData = json_decode((string) $request->getBody(), true);

    if (! $requestData) {
        return new Response(Response::STATUS_BAD_REQUEST);
    }

    $correlationId = strval($requestData['correlationId']);
    $amount = floatval($requestData['amount']);

    queueJob($redis, [
        'correlation_id' => $correlationId,
        'amount' => $amount,
    ]);

    return new Response(Response::STATUS_NO_CONTENT);
});

$router->post('/purge-payments', static function () use ($redis) {
    $redis->del('payments');

    return new Response(Response::STATUS_NO_CONTENT);
});

$router->catch(
    \Throwable::class,
    static function (
        ServerRequestInterface $request,
        ResponseInterface $response,
        RouteRunner $route,
    ) {
        $exception = $route->exception;
        $exceptionString = $exception->getMessage() . PHP_EOL . $exception->getTraceAsString();

        $response->getBody()->write($exceptionString);

        return $response
            ->withStatus(Response::STATUS_INTERNAL_SERVER_ERROR)
            ->withHeader('Content-Type', 'text/plain');
    }
);

$http->listen(new SocketServer('0.0.0.0:8080'));

echo '[Server] started at 8080.'.PHP_EOL;
