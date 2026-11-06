<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Settings\SocialSettingsProvider;

it('asks for the member permissions by default and the organisation ones not', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions['LINKEDIN_MEMBER_SCOPE']->default)->toBeTrue()
        ->and($definitions['LINKEDIN_SIGN_IN_SCOPE']->default)->toBeTrue()
        ->and($definitions['LINKEDIN_ORGANIZATION_SCOPE']->default)->toBeFalse()
        ->and($definitions['LINKEDIN_READ_ORG_ACLS']->default)->toBeFalse();
});

it('does not tell the operator to turn off a permission their app holds', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    $member = $definitions['LINKEDIN_MEMBER_SCOPE']->description;

    // The Community Management API product grants w_member_social, so the
    // description may not tell the operator to turn it off for such an app.
    expect($member)->toContain('Community Management API')
        ->and($member)->toContain('does not hold it')
        ->and($member)->not->toContain('Turn it off for an app that holds only the Community Management API product');
});

it('names the organisation list that replaces the sign-in permission', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    // With the sign-in permission off, the member is named by the access
    // control list, and the list is only readable with the permission the
    // setting names.
    expect($definitions['LINKEDIN_SIGN_IN_SCOPE']->description)
        ->toContain('Ask LinkedIn for the administered organisations');
});

/**
 * The redirect URL is setup instruction, and it belongs in the setting it
 * is registered on.
 */
it('puts the meta redirect url in the configuration id setting', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([
        'SPORA_APP_URL' => 'https://spora.example.com',
    ])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions['META_CONFIG_ID']->description)
        ->toContain('Facebook Login for Business → Configurations')
        ->toContain('Register this redirect URL with it: https://spora.example.com/api/v1/plugins/social/oauth/meta/callback');
});

it('puts the linkedin redirect url in the client id setting', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([
        'SPORA_APP_URL' => 'https://spora.example.com',
    ])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions['LINKEDIN_CLIENT_ID']->description)
        ->toContain('Register this redirect URL with it: https://spora.example.com/api/v1/plugins/social/oauth/linkedin/callback');
});

it('says that there is no address to register when the instance has none', function () {
    // The origin falls back to the request's own host, and on the command
    // line there is no request: the guess is localhost, which no platform
    // accepts.
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions['META_CONFIG_ID']->description)
        ->toContain('no public address')
        ->and($definitions['META_CONFIG_ID']->description)->not->toContain('http://localhost')
        ->and($definitions['LINKEDIN_CLIENT_ID']->description)
        ->toContain('no public address')
        ->and($definitions['LINKEDIN_CLIENT_ID']->description)->not->toContain('http://localhost');
});

it('names the redirect url of a development install', function () {
    // The platform refuses a callback it was not given, so a local
    // install has to be told which URI to register — the connect sends
    // exactly that one.
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([
        'SPORA_APP_URL' => 'http://localhost:8080',
        SocialSettings::KEY_ALLOW_LOCAL_APP_URL => true,
    ])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions['LINKEDIN_CLIENT_ID']->description)
        ->toContain('Register this redirect URL with it: http://localhost:8080/api/v1/plugins/social/oauth/linkedin/callback')
        ->and($definitions['META_CONFIG_ID']->description)
        ->toContain('Register this redirect URL with it: http://localhost:8080/api/v1/plugins/social/oauth/meta/callback');
});

it('offers the development switch, off by default', function () {
    $provider = new SocialSettingsProvider(new SocialSettings(new Config([])));

    $definitions = [];

    foreach ($provider->settings() as $definition) {
        $definitions[$definition->key] = $definition;
    }

    expect($definitions)->toHaveKey('ALLOW_LOCAL_APP_URL')
        ->and($definitions['ALLOW_LOCAL_APP_URL']->type)->toBe(Spora\Settings\SettingDefinition::TYPE_TOGGLE)
        ->and($definitions['ALLOW_LOCAL_APP_URL']->default)->toBeFalse()
        // The description has to say what the switch costs, because an
        // operator could leave it on in production.
        ->and($definitions['ALLOW_LOCAL_APP_URL']->description)->toContain('development')
        ->and($definitions['ALLOW_LOCAL_APP_URL']->description)->toContain('Instagram');
});

it('refuses a meta provider that has an app id and a secret but no configuration id', function () {
    // The notice and the refusal read the same answer, so the operator is
    // never told one thing and then refused for another.
    $settings = new SocialSettings(socialConfig([
        SocialSettings::KEY_META_CONFIG_ID => '',
    ]));

    $service = new Spora\Plugins\Social\Service\SocialConnectionService(
        $settings,
        new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
        new Spora\Plugins\Social\OAuth\SocialStateCodec(securityManager()),
        new Spora\Plugins\Social\Service\SocialAppUrlProbe(new Symfony\Component\HttpClient\MockHttpClient([
            new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
        ])),
        new Spora\Plugins\Social\Service\SocialConnectionTokens(
            $settings,
            new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
            securityManager(),
        ),
        aggregatorService(),
    );

    expect($settings->needsSetup(Spora\Plugins\Social\Model\SocialProvider::Meta))->toBeTrue()
        ->and($settings->isConfigured(Spora\Plugins\Social\Model\SocialProvider::Meta))->toBeFalse();

    expect(fn() => $service->startConnect(1, Spora\Plugins\Social\Model\SocialProvider::Meta))
        ->toThrow(
            Spora\Plugins\Social\Exception\SocialException::class,
            'Meta integration needs setup — add the app id, the app secret and the configuration id in Settings → Plugins.',
        );
});

it('refuses to connect with the setup sentence, not a technical one', function () {
    // Nothing is configured for Meta.
    $service = new Spora\Plugins\Social\Service\SocialConnectionService(
        new SocialSettings(new Config([])),
        new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
        new Spora\Plugins\Social\OAuth\SocialStateCodec(securityManager()),
        new Spora\Plugins\Social\Service\SocialAppUrlProbe(new Symfony\Component\HttpClient\MockHttpClient([
            new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
        ])),
        new Spora\Plugins\Social\Service\SocialConnectionTokens(
            new SocialSettings(new Config([])),
            new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]),
            securityManager(),
        ),
        aggregatorService(),
    );

    expect(fn() => $service->startConnect(1, Spora\Plugins\Social\Model\SocialProvider::Meta))
        ->toThrow(
            Spora\Plugins\Social\Exception\SocialException::class,
            'Meta integration needs setup — add the app id, the app secret and the configuration id in Settings → Plugins.',
        );
});
