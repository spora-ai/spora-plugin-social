<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

use Spora\Plugins\Social\Model\SocialPlatform;

/**
 * Why a live check cannot run.
 *
 * The reasons name the environment variable that is missing, so the
 * operator is told what to set rather than that something is absent.
 */
final class LiveRequirements
{
    public function __construct(
        private readonly LiveContext $context,
    ) {}

    public function missing(SocialPlatform $platform, int $needs): ?string
    {
        $missing = $this->missingCredential($platform);

        if ($missing !== null) {
            return $missing;
        }

        $missing = $this->missingAuthorization($platform);

        if ($missing !== null) {
            return $missing;
        }

        return $this->missingOutcome($platform, $needs);
    }

    /**
     * The credential the run needs and does not have.
     */
    private function missingCredential(SocialPlatform $platform): ?string
    {
        $credentials = $this->context->credentials;
        $names = $this->context->names;

        if ($credentials->clientId($platform) === null || $credentials->clientSecret($platform) === null) {
            return sprintf(
                'the %s credentials are not in the environment (%s and %s)',
                $platform->value,
                $names->clientIdName($platform),
                $names->clientSecretName($platform),
            );
        }

        if ($this->context->redirectUri($platform) === '') {
            return sprintf(
                '%s is not set, so there is no redirect uri to send the browser to',
                LiveCredentials::ENV_REDIRECT_URI,
            );
        }

        return null;
    }

    /**
     * The sign-in the run needs and does not have.
     */
    private function missingAuthorization(SocialPlatform $platform): ?string
    {
        $credentials = $this->context->credentials;
        $names = $this->context->names;

        if ($credentials->authorizationCode($platform) === null) {
            return sprintf(
                'no authorization code for %s (%s is not set — the operator did not complete the sign-in)',
                $platform->value,
                $names->authorizationCodeName($platform),
            );
        }

        if ($credentials->pkceVerifier($platform) === null) {
            return sprintf(
                'no pkce verifier for %s (%s is not set)',
                $platform->value,
                $names->pkceVerifierName($platform),
            );
        }

        return null;
    }

    /**
     * What the run did not manage to do.
     */
    private function missingOutcome(SocialPlatform $platform, int $needs): ?string
    {
        $missing = $this->missingToken($platform, $needs);

        if ($missing !== null) {
            return $missing;
        }

        if (($needs & LiveCheck::PUBLIC_IMAGE) !== 0 && $this->context->credentials->publicImageUrl() === null) {
            return sprintf(
                '%s is not set, so there is no publicly reachable image to publish',
                LiveCredentials::ENV_PUBLIC_IMAGE_URL,
            );
        }

        return $this->missingPublication($platform, $needs);
    }

    /**
     * The token the run did not buy.
     */
    private function missingToken(SocialPlatform $platform, int $needs): ?string
    {
        $provider = $this->context->names->providerOf($platform);

        if (($needs & LiveCheck::TOKEN) === 0 || $this->context->hasToken($provider)) {
            return null;
        }

        return $this->context->hasExchange($provider)
            ? sprintf('the %s token exchange did not produce a token', $provider)
            : sprintf('the %s token exchange did not run in this session', $provider);
    }

    /**
     * The publication the run did not make.
     */
    private function missingPublication(SocialPlatform $platform, int $needs): ?string
    {
        if (($needs & LiveCheck::PUBLISHED) !== 0 && $this->context->published($platform) === null) {
            return sprintf('nothing was published on %s in this run', $platform->value);
        }

        return null;
    }
}
