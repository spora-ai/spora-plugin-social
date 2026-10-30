<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Live\Support;

/**
 * The secrets a live run holds, and the scrubbing of anything carrying them.
 *
 * A Graph API call puts its access token in the query string, so an
 * exception that carries the request URL carries the token with it — and a
 * failure message ends up in the operator's terminal history for good. The
 * values the run knows are therefore removed from every string it writes,
 * and the shapes that carry a token are scrubbed even for a value the run
 * never held, such as a token the platform handed out mid-flow.
 */
final class LiveSecrets
{
    /** @var list<string> */
    private array $values = [];

    /**
     * A value the run must never write down.
     *
     * Short values are ignored on purpose: a two-character secret would
     * match half the text in a message and turn the whole report into
     * asterisks.
     */
    public function remember(?string $secret): void
    {
        if ($secret === null || strlen($secret) < 8) {
            return;
        }

        if (!in_array($secret, $this->values, true)) {
            $this->values[] = $secret;
        }
    }

    public function redact(string $text): string
    {
        foreach ($this->values as $value) {
            $text = str_replace($value, '***redacted***', $text);
        }

        return $this->scrub($text);
    }

    private function scrub(string $text): string
    {
        $patterns = [
            '/(access_token|page_access_token|appsecret|client_secret|fb_exchange_token|exchange_token|refresh_token|code_verifier|code|state)=([^&\s"\'<>]+)/i' => '$1=***redacted***',
            '/"(access_token|page_access_token|appsecret|client_secret|refresh_token|code_verifier|code|state)"\s*:\s*"[^"]*"/i' => '"$1":"***redacted***"',
            '/\b(Bearer|OAuth)\s+([A-Za-z0-9\-._~+\/]+=*)/i' => '$1 ***redacted***',
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{4,}\.[A-Za-z0-9_-]*/' => '***redacted***',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return $text;
    }
}
