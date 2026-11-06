<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Support;

use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The HTTP client the aggregator's tests run against.
 *
 * The plugin's tests never talk to Zernio: the mock answers with what
 * `bin/zernio-probe` recorded, and the recorded answers live in the
 * fixture files under `tests/Fixtures/Zernio/`. A real run replaces
 * those files, and a difference shows up as a failing test rather than
 * as a rewrite of the test.
 */
final class ZernioRecorder
{
    /**
     * What the client was asked for.
     *
     * @var list<array{method: string, url: string, options: array<string, mixed>}>
     */
    public array $requests = [];

    /**
     * @param list<array{body: string, status?: int, transport?: bool}> $answers
     */
    public function __construct(private readonly array $answers = []) {}

    public function client(): MockHttpClient
    {
        return new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            $answer = $this->answers[count($this->requests) - 1] ?? ['body' => '{}'];

            // A call that never landed: the request never reaches
            // Zernio, and the outcome is unknown.
            if (($answer['transport'] ?? false) === true) {
                throw new TransportException('Connection refused');
            }

            return new MockResponse($answer['body'], ['http_code' => $answer['status'] ?? 200]);
        });
    }

    /**
     * The headers the last request carried, as a map.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        $options = $this->requests[count($this->requests) - 1]['options'] ?? [];
        $headers = [];

        foreach ($options['headers'] ?? [] as $header) {
            [$name, $value] = explode(': ', (string) $header, 2);
            $headers[strtolower($name)] = $value;
        }

        return $headers;
    }

    /**
     * The body the last request carried.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $options = $this->requests[count($this->requests) - 1]['options'] ?? [];

        return (array) json_decode((string) ($options['body'] ?? ''), true);
    }

    /**
     * The url the last request went to.
     */
    public function url(): string
    {
        return $this->requests[count($this->requests) - 1]['url'] ?? '';
    }

    /**
     * The method the last request used.
     */
    public function method(): string
    {
        return $this->requests[count($this->requests) - 1]['method'] ?? '';
    }
}
