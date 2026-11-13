<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

/**
 * Whether the install's public URL is reachable from the internet.
 *
 * Instagram fetches the media from the platform's own servers, so an
 * address only the operator's network can see fails at the last step of a
 * publish — with an error that never mentions the URL. Checking it at
 * connect time turns that into a sentence the operator can act on.
 *
 * Two questions, asked together: is the address publicly routable at all
 * (a loopback or a private-range host is not), and does the host answer on
 * it (a transport error means something between the platform and the
 * install is in the way).
 *
 * The second question leaves the host, so it costs a round trip — five
 * seconds when nothing answers. Only the connect flow asks: it is the one
 * place where the answer decides whether the connect is refused.
 */
final class SocialAppUrlProbe
{
    private const TIMEOUT_SECONDS = 5.0;

    /**
     * How long the whole request may take.
     *
     * The timeout is the idle one, so a host that answers slowly but keeps
     * the connection open could hold the request for ever. This bounds it.
     */
    private const MAX_DURATION_SECONDS = 10.0;

    public function __construct(
        private readonly HttpClientInterface $client,
    ) {}

    public function isPubliclyReachable(?string $appUrl): bool
    {
        if ($appUrl === null || trim($appUrl) === '') {
            return false;
        }

        return $this->probe($appUrl);
    }

    private function probe(string $appUrl): bool
    {
        $host = parse_url(trim($appUrl), PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return false;
        }

        if (!$this->isPublicHost($host)) {
            return false;
        }

        return $this->answers($appUrl);
    }

    /**
     * A host on the loopback interface or in a private range is reachable
     * from the operator's desk and from nowhere else.
     */
    private function isPublicHost(string $host): bool
    {
        $host = strtolower(trim($host, '[]'));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return true;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Whether the host answers on the address.
     *
     * Any HTTP status counts: a 404 still proves the request arrived, and
     * only a transport failure says the address is unreachable.
     */
    private function answers(string $appUrl): bool
    {
        try {
            $response = $this->client->request('HEAD', $appUrl, [
                'timeout'  => self::TIMEOUT_SECONDS,
                'max_duration' => self::MAX_DURATION_SECONDS,
                'max_redirects' => 3,
            ]);

            $response->getStatusCode();
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
