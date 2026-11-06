<?php

declare(strict_types=1);

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
 *
 * The quotations cover the plugin's **own** networks. The aggregator's
 * rows are a copy of Zernio's documentation: they change when Zernio
 * changes them, Zernio refuses the post itself, and the skill names the
 * aggregator's networks rather than carrying a second copy of limits
 * that rot.
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

    foreach (PlatformFit::nativeChannels() as $channel) {
        $limit = number_format(PlatformFit::characterLimit($channel));

        foreach ($readers as $prose) {
            expect($prose)->toContain($limit);
        }
    }
});

it('quotes the characters a platform shows before it hides the rest', function () {
    // The fold count belongs to the limit it follows, so it is looked for
    // between one platform's limit and the next: "2,200 characters" is the
    // Instagram cap, not the LinkedIn fold.
    $readers = [
        'the social_post tool description' => toolDescriptionOf(SocialPostTool::class),
        'the agent template'               => (string) (socialAgentTemplate()['agent']['system_prompt'] ?? ''),
        'the skill'                        => socialSkill()['body'],
    ];

    $rulesOf = static function (string $prose): array {
        $starts = [];

        foreach (PlatformFit::nativeChannels() as $channel) {
            $position = strpos($prose, number_format(PlatformFit::characterLimit($channel)));

            if ($position !== false) {
                $starts[$channel->network] = $position;
            }
        }

        asort($starts);

        $blocks = [];
        $keys = array_keys($starts);

        foreach ($keys as $index => $key) {
            $end = $starts[$keys[$index + 1]] ?? strlen($prose);
            $blocks[$key] = substr($prose, $starts[$key], $end - $starts[$key]);
        }

        return $blocks;
    };

    foreach ($readers as $prose) {
        $blocks = $rulesOf($prose);

        foreach (PlatformFit::nativeChannels() as $channel) {
            $visible = (string) PlatformFit::rule($channel)['visible_chars'];

            // The count has to stand on its own, or the cap of one
            // platform passes for the fold of another.
            expect($blocks[$channel->network] ?? '')->toMatch('/(?<![\d,])' . $visible . '(?![\d,])/');
        }
    }
});

it('does not ask the agent for a body for every platform', function () {
    // The prose once asked for a body per platform. The model is the
    // other way round: one body for every platform, and a body of its own
    // for a platform only where its copy really differs.
    $readers = [
        'the social_post tool description' => toolDescriptionOf(SocialPostTool::class),
        'the agent template'               => (string) (socialAgentTemplate()['agent']['system_prompt'] ?? ''),
        'the skill'                        => socialSkill()['body'],
    ];

    $claims = [
        'one body per platform',
        'a body per platform',
        'a separate body per platform',
        'one text for three platforms',
    ];

    foreach ($readers as $prose) {
        foreach ($claims as $claim) {
            expect(strtolower($prose))->not->toContain($claim);
        }

        // The override has to be named, or the agent cannot give one
        // platform a copy of its own.
        expect($prose)->toContain('platform_bodies');
    }
});

it('does not claim the backend leaves the limits to the agent', function () {
    // The prose once said the plugin does not enforce the limits. It does,
    // and a stale claim in the operator-facing manual is worse than none.
    $prose = strtolower(socialSkill()['body'] . ' ' . (string) (socialAgentTemplate()['agent']['system_prompt'] ?? ''));

    expect($prose)->not->toContain('does not enforce')
        ->and($prose)->toContain('enforce');
});

it('quotes the aggregator networks and their limits in the skill', function () {
    // The skill is the agent's only description of what the plugin can
    // do, and the aggregator's limits are not the plugin's own: the
    // agent has to know them to write a body that fits.
    $prose = socialSkill()['body'];

    foreach (PlatformFit::channels() as $channel) {
        if ($channel->isDirect()) {
            continue;
        }

        $rule = PlatformFit::rule($channel);

        expect($prose)->toMatch('/\b' . preg_quote($rule['label'], '/') . '\b/')
            ->and($prose)->toContain(number_format($rule['max_characters']));
    }
});

it('says which networks need the aggregator, and what its rules are', function () {
    $prose = socialSkill()['body'];

    // Which networks are the plugin's own and which are not.
    expect($prose)->toContain('Zernio')
        ->and($prose)->toContain('Everything else goes through an aggregator');

    // The limits are the aggregator's, and they differ from the native
    // ones for the same network.
    expect($prose)->toContain("limits are Zernio's")
        ->and($prose)->toContain('differ');

    // It publishes at the moment it is due, not when the aggregator says
    // so.
    expect($prose)->toContain('at the moment it is due');

    // And it may refuse the same content again within a day.
    expect($prose)->toContain('duplicate')
        ->and($prose)->toContain('24 hours');
});

it('does not claim again what the code does not do', function (string $claim) {
    // The skill is the operating manual the model reads, and a claim in
    // it that the plugin does not honour is a claim the model acts on:
    // it would attach a second file, or promise a remedy that is not
    // one. Each phrase below was wrong once, and the correction is the
    // fix — so the phrase must not come back.
    $prose = strtolower(socialSkill()['body']);

    expect($prose)->not->toContain($claim);
})->with([
    'a post may carry several images through the aggregator' => 'may carry several images',
    'deleting the post in Zernio frees the content' => 'which frees the content',
    "the aggregator's numbers are an add-on on its plan" => "numbers are an add-on on its plan",
]);

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
