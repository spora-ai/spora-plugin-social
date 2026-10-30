<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Support;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/**
 * A Guzzle client that answers from a script and records what it was asked.
 */
final class GuzzleRecorder
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $requests = [];

    /**
     * @param list<array{body: string, status?: int}> $responses
     */
    public function client(array $responses = []): Client
    {
        $mock = new MockHandler(array_map(
            static fn(array $response): Response => new Response(
                $response['status'] ?? 200,
                ['Content-Type' => 'application/json'],
                $response['body'],
            ),
            $responses,
        ));

        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->requests));

        return new Client(['handler' => $stack]);
    }
}
