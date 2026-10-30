<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Http;

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialSettings;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where the platform sends the browser back to.
 *
 * This route is **not** behind the session middleware: the platform's
 * browser has no session, so the signed state is the authentication.
 *
 * The state carries the principal, so the callback knows which principal
 * it is connecting an account for without asking anyone.
 */
final class OAuthCallbackController
{
    public function __construct(
        private readonly SocialConnectionService $connections,
        private readonly SocialSettings $settings,
    ) {}

    public function callback(Request $request): Response
    {
        $provider = SocialProvider::tryFromName((string) $request->attributes->get('provider'));
        $code = trim((string) $request->query->get('code', ''));
        $state = trim((string) $request->query->get('state', ''));

        if ($provider === null) {
            throw SocialException::validation('The callback does not name a known provider.');
        }

        if ($code === '' || $state === '') {
            throw SocialException::validation('The callback is missing its code or its state.');
        }

        try {
            $result = $this->connections->completeConnect($code, $state);
        } catch (SocialException $e) {
            return new JsonResponse(
                ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]],
                $e->status,
            );
        }

        $frontend = rtrim($this->settings->frontendUrl(), '/');

        return new RedirectResponse(sprintf(
            '%s/apps/social/p/%d/connections?connected=%s',
            $frontend,
            $result['principal_id'],
            $provider->value,
        ));
    }
}
