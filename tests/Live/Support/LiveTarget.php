<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Carbon\Carbon;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialAccountKind;
use Spora\Plugins\Social\Model\SocialConnection;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;

/**
 * A published target on a real account, holding the live token.
 *
 * The insights path takes the token out of the account row, so the row has
 * to hold the token the platform actually handed out — encrypted the way
 * the plugin encrypts it, through core's own security manager. That is the
 * path a real publish takes too, so the check reads the numbers the plugin
 * would read.
 */
final class LiveTarget
{
    public static function create(
        SecurityManagerInterface $security,
        int $principalId,
        SocialPlatform $platform,
        SocialAccountKind $kind,
        string $externalId,
        string $accessToken,
    ): SocialPostTarget {
        $account = new SocialAccount();
        $account->principal_id = $principalId;
        $account->platform = $platform->value;
        $account->kind = $kind->value;
        $account->external_id = $externalId;
        $account->status = SocialAccount::STATUS_ACTIVE;
        $account->connected_at = Carbon::now();
        $account->save();

        // One login, one token: the connection carries it.
        $connection = new SocialConnection();
        $connection->principal_id = $principalId;
        $connection->provider = $platform === SocialPlatform::LinkedIn ? 'linkedin' : 'meta';
        $connection->access_token = $security->encrypt($accessToken)->toStorageString();
        $connection->expires_at = Carbon::now()->addDays(50);
        $connection->scopes = '';
        $connection->save();

        $account->connection_id = $connection->id;
        $account->save();

        $post = new SocialPost();
        $post->principal_id = $principalId;
        $post->body = 'Live check from Spora.';
        $post->status = SocialPost::STATUS_PUBLISHED;
        $post->save();

        $target = new SocialPostTarget();
        $target->post_id = $post->id;
        $target->account_id = $account->id;
        $target->platform = $platform->value;
        $target->status = SocialPostTarget::STATUS_PUBLISHED;
        $target->external_id = $externalId;
        $target->published_at = Carbon::now();
        $target->save();

        return $target;
    }
}
