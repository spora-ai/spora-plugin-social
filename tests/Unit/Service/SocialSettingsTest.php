<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialProvider;
use Spora\Plugins\Social\Service\LinkedInPermissionSettings;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialConnectionTokens;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\SocialFixtures;

/**
 * The public address the plugin hands out.
 *
 * It has to be an address a platform can be given: a relative path is
 * nothing to register, and the Instagram publishing path needs the same
 * origin to serve the media from.
 */
it('prefers the configured app url over the detected origin', function () {
    $settings = new SocialSettings(new Spora\Core\Config([
        'SPORA_APP_URL' => 'https://spora.example.com/',
    ]));

    expect($settings->appUrl())->toBe(SocialFixtures::APP_URL);
});

it('falls back to the detected origin when nothing is configured', function () {
    $settings = new SocialSettings(new Spora\Core\Config([]));

    $origin = $settings->appUrl();

    expect($origin)->not->toBeNull()
        ->and($origin)->toContain('://')
        ->and(parse_url((string) $origin, PHP_URL_HOST))->not->toBeNull();
});

it('answers the redirect uri as an absolute address', function () {
    $settings = new SocialSettings(new Spora\Core\Config([
        'SPORA_APP_URL' => SocialFixtures::APP_URL,
    ]));

    expect($settings->oauthRedirectUri(SocialProvider::Meta))
        ->toBe('https://spora.example.com/api/v1/plugins/social/oauth/meta/callback')
        ->and($settings->oauthRedirectUri(SocialProvider::LinkedIn))
        ->toBe('https://spora.example.com/api/v1/plugins/social/oauth/linkedin/callback');
});

it('refuses an origin that is not an origin', function () {
    // The request carried no host, and the detection answers `http://`.
    expect(SocialSettings::resolveOrigin(null, 'http://'))->toBeNull()
        // A configured value that is not an address is not quietly
        // replaced by the guess.
        ->and(SocialSettings::resolveOrigin('/spora', SocialFixtures::APP_URL))->toBeNull()
        ->and(SocialSettings::resolveOrigin(null, SocialFixtures::APP_URL))->toBe(SocialFixtures::APP_URL);
});

it('refuses to connect when the instance has no public address', function () {
    // The operator set a path instead of an address.
    $settings = new SocialSettings(new Spora\Core\Config([
        'SPORA_APP_URL' => '/spora',
        SocialSettings::KEY_META_APP_ID => 'meta-app-id',
        SocialSettings::KEY_META_APP_SECRET => 'meta-app-secret',
        SocialSettings::KEY_META_CONFIG_ID => 'meta-config-id',
    ]));

    expect($settings->oauthRedirectUri(SocialProvider::Meta))->toBeNull();

    // The connect flow is what says it plainly.
    $service = new SocialConnectionService($settings, new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]), new Spora\Plugins\Social\OAuth\SocialStateCodec(securityManager()), new Spora\Plugins\Social\Service\SocialAppUrlProbe(new Symfony\Component\HttpClient\MockHttpClient([
        new Symfony\Component\HttpClient\Response\MockResponse('', ['http_code' => 200]),
    ])), new SocialConnectionTokens($settings, new Spora\Plugins\Social\Tests\Support\FakeProviderResolver([]), securityManager()));

    expect(fn() => $service->startConnect(1, SocialProvider::Meta))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'no public address');
});

it('reads the LinkedIn permissions the operator asked for', function () {
    $settings = new LinkedInPermissionSettings(new Spora\Core\Config([
        SocialSettings::KEY_LINKEDIN_ORGANIZATION_SCOPE => true,
        SocialSettings::KEY_LINKEDIN_READ_ORG_ACLS => true,
    ]));

    $permissions = $settings->requested();

    expect($permissions->organizationScope)->toBeTrue()
        ->and($permissions->readOrganizationAcls)->toBeTrue()
        ->and($permissions->readOrganizationStats)->toBeFalse()
        ->and($permissions->readMemberFeed)->toBeFalse()
        ->and($permissions->readOrganizationFeed)->toBeFalse();
});
