<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * The names of the variables the live run reads.
 *
 * The names are a fact about the harness, not about the environment: an
 * operator is told which variable to set, and the reason a check cannot
 * run names it. Keeping them apart from the values means the values can
 * be read without knowing how the variables are spelled.
 */
final class LiveVariableNames
{
    /**
     * The login a platform belongs to.
     *
     * Meta's one login covers Instagram and Facebook together, so the
     * credentials are the provider's and not the network's.
     */
    public function providerOf(SocialPlatform $platform): string
    {
        return $platform === SocialPlatform::LinkedIn ? 'linkedin' : 'meta';
    }

    public function clientIdName(SocialPlatform $platform): string
    {
        return $this->name($platform, 'CLIENT_ID');
    }

    public function clientSecretName(SocialPlatform $platform): string
    {
        return $this->name($platform, 'CLIENT_SECRET');
    }

    public function authorizationCodeName(SocialPlatform $platform): string
    {
        return $this->name($platform, 'AUTHORIZATION_CODE');
    }

    public function pkceVerifierName(SocialPlatform $platform): string
    {
        return $this->name($platform, 'PKCE_VERIFIER');
    }

    /**
     * The variables that may hold the redirect URL, canonical first.
     *
     * @return list<string>
     */
    public function redirectUrlNames(string $provider): array
    {
        $names = ['SPORA_LIVE_' . strtoupper($provider) . '_REDIRECT_URL'];

        foreach ($provider === 'meta' ? ['facebook', 'instagram'] : [$provider] as $platform) {
            $names[] = 'SPORA_LIVE_' . strtoupper($platform) . '_REDIRECT_URL';
        }

        return $names;
    }

    private function name(SocialPlatform $platform, string $suffix): string
    {
        return 'SPORA_LIVE_' . strtoupper($this->providerOf($platform)) . '_' . $suffix;
    }
}
