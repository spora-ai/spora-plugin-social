<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\OAuth;

use JsonException;
use Spora\Core\SecurityManagerInterface;
use Spora\Plugins\Social\Exception\SocialException;

/**
 * Signs and verifies the OAuth `state`.
 *
 * The state is the only thing that authenticates the OAuth callback: the
 * platform's browser arrives with no session, so the callback cannot ask
 * who is calling. The state therefore carries the principal, the platform,
 * a nonce and the PKCE verifier, and it is signed — an unsigned state
 * would let anyone point a victim's callback at a principal they chose.
 *
 * The signature is an HMAC over the master key rather than a plain hash,
 * so the state cannot be forged without the host's secret.
 */
final class SocialStateCodec
{
    private const SEPARATOR = '.';

    private const DECODE_FAILED = 'The OAuth state could not be decoded.';

    public function __construct(
        private readonly SecurityManagerInterface $security,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $body = $this->base64UrlEncode($json);

        return $body . self::SEPARATOR . $this->base64UrlEncode($this->sign($body));
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $state): array
    {
        $parts = explode(self::SEPARATOR, $state);

        if (count($parts) !== 2) {
            throw SocialException::forbidden('The OAuth state is malformed.');
        }

        [$body, $signature] = $parts;

        $expected = $this->base64UrlEncode($this->sign($body));
        if (!hash_equals($expected, $signature)) {
            throw SocialException::forbidden('The OAuth state signature does not match.');
        }

        $decoded = base64_decode(strtr($body, '-_', '+/'), true);
        if ($decoded === false) {
            throw SocialException::forbidden(self::DECODE_FAILED);
        }

        try {
            $payload = json_decode($decoded, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw SocialException::forbidden(self::DECODE_FAILED);
        }

        if (!is_array($payload)) {
            throw SocialException::forbidden(self::DECODE_FAILED);
        }

        return $payload;
    }

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, $this->security->masterKey(), true);
    }

    private function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
