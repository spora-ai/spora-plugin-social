<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Aggregator;

use Spora\Plugins\Social\Model\SocialAccountKind;

/**
 * What kind of account an aggregator's account is.
 *
 * The kind describes **the network account**; the route describes how
 * the plugin reaches it. They are orthogonal axes, so a Zernio Facebook
 * account is still a Facebook Page — seen through a different route.
 *
 * **What the aggregator says wins.** An account object may carry its own
 * account type, and when it does that is mapped onto the four native
 * kinds. The table below is the fallback for the networks the aggregator
 * says nothing about, and `aggregator` is the fallback under that.
 *
 * The vocabulary the aggregator uses is not the plugin's, so the mapping
 * is a list of spellings rather than a rule: "organization", "company"
 * and "organisation" all mean an organisation page, and "personal" and
 * "user" both mean a member.
 */
final class AggregatorKind
{
    /**
     * The kind a network's account is, for the networks the plugin also
     * reaches natively.
     *
     * @var array<string, string>
     */
    private const BY_NETWORK = [
        'facebook'  => SocialAccountKind::Page->value,
        'instagram' => SocialAccountKind::Business->value,
        'linkedin'  => SocialAccountKind::Member->value,
        'twitter'   => SocialAccountKind::Member->value,
    ];

    /**
     * What the aggregator says about an account, mapped onto the kinds.
     *
     * @var array<string, string>
     */
    private const REPORTED = [
        'organization'  => SocialAccountKind::Organization->value,
        'organisation'  => SocialAccountKind::Organization->value,
        'company'       => SocialAccountKind::Organization->value,
        'page'          => SocialAccountKind::Page->value,
        'business'      => SocialAccountKind::Business->value,
        'creator'       => SocialAccountKind::Business->value,
        'member'        => SocialAccountKind::Member->value,
        'personal'      => SocialAccountKind::Member->value,
        'user'          => SocialAccountKind::Member->value,
        'profile'       => SocialAccountKind::Member->value,
    ];

    /**
     * The kind of one account.
     *
     * @param string      $network  the network's own name
     * @param string|null $reported what the aggregator said about the account, when it said anything
     */
    public static function of(string $network, ?string $reported): string
    {
        $kind = $reported === null ? null : (self::REPORTED[strtolower(trim($reported))] ?? null);

        if ($kind !== null) {
            return $kind;
        }

        return self::BY_NETWORK[strtolower(trim($network))] ?? SocialAccountKind::Aggregator->value;
    }
}
