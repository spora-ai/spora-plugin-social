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
     * Three cases, and the way out differs: a principal with no account
     * has to connect one, a principal with only aggregator accounts has
     * to add one on the Accounts screen — there is no connect URL for an
     * aggregator — and a principal with accounts has to name one.
     */
    public function noAccount(int $principalId): SocialException
    {
        $accounts = SocialAccount::query()->forPrincipal($principalId)->get();

        if ($accounts->isEmpty()) {
            return SocialException::validation('No social account is connected for this principal. Connect one first: `social_connections` with action `connect` returns the URL for the operator to open.');
        }

        // The only accounts there are aggregator accounts: the operator
        // manages them in the aggregator's own dashboard, and there is
        // no URL for the plugin to hand back.
        $native = $accounts->contains(
            static fn(SocialAccount $account): bool => $account->channel()->isDirect(),
        );

        if (!$native) {
            return SocialException::validation('No native social account is connected for this principal. Add one on the Accounts screen — an aggregator account is managed in the aggregator\'s own dashboard.');
        }

        return SocialException::validation('A post needs at least one account to publish to.');
    }
}
