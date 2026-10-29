<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialPlatform;
use Spora\Plugins\Social\Service\PlatformFit;
use Spora\Plugins\Social\Tools\SocialPostTool;

/**
 * The platform rules live in {@see PlatformFit} and are read in several
 * places: the publish-time guard, the tool descriptions the model reads,
 * the agent template's system prompt and the skill.
 *
 * The numbers are therefore quoted, and this test is what keeps the
 * quotations honest: change a limit in the table and the prose has to be
 * updated with it, or the operator and the agent are told two different
 * numbers.
 */
it('quotes the character limits the backend enforces', function () {
    // Each reader of the table quotes it: the tool description the model
    // reads, the template's system prompt, and the skill. A reader that
    // forgets a number would send the operator a post the backend refuses.
    $readers = [
        'the social_post tool description' => toolDescriptionOf(SocialPostTool::class),
        'the agent template'               => (string) (socialAgentTemplate()['agent']['system_prompt'] ?? ''),
        'the skill'                        => socialSkill()['body'],
    ];

    foreach (SocialPlatform::cases() as $platform) {
        $limit = number_format(PlatformFit::characterLimit($platform));

        foreach ($readers as $name => $prose) {
            expect($prose)->toContain($limit);
        }
    }
});

it('does not claim the backend leaves the limits to the agent', function () {
    // The prose once said the plugin does not enforce the limits. It does,
    // and a stale claim in the operator-facing manual is worse than none.
    $prose = strtolower(socialSkill()['body'] . ' ' . (string) (socialAgentTemplate()['agent']['system_prompt'] ?? ''));

    expect($prose)->not->toContain('does not enforce')
        ->and($prose)->toContain('enforce');
});

it('declares the skill the tools bundle', function () {
    foreach ((new Spora\Plugins\Social\SocialPlugin())->tools() as $class) {
        $attributes = (new ReflectionClass($class))->getAttributes(Spora\Tools\Attributes\Tool::class);

        expect($attributes)->not->toBeEmpty()
            ->and($attributes[0]->newInstance()->getRecommendsSkills())->toContain('social-poster');
    }
});

it('backs the recommended skill with a directory that ships', function () {
    $skills = glob(dirname(__DIR__, 2) . '/skills/*', GLOB_ONLYDIR) ?: [];

    $slugs = array_map(static fn(string $path): string => basename($path), $skills);

    expect($slugs)->toContain('social-poster');
});
