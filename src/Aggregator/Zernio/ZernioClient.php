<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator\Zernio;

use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * The aggregator's own client for Zernio.
 *
 * Not the zernio plugin's client and not an extraction of it: the two
 * differ where it matters. This one sends `Idempotency-Key` (the layer
 * that protects a retry whose body differs), uploads with a `PUT` of raw
 * bytes to a presigned URL and no authorization header, and waits longer
 * — a publish to twelve networks happens inside the request, and that is
 * not a fast call.
 *
 * A refusal is a value and a transport failure is an exception: the
 * publisher has to tell "Zernio said no" from "we do not know", and the
 * two lead to different things on the target row.
 */
final class ZernioClient
{
    public const BASE_URL = 'https://zernio.com/api/v1';

    /**
     * How long a call may take.
     *
     * A publish goes out to every network in the call, synchronously,
     * inside the request. Twelve networks is not a fast call, and a
     * timeout that fires while Zernio is still publishing is a publish
     * whose outcome is unknown — which the plugin has to survive.
     */
    public const TIMEOUT_SECONDS = 180;

    public function __construct(
        private readonly HttpClientInterface $http,
    ) {}

    /**
     * The accounts the key can reach.
     *
     * @param array<string, mixed> $query
     */
    public function accounts(string $apiKey, array $query = []): ZernioResponse
    {
        return $this->get('/accounts', $apiKey, $query);
    }

    /**
     * One account's health.
     */
    public function accountHealth(string $apiKey, string $accountId): ZernioResponse
    {
        return $this->get('/accounts/' . rawurlencode($accountId) . '/health', $apiKey);
    }

    /**
     * The follower counts of the accounts the key can reach.
     *
     * @param array<string, mixed> $query
     */
    public function followerStats(string $apiKey, array $query = []): ZernioResponse
    {
        return $this->get('/accounts/follower-stats', $apiKey, $query);
    }

    /**
     * The numbers of one post.
     */
    public function analytics(string $apiKey, string $postId): ZernioResponse
    {
        return $this->get('/analytics', $apiKey, ['postId' => $postId]);
    }

    /**
     * Create and publish a post.
     *
     * The key is the caller's: one key per publish attempt, reused on
     * every retry of that attempt, so a retry answers `200` with the
     * original post instead of publishing twice.
     *
     * @param array<string, mixed> $body
     */
    public function createPost(string $apiKey, array $body, string $idempotencyKey): ZernioResponse
    {
        return $this->post('/posts', $apiKey, $body, ['Idempotency-Key' => $idempotencyKey]);
    }

    /**
     * Take a published post back down.
     *
     * The account id travels with the platform: the endpoint answers
     * `409 ambiguous_account` when a post reached several accounts of one
     * platform and no account was named.
     */
    public function unpublish(string $apiKey, string $postId, string $platform, string $accountId): ZernioResponse
    {
        return $this->post(
            '/posts/' . rawurlencode($postId) . '/unpublish',
            $apiKey,
            ['platform' => $platform, 'accountId' => $accountId],
        );
    }

    /**
     * The place to put the bytes.
     */
    public function presign(string $apiKey, string $filename, string $contentType, int $size): ZernioResponse
    {
        return $this->post('/media/presign', $apiKey, [
            'filename'    => $filename,
            'contentType' => $contentType,
            'size'        => $size,
        ]);
    }

    /**
     * The bytes, to the place the presign named.
     *
     * The presigned URL points at the storage bucket, not at the API, so
     * it carries no authorization header — a bearer token there is a
     * credential sent to a third party.
     */
    public function upload(string $uploadUrl, string $bytes, string $contentType): void
    {
        $this->send('PUT', $uploadUrl, [
            'body'    => $bytes,
            'headers' => ['Content-Type' => $contentType],
        ], null);
    }

    /**
     * @param array<string, mixed> $query
     */
    private function get(string $path, string $apiKey, array $query = []): ZernioResponse
    {
        return $this->send('GET', $this->url($path), $query === [] ? [] : ['query' => $query], $apiKey);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    private function post(string $path, string $apiKey, array $body, array $headers = []): ZernioResponse
    {
        return $this->send('POST', $this->url($path), ['json' => $body, 'headers' => $headers], $apiKey);
    }

    private function url(string $path): string
    {
        return rtrim(self::BASE_URL, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function send(string $method, string $url, array $options, ?string $apiKey): ZernioResponse
    {
        $headers = $options['headers'] ?? [];
        unset($options['headers']);

        if ($apiKey !== null) {
            $headers = array_merge([
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept'        => 'application/json',
            ], $headers);
        }

        $options['headers'] = $headers;
        $options['timeout'] = self::TIMEOUT_SECONDS;

        try {
            $response = $this->http->request($method, $url, $options);
            $status = $response->getStatusCode();
            $raw = $response->getContent(false);
            $responseHeaders = [];

            foreach ($response->getHeaders(false) as $name => $values) {
                $responseHeaders[strtolower($name)] = implode(', ', $values);
            }
        } catch (TransportExceptionInterface $e) {
            throw new ZernioTransportException(sprintf('Zernio did not answer: %s', $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($raw, true);

        return new ZernioResponse(
            status: $status,
            body: is_array($decoded) ? $decoded : [],
            headers: $responseHeaders,
        );
    }
}
