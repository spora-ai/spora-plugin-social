<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * The OAuth side's HTTP client, with the calls it made kept.
 *
 * The providers answer with a value and throw nothing, so the same question
 * as on the publish side has to be asked of the recorded calls: which
 * endpoint was called, with which grant, and what came back.
 *
 * The client is otherwise the one the plugin builds itself — same handler
 * stack, same error behaviour — so a live run fails the way production
 * fails.
 */
final class LiveGuzzleRecorder
{
    /**
     * The form fields that carry a credential.
     *
     * The values are scrubbed by name rather than by shape: a parsed field
     * has lost the `name=value` the shape would have been found in.
     */
    private const SECRET_FIELDS = [
        'access_token',
        'page_access_token',
        'appsecret',
        'client_secret',
        'fb_exchange_token',
        'exchange_token',
        'refresh_token',
        'code',
        'code_verifier',
        'state',
    ];

    /** @var list<array<string, mixed>> */
    private array $history = [];

    public function __construct(private readonly LiveSecrets $secrets) {}

    /**
     * A Guzzle client that answers from the network and records the calls.
     *
     * @param callable|null $handler the transport to use; the default is the
     *                               one Guzzle builds, so the live run fails
     *                               the way production fails
     */
    public function client(?callable $handler = null): Client
    {
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($this->history));

        return new Client(['handler' => $stack]);
    }

    /**
     * @return list<array{method: string, url: string, status: int, headers: array<string, string>, form: array<string, string>, request: string, body: string}>
     */
    public function calls(): array
    {
        $calls = [];

        foreach ($this->history as $entry) {
            $request = $entry['request'] ?? null;

            if (!$request instanceof RequestInterface) {
                continue;
            }

            $response = $entry['response'] ?? null;

            $calls[] = [
                'method'  => $request->getMethod(),
                'url'     => (string) $request->getUri(),
                'status'  => $response instanceof ResponseInterface ? $response->getStatusCode() : 0,
                'headers' => $this->headersOf($response),
                'form'    => $this->formOf($request),
                'request' => $this->secrets->redact((string) $request->getBody()),
                'body'    => $response instanceof ResponseInterface
                    ? $this->secrets->redact((string) $response->getBody())
                    : '',
            ];
        }

        return $calls;
    }

    /**
     * The last call whose method and url contain the given fragments.
     *
     * @return array{method: string, url: string, status: int, headers: array<string, string>, form: array<string, string>, request: string, body: string}|null
     */
    public function last(string $method, string $urlFragment): ?array
    {
        $found = null;

        foreach ($this->calls() as $call) {
            if ($call['method'] === $method && str_contains($call['url'], $urlFragment)) {
                $found = $call;
            }
        }

        return $found;
    }

    /**
     * @return array<string, string>
     */
    private function headersOf(mixed $response): array
    {
        if (!$response instanceof ResponseInterface) {
            return [];
        }

        $headers = [];
        foreach ($response->getHeaders() as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        return $headers;
    }

    /**
     * The form fields of a request, with the secret ones scrubbed.
     *
     * The grant type and the endpoint are what the checks read; a token in
     * the body is not something the report has to carry.
     *
     * @return array<string, string>
     */
    private function formOf(RequestInterface $request): array
    {
        if (!str_contains($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded')) {
            return [];
        }

        parse_str((string) $request->getBody(), $fields);

        $form = [];

        foreach ($fields as $key => $value) {
            $name = (string) $key;

            $form[$name] = in_array($name, self::SECRET_FIELDS, true)
                ? '***redacted***'
                : $this->redacted($value);
        }

        return $form;
    }

    /**
     * The value as it may be shown.
     */
    private function redacted(mixed $value): string
    {
        return is_string($value) ? $this->secrets->redact($value) : '';
    }
}
