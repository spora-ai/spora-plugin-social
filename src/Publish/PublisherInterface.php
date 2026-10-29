<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Publish;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * One platform's publishing call.
 *
 * A publish that goes out cannot be recalled, so the way back down is
 * part of the same contract: a platform that cannot delete says so in
 * the operator's words rather than failing with something opaque.
 */
interface PublisherInterface
{
    public function platform(): SocialPlatform;

    public function publish(PublishRequest $request): PublishResult;

    /**
     * Take a published post back down.
     *
     * @see DeleteRequest
     */
    public function delete(DeleteRequest $request): PublishResult;
}
