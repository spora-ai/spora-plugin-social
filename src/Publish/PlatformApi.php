<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Service\ApiVersions;

/**
 * The platform's HTTP client and the API versions it speaks.
 *
 * They are one object because a caller that had only the client would
 * silently get the plugin's default versions instead of the operator's
 * configuration — and the version is what the platform refuses a request
 * over. Separating them made "forgot the versions" a mistake with no
 * error; this makes it impossible.
 */
final readonly class PlatformApi
{
    public function __construct(
        public PlatformHttpClient $http,
        public ApiVersions $versions,
    ) {}
}
