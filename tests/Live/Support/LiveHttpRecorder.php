<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/**
 * The publish side's HTTP client, with the calls it made kept.
 *
 * The publish adapters answer with a value and throw nothing, so a check
 * that only reads the result cannot tell a publish that went to the right
 * host from one that went to the wrong one. The recorder keeps the request
 * and the answer, and the checks read them.
 */
final class LiveHttpRecorder implements HttpClientInterface
{
    /** @var list<array{method: string, url: string, status: int, headers: array<string, string>, body: string, request: string}> */
    private array $calls = [];

    public function __construct(
        private HttpClientInterface $inner,
        private readonly LiveSecrets $secrets,
    ) {}

    /**
     * @return list<array{method: string, url: string, status: int, headers: array<string, string>, body: string, request: string}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * The last call whose method and url contain the given fragments.
     *
     * @return array{method: string, url: string, status: int, headers: array<string, string>, body: string, request: string}|null
     */
    public function last(string $method, string $urlFragment): ?array
    {
        $found = null;

        foreach ($this->calls as $call) {
            if ($call['method'] === $method && str_contains($call['url'], $urlFragment)) {
                $found = $call;
            }
        }

        return $found;
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $response = $this->inner->request($method, $url, $options);

        $headers = [];
        foreach ($response->getHeaders(false) as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        // The effective url, not the one that was asked for: the query the
        // caller put in the options is part of the call that went out.
        $effective = $response->getInfo('url');

        $this->calls[] = [
            'method'  => $method,
            'url'     => $this->secrets->redact(is_string($effective) && $effective !== '' ? $effective : $url),
            'status'  => $response->getStatusCode(),
            'headers' => $headers,
            'body'    => $this->secrets->redact($response->getContent(false)),
            'request' => $this->secrets->redact($this->requestBody($options)),
        ];

        return $response;
    }

    /**
     * The body the plugin sent, so a check can see what it put in the call.
     *
     * @param array<string, mixed> $options
     */
    private function requestBody(array $options): string
    {
        if (isset($options['json'])) {
            $encoded = json_encode($options['json'], JSON_UNESCAPED_SLASHES);

            return $encoded === false ? '' : $encoded;
        }

        if (isset($options['body']) && is_string($options['body'])) {
            return $options['body'];
        }

        return '';
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->inner->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->inner = $this->inner->withOptions($options);

        return $clone;
    }
}
