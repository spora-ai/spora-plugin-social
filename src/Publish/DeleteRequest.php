<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialAccountKind;

/**
 * One attempt to take a published post back down.
 *
 * Only what the platform needs to find the post again: the id the publish
 * answered with, and the token that published it. The copy is already on
 * the network and is not part of the request.
 */
final readonly class DeleteRequest
{
    public function __construct(
        /** The id the publish answered with: a share URN, a Page post id. */
        public string $externalId,
        public SocialAccountKind $kind,
        public string $accessToken,
    ) {}
}
