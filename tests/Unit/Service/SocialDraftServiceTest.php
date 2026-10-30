<?php

declare(strict_types=1);

use Spora\Drivers\DriverFactory;
use Spora\Drivers\LLMDriverInterface;
use Spora\Drivers\ValueObjects\LLMRequest;
use Spora\Drivers\ValueObjects\LLMResponse;
use Spora\Models\LLMDriverConfiguration;
use Spora\Models\PrincipalPreference;
use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\Service\SocialDraftService;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Spora\Services\LLMConfigPreferences;

/**
 * The drafts the plugin writes, and the model that writes them.
 */

/**
 * The draft service, with the language model it talks to replaced.
 *
 * @param  list<LLMRequest> $asked the requests the service made
 */
function draftService(string $answer, array &$asked = []): SocialDraftService
{
    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->shouldReceive('complete')
        ->andReturnUsing(static function (LLMRequest $request) use ($answer, &$asked): LLMResponse {
            $asked[] = $request;

            return new LLMResponse($answer, [], 0, 0, 'the-answer');
        });

    $factory = Mockery::mock(DriverFactory::class);
    $factory->shouldReceive('makeDriverFromConfig')->andReturn($driver);

    return new SocialDraftService(new LLMConfigPreferences(), $factory);
}

/**
 * A principal with a language model of its own.
 */
function aPrincipalWithAModel(): int
{
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $config = new LLMDriverConfiguration();
    $config->name = 'the-model';
    $config->driver_class = 'the-driver';
    $config->principal_id = $principalId;
    $config->is_global = false;
    $config->save();

    $preference = PrincipalPreference::query()->firstOrNew(['principal_id' => $principalId]);
    $preference->preferred_llm_config_id = $config->id;
    $preference->save();

    return $principalId;
}

/**
 * A global default configuration, for the principal that has no preferred one.
 */
function aGlobalDefaultConfiguration(): void
{
    $config = new LLMDriverConfiguration();
    $config->name = 'the-default-model';
    $config->driver_class = 'the-driver';
    $config->principal_id = null;
    $config->is_global = true;
    $config->is_default = true;
    $config->save();
}

it('writes a body per platform', function () {
    $asked = [];
    $principalId = aPrincipalWithAModel();

    $service = draftService(json_encode([
        'title'    => 'The launch',
        'bodies'   => [
            'linkedin'  => 'A long post for LinkedIn.',
            'instagram' => 'A short one for Instagram.',
        ],
        'hashtags' => ['#launch', 'shipit'],
    ]), $asked);

    $draft = $service->generate($principalId, SocialFixtures::BRIEF, ['linkedin', 'instagram']);

    expect($draft['title'])->toBe('The launch')
        ->and($draft['bodies'])->toBe([
            'linkedin'  => 'A long post for LinkedIn.',
            'instagram' => 'A short one for Instagram.',
        ])
        ->and($draft['hashtags'])->toBe(['launch', 'shipit']);

    expect($asked)->toHaveCount(1);
    expect($asked[0]->messages[0]['content'])->toContain(SocialFixtures::BRIEF)
        ->and($asked[0]->messages[0]['content'])->toContain('linkedin, instagram');
});

it('asks for every platform when the caller names none', function () {
    $asked = [];
    $principalId = aPrincipalWithAModel();

    $service = draftService('{"bodies":{"linkedin":"One"}}', $asked);

    $service->generate($principalId, SocialFixtures::BRIEF, []);

    expect($asked[0]->messages[0]['content'])->toContain('linkedin, facebook, instagram');
});

it('falls back to the single body for a platform the model did not write for', function () {
    $principalId = aPrincipalWithAModel();

    $service = draftService('{"body":"The same copy everywhere","bodies":{"linkedin":"Its own"}}');

    $draft = $service->generate($principalId, SocialFixtures::BRIEF, ['linkedin', 'instagram']);

    expect($draft['bodies'])->toBe([
        'linkedin'  => 'Its own',
        'instagram' => 'The same copy everywhere',
    ]);
});

it('keeps the copy when the model answers in prose', function () {
    $principalId = aPrincipalWithAModel();

    $service = draftService(SocialFixtures::PROSE_ANSWER);

    $draft = $service->generate($principalId, SocialFixtures::BRIEF, ['linkedin', 'instagram']);

    expect($draft['title'])->toBe('Draft')
        ->and($draft['bodies'])->toBe([
            'linkedin'  => SocialFixtures::PROSE_ANSWER,
            'instagram' => SocialFixtures::PROSE_ANSWER,
        ])
        ->and($draft['hashtags'])->toBe([]);
});

it('reads the copy out of a fenced answer', function () {
    $principalId = aPrincipalWithAModel();

    $service = draftService("```json\n{\"bodies\":{\"linkedin\":\"From inside the fence\"}}\n```");

    $draft = $service->generate($principalId, SocialFixtures::BRIEF, ['linkedin']);

    expect($draft['bodies'])->toBe(['linkedin' => 'From inside the fence']);
});

it('names the accounts the post goes to in the prompt', function () {
    $asked = [];
    $principalId = aPrincipalWithAModel();

    $service = draftService('{}', $asked);

    $service->generate($principalId, SocialFixtures::BRIEF, ['linkedin'], [11, 12]);

    expect($asked[0]->messages[0]['content'])->toContain('11, 12');
});

it('refuses a brief that says nothing', function () {
    $principalId = aPrincipalWithAModel();

    $service = draftService('{}');

    expect(fn() => $service->generate($principalId, '   ', []))
        ->toThrow(SocialException::class, 'a brief');
});

it('refuses when no language model is configured', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $service = draftService('{}');

    expect(fn() => $service->generate($principalId, SocialFixtures::BRIEF, []))
        ->toThrow(SocialException::class, 'No language model is configured');
});

it('falls back to the global default configuration', function () {
    aGlobalDefaultConfiguration();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $service = draftService('{}');

    expect($service->generate($principalId, SocialFixtures::BRIEF, []))->toHaveKey('bodies');
});

it('reports a model that could not answer', function () {
    $principalId = aPrincipalWithAModel();

    $driver = Mockery::mock(LLMDriverInterface::class);
    $driver->shouldReceive('complete')->andThrow(new RuntimeException('the provider is down'));

    $factory = Mockery::mock(DriverFactory::class);
    $factory->shouldReceive('makeDriverFromConfig')->andReturn($driver);

    $service = new SocialDraftService(new LLMConfigPreferences(), $factory);

    expect(fn() => $service->generate($principalId, SocialFixtures::BRIEF, []))
        ->toThrow(SocialException::class, 'did not answer');
});
