<?php

declare(strict_types=1);

namespace FasterMessage\Tests;

use FasterMessage\Exception\ConnectionException;
use FasterMessage\Http\Response;
use FasterMessage\Http\Transport;

final class FakeTransport implements Transport
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    public array $calls = [];

    /** @param list<Response|ConnectionException> $queue */
    public function __construct(private array $queue)
    {
    }

    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): Response
    {
        $this->calls[] = compact('method', 'url', 'headers', 'body');
        $next = array_shift($this->queue) ?? throw new \LogicException('Appel de trop.');
        if ($next instanceof ConnectionException) {
            throw $next;
        }

        return $next;
    }

    /** @param array<string, mixed> $body @param array<string, string> $headers */
    public static function json(int $status, array $body, array $headers = []): Response
    {
        return new Response($status, $headers + ['x-request-id' => 'rid-1'], (string) json_encode($body));
    }

    /** @param array<string, string> $headers */
    public static function error(int $status, string $code, string $type = 'invalid_request', ?string $param = null, array $headers = []): Response
    {
        return self::json($status, ['error' => array_filter(['type' => $type, 'code' => $code, 'message' => 'm', 'param' => $param, 'request_id' => 'rid-9'])], $headers);
    }
}
