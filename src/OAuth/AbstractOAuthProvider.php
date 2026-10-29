<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Grant\GrantFactory;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessTokenInterface;
use Psr\Http\Message\ResponseInterface;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialProvider;
use Throwable;

/**
 * The shared machinery of the two OAuth flows.
 *
 * Built on `league/oauth2-client`'s provider core: it owns the
 * authorization URL, the PKCE challenge, the authorization-code exchange
 * and the refresh grant. The providers differ in their endpoints and in
 * what their token exchange looks like, so those live in the subclasses.
 *
 * Guzzle arrives with the library, and the identity calls below use the
 * same client — so a plugin install pulls one HTTP stack for OAuth and a
 * second for publishing, each used for one thing.
 */
abstract class AbstractOAuthProvider extends AbstractProvider implements OAuthProviderInterface
{
    public function __construct(
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        ?ClientInterface $httpClient = null,
        ?GrantFactory $grantFactory = null,
    ) {
        parent::__construct(
            [
                'clientId'     => $clientId,
                'clientSecret' => $clientSecret,
                'redirectUri'  => $redirectUri,
            ],
            [
                'httpClient'  => $httpClient ?? new Client(),
                'grantFactory' => $grantFactory ?? new GrantFactory(),
            ],
        );
    }

    abstract public function provider(): SocialProvider;

    public function getBaseAuthorizationUrl(): string
    {
        return $this->authorizeUrl();
    }

    public function getBaseAccessTokenUrl(array $params): string
    {
        return $this->tokenUrl();
    }

    public function getResourceOwnerDetailsUrl(AccessTokenInterface $token): string
    {
        return $this->resourceOwnerUrl();
    }

    abstract protected function authorizeUrl(): string;

    abstract protected function tokenUrl(): string;

    abstract protected function resourceOwnerUrl(): string;

    /**
     * The identity behind the token.
     *
     * The plugin does not use league's resource owner — it reads the
     * platform's identity endpoint itself, because the answer it needs is
     * platform-shaped (a member id, a page id, an Instagram user id) and
     * not a generic profile.
     *
     * @param array<string, mixed> $response
     */
    protected function createResourceOwner(array $response, AccessTokenInterface $token): ResourceOwnerInterface
    {
        return new GenericResourceOwner($response, 'id');
    }

    public function authorizationUrl(string $state, string $pkceVerifier): string
    {
        $this->pkceCode = $pkceVerifier;

        return $this->getAuthorizationUrl(['state' => $state]);
    }

    public function exchangeCode(string $code, string $pkceVerifier): OAuthTokenSet
    {
        $this->pkceCode = $pkceVerifier;

        return $this->toTokenSet($this->requestToken('authorization_code', ['code' => $code]));
    }

    public function refresh(OAuthTokenSet $tokens): OAuthTokenSet
    {
        $refreshToken = $tokens->refreshToken;
        if ($refreshToken === null || $refreshToken === '') {
            throw SocialException::platform('This connection has no refresh token, so it cannot be renewed.', 'TOKEN_NOT_REFRESHABLE');
        }

        // A refresh is not a PKCE exchange; leaving the verifier in place
        // would send a `code_verifier` the platform did not ask for.
        $this->pkceCode = null;

        return $this->toTokenSet($this->requestToken('refresh_token', ['refresh_token' => $refreshToken]));
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function requestToken(string $grant, array $options): AccessTokenInterface
    {
        try {
            return $this->getAccessToken($grant, $options);
        } catch (Throwable $e) {
            throw SocialException::platform($this->describeFailure($e), 'OAUTH_EXCHANGE_FAILED');
        }
    }

    /**
     * @param array<string, mixed> $extra
     */
    protected function toTokenSet(AccessTokenInterface $token, array $extra = []): OAuthTokenSet
    {
        $expiresAt = null;
        if ($token->getExpires() !== null) {
            $expiresAt = new DateTimeImmutable('@' . $token->getExpires());
        }

        $values = $token->getValues();

        return new OAuthTokenSet(
            accessToken: $token->getToken(),
            refreshToken: $this->stringOrNull($token->getRefreshToken()),
            expiresAt: $expiresAt,
            scopes: $this->scopesOf($values),
            extra: $extra,
        );
    }

    /**
     * A GET against the platform's API, decoded.
     *
     * The query string is built here rather than left to the HTTP client:
     * league's request factory only knows about headers, a body and a
     * protocol version, so a `query` option would be dropped on the floor.
     *
     * @param array<string, mixed>  $query
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    protected function apiGet(string $url, array $query = [], array $headers = []): array
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
        }

        try {
            $parsed = $this->getParsedResponse($this->getRequest('GET', $url, [
                'headers' => $headers,
            ]));
        } catch (Throwable $e) {
            throw SocialException::platform($this->describeFailure($e), 'PLATFORM_REQUEST_FAILED');
        }

        return is_array($parsed) ? $parsed : [];
    }

    /**
     * league's default authorization parameters carry `approval_prompt`,
     * which is Google's parameter. The three platforms here ignore it, but
     * a parameter one of them rejects is a failed connect, so it goes.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected function getAuthorizationParameters(array $options): array
    {
        $parameters = parent::getAuthorizationParameters($options);
        unset($parameters['approval_prompt']);

        return $parameters;
    }

    protected function getPkceMethod(): ?string
    {
        return self::PKCE_METHOD_S256;
    }

    /**
     * The verifier the caller supplied is kept: it travels to the platform
     * in the challenge and comes back in the signed state, so the provider
     * must not replace it with a fresh one.
     */
    protected function getRandomPkceCode($length = 64): string
    {
        return $this->pkceCode ?? parent::getRandomPkceCode($length);
    }

    /**
     * @param array<string, mixed>|string $data
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (!is_array($data)) {
            return;
        }

        $error = $data['error'] ?? null;
        if ($error === null) {
            return;
        }

        $message = is_array($error)
            ? (string) ($error['message'] ?? $error['error'] ?? 'Unknown error')
            : (string) $error;

        $description = (string) ($data['error_description'] ?? '');

        throw new IdentityProviderException(
            trim($message . ($description !== '' ? ' — ' . $description : '')),
            0,
            $data,
        );
    }

    /**
     * The scopes the platform answered with, split the way the platform
     * joins them.
     *
     * Meta answers with commas and LinkedIn with spaces, so the separator
     * is the provider's own: splitting on the wrong one leaves the whole
     * scope string as a single scope, and every scope check then fails.
     *
     * @param array<string, mixed> $values
     * @return list<string>
     */
    protected function scopesOf(array $values): array
    {
        $scope = $values['scope'] ?? null;
        if (!is_string($scope) || $scope === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode($this->getScopeSeparator(), $scope))));
    }

    protected function describeFailure(Throwable $e): string
    {
        if ($e instanceof IdentityProviderException) {
            return $e->getMessage();
        }

        return $e->getMessage() !== '' ? $e->getMessage() : 'The platform did not answer as expected.';
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return $value;
    }
}
