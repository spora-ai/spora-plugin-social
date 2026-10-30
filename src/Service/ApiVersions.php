<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

/**
 * The API versions the plugin speaks.
 *
 * Both are settings rather than fixed values: LinkedIn versions its APIs
 * quarterly and each version lives two years, and Meta retires a Graph
 * version every year — a plugin that hard-codes one stops working on a
 * date nobody chose.
 *
 * The constructor takes both, with no defaults: a caller that forgot the
 * versions would otherwise get the plugin's defaults and talk to the
 * platform as the wrong version without knowing it.
 */
final class ApiVersions
{
    /**
     * Meta's Graph API.
     *
     * One host for both networks: the Instagram API with Unified Login is
     * "built on Facebook Login for Business" and its calls go to
     * `graph.facebook.com`, with the Instagram account id in the path.
     */
    public const GRAPH_HOST = 'graph.facebook.com';

    /**
     * The Facebook Login dialog, which is versioned like the Graph API.
     */
    public const DIALOG_HOST = 'www.facebook.com';

    /**
     * The Graph API version the plugin speaks when the operator names
     * none.
     *
     * Meta retires a version every year, so a fixed one stops working on
     * a date nobody chose; the operator can move it sooner.
     */
    public const DEFAULT_GRAPH = 'v26.0';

    /**
     * The LinkedIn API version the plugin speaks when the operator names
     * none.
     *
     * LinkedIn versions its APIs quarterly and each version lives two
     * years. The default moniker of the documentation is `li-lms-2026-10`.
     */
    public const DEFAULT_LINKEDIN = '202610';

    public function __construct(
        /** The Graph API version every Meta endpoint carries, e.g. `v26.0`. */
        public readonly string $graph,
        /** The LinkedIn API version, in `YYYYMM`. */
        public readonly string $linkedin,
    ) {}

    /**
     * A Graph API URL on the given host.
     */
    public function graphUrl(string $host, string $path): string
    {
        return 'https://' . $host . '/' . $this->graph . $path;
    }

    /**
     * The headers every LinkedIn `/rest/*` request carries.
     *
     * Both version headers are required: "All APIs require the request
     * header Linkedin-Version: {Version in YYYYMM format}. All APIs
     * require the request header `X-Restli-Protocol-Version: 2.0.0`."
     *
     * @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
     *
     * @return array<string, string>
     */
    public function linkedInRestHeaders(string $accessToken): array
    {
        return [
            'Authorization'              => 'Bearer ' . $accessToken,
            'X-Restli-Protocol-Version' => '2.0.0',
            'Linkedin-Version'           => $this->linkedin,
            'Content-Type'               => 'application/json',
        ];
    }
}
