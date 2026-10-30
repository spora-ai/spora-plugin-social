<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Service;

use JsonException;
use Spora\Drivers\DriverFactory;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Models\LLMDriverConfiguration;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Services\LLMConfigPreferences;
use Throwable;

/**
 * Drafts a post body per platform.
 *
 * The call path is the orchestrator's: resolve the principal's LLM
 * configuration, build an `LLMRequest`, then
 * `DriverFactory::make(...)->complete($request)`. Nothing here talks to a
 * provider directly, so the operator's configured model and its settings
 * are the ones that answer.
 *
 * A body per platform, because the drafts that work for LinkedIn and
 * Facebook are genuinely different, and that difference is the point.
 */
final class SocialDraftService
{
    private const SYSTEM_PROMPT = 'You write social media posts. You answer with JSON and nothing else.';

    public function __construct(
        private readonly LLMConfigPreferences $preferences,
        private readonly DriverFactory $driverFactory,
    ) {}

    /**
     * @param list<string> $platforms
     * @param list<int>   $accountIds
     * @return array{title: string, bodies: array<string, string>, hashtags: list<string>}
     */
    public function generate(int $principalId, string $brief, array $platforms, array $accountIds = []): array
    {
        $brief = trim($brief);
        if ($brief === '') {
            throw SocialException::validation('A draft needs a brief to write from.');
        }

        $platforms = $platforms !== [] ? $platforms : SocialPlatform::values();

        $driver = $this->driverFactory->makeDriverFromConfig($this->configurationFor($principalId));

        $request = new LLMRequest(
            systemPrompt: self::SYSTEM_PROMPT,
            messages: [
                ['role' => 'user', 'content' => $this->prompt($brief, $platforms, $accountIds)],
            ],
            tools: [],
            maxTokens: 1600,
            temperature: 0.7,
        );

        try {
            $response = $driver->complete($request);
        } catch (Throwable $e) {
            throw SocialException::platform('The language model did not answer: ' . $e->getMessage(), 'DRAFT_FAILED');
        }

        return $this->parse((string) $response->content, $platforms);
    }

    /**
     * The principal's preferred configuration, or the global default.
     */
    private function configurationFor(int $principalId): LLMDriverConfiguration
    {
        $config = $this->preferences->getPrincipalPreferredConfig($principalId);

        if ($config === null) {
            $config = LLMDriverConfiguration::query()
                ->where('is_global', true)
                ->where('is_default', true)
                ->first();
        }

        if ($config === null) {
            throw SocialException::validation(
                'No language model is configured for this principal. Set a preferred configuration, or a global default.',
            );
        }

        return $config;
    }

    /**
     * @param list<string> $platforms
     * @param list<int>   $accountIds
     */
    private function prompt(string $brief, array $platforms, array $accountIds): string
    {
        $platformList = implode(', ', $platforms);

        $accounts = '';
        if ($accountIds !== []) {
            $accounts = "\nThe post goes to the connected account ids: " . implode(', ', $accountIds) . ".";
        }

        return <<<PROMPT
        Write social posts for these platforms: {$platformList}.

        The brief:
        {$brief}
        {$accounts}

        Answer with a JSON object and nothing else, in exactly this shape:
        {
          "title": "an internal label, never published",
          "bodies": { "platform name": "the post text for that platform" },
          "hashtags": ["tag"]
        }

        Give every platform in the list its own body: the platforms have different
        conventions, lengths and audiences, and one text for all of them reads as
        an afterthought on at least one of them.
        PROMPT;
    }

    /**
     * @param list<string> $platforms
     * @return array{title: string, bodies: array<string, string>, hashtags: list<string>}
     */
    private function parse(string $content, array $platforms): array
    {
        $decoded = $this->decodeJson($content);

        if ($decoded === null) {
            // A model that answered in prose still gave usable copy; the
            // bodies are the same text for every platform rather than a
            // failed request.
            return [
                'title'    => 'Draft',
                'bodies'   => array_fill_keys($platforms, trim($content)),
                'hashtags' => [],
            ];
        }

        return [
            'title'    => is_string($decoded['title'] ?? null) ? $decoded['title'] : 'Draft',
            'bodies'   => $this->bodiesOf($decoded, $platforms),
            'hashtags' => $this->hashtagsOf($decoded),
        ];
    }

    /**
     * A body per platform.
     *
     * A platform the model did not write for falls back to the model's
     * single body, and to nothing at all when there is none.
     *
     * @param  array<string, mixed> $decoded
     * @param  list<string>         $platforms
     * @return array<string, string>
     */
    private function bodiesOf(array $decoded, array $platforms): array
    {
        $bodies = [];

        $rawBodies = $decoded['bodies'] ?? [];
        if (is_array($rawBodies)) {
            foreach ($rawBodies as $platform => $body) {
                if (is_string($platform) && is_string($body)) {
                    $bodies[$platform] = $body;
                }
            }
        }

        $fallback = is_string($decoded['body'] ?? null) ? $decoded['body'] : '';

        foreach ($platforms as $platform) {
            if (!isset($bodies[$platform])) {
                $bodies[$platform] = $fallback;
            }
        }

        return $bodies;
    }

    /**
     * The hashtags the model named, without their hashes.
     *
     * @param  array<string, mixed> $decoded
     * @return list<string>
     */
    private function hashtagsOf(array $decoded): array
    {
        $hashtags = [];

        foreach ((array) ($decoded['hashtags'] ?? []) as $hashtag) {
            if (is_string($hashtag) && $hashtag !== '') {
                $hashtags[] = ltrim($hashtag, '#');
            }
        }

        return $hashtags;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(string $content): ?array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = (string) preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $content);
        }

        try {
            $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
