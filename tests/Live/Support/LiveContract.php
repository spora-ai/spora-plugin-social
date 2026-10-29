<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use PHPUnit\Framework\Assert;

/**
 * The assertions of the live suite.
 *
 * A check states what the platform answered, not that the call did not
 * throw: "the id is a share urn" and "the url is the documented one" are
 * claims a wrong host cannot pass. The message carries the claim and never
 * the value, because a value can be a token.
 */
final class LiveContract
{
    public static function holds(bool $condition, string $claim): void
    {
        Assert::assertTrue($condition, $claim);
    }

    /**
     * The S256 challenge of a verifier.
     *
     * The shape the authorization url has to carry, so the check can say
     * that the challenge belongs to the verifier the exchange will present.
     */
    public static function pkceChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }
}
