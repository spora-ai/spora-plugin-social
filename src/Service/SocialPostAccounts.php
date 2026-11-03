<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use Illuminate\Support\Carbon;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialPost;

/**
 * The accounts a post goes to.
 *
 * A draft may have none: the accounts name the platforms, and a draft
 * has not been sent anywhere yet. A post that goes out needs at least
 * one, or it has no platform to go to.
 *
 * Kept out of {@see SocialPostService}: the rule is a question of its
 * own, and one class that answers it and writes the post grows past the
 * twenty methods a class may have.
 */
final class SocialPostAccounts
{
    public function __construct(
        private readonly SocialConnectionService $connections,
    ) {}

    /**
     * The accounts a post goes to.
     *
     * @param  list<int> $accountIds
     * @return list<SocialAccount>
     */
    public function resolve(int $principalId, array $accountIds, bool $required): array
    {
        if ($accountIds === []) {
            if ($required) {
                throw $this->noAccount($principalId);
            }

            return [];
        }

        $accounts = [];
        foreach ($accountIds as $accountId) {
            $accounts[] = $this->connections->findAccount($accountId, $principalId);
        }

        return $accounts;
    }

    /**
     * Whether the post goes out, after an update.
     *
     * A draft that the update dates goes out; a post that is already
     * scheduled or published does whatever it does.
     */
    public function goesOut(SocialPost $post, ?Carbon $scheduledFor): bool
    {
        return $post->status !== SocialPost::STATUS_DRAFT || $scheduledFor !== null;
    }

    /**
     * The refusal, and the way out of it.
     *
     * Two cases, and the way out differs: a principal with no account
     * has to connect one, and a principal with accounts has to name one.
     */
    public function noAccount(int $principalId): SocialException
    {
        if (!SocialAccount::query()->forPrincipal($principalId)->exists()) {
            return SocialException::validation('No social account is connected for this principal. Connect one first: `social_connections` with action `connect` returns the URL for the operator to open.');
        }

        return SocialException::validation('A post needs at least one account to publish to.');
    }
}
