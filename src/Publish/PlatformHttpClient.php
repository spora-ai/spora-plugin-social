<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The one HTTP seam the publishing adapters use.
 *
 * Symfony's client throws on a 4xx unless asked not to, and a platform
 * refusing a post is a normal outcome the adapter has to read — not an
 * exception. So the response is never thrown: the status, the headers and
 * the decoded body come back as a value.
 */
final class PlatformHttpClient
{
    public function __construct(
        private readonly HttpClientInterface $client,
    ) {}

    /**
     * @param array<string, mixed>  $options
     */
    public function request(string $method, string $url, array $options = []): PlatformHttpResponse
    {
        $response = $this->client->request($method, $url, $options);

        $status = $response->getStatusCode();
        $raw = $response->getContent(false);
        $headers = [];

        foreach ($response->getHeaders(false) as $name => $values) {
            $headers[strtolower($name)] = implode(', ', $values);
        }

        $decoded = json_decode($raw, true);

        return new PlatformHttpResponse(
            status: $status,
            headers: $headers,
            body: is_array($decoded) ? $decoded : [],
            raw: $raw,
        );
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     */
    public function postJson(string $url, array $body, array $query = [], array $headers = []): PlatformHttpResponse
    {
        return $this->request('POST', $url, [
            'json'    => $body,
            'query'   => $query,
            'headers' => $headers,
        ]);
    }
}
