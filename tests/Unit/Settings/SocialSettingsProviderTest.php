<?php

declare(strict_types=1);

use Spora\Core\Config;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Settings\SocialSettingsProvider;

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
        securityManager(),
        new Spora\Plugins\Social\Service\SocialAppUrlProbe(new Symfony\Component\HttpClient\MockHttpClient([
            new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
        ])),
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
        securityManager(),
        new Spora\Plugins\Social\Service\SocialAppUrlProbe(new Symfony\Component\HttpClient\MockHttpClient([
            new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
        ])),
    );

    expect(fn() => $service->startConnect(1, Spora\Plugins\Social\Model\SocialProvider::Meta))
        ->toThrow(
            Spora\Plugins\Social\Exception\SocialException::class,
            'Meta integration needs setup — add the app id, the app secret and the configuration id in Settings → Plugins.',
        );
});
