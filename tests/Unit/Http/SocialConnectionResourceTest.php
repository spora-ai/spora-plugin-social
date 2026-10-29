<?php

declare(strict_types=1);

use Spora\Plugins\Social\Http\SocialConnectionResource;

/**
 * The wire shape the frontend and the docs are built against.
 */
it('carries the connection health and the accounts it covers', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'meta', [
        'access_token' => 'the-suat',
        'scopes'       => 'instagram_basic instagram_manage_insights',
        'config_id'    => '9876',
        'business_external_id' => '123456',
    ]);

    $account = createSocialAccount($principalId, 'instagram', 'business', [
        'connection'   => $connection,
        'external_id'  => '17841405822304914',
        'display_name' => 'Fabeat Studio',
        'username'     => 'fabeat.studio',
        'followers'    => 18750,
    ]);

    $payload = SocialConnectionResource::toArray($connection->fresh() ?? $connection);

    expect($payload['id'])->toBe((int) $connection->id)
        ->and($payload['provider'])->toBe('meta')
        ->and($payload['business_external_id'])->toBe('123456')
        ->and($payload['config_id'])->toBe('9876')
        ->and($payload['expires_at'])->toBeNull()
        ->and($payload['status'])->toBe('active')
        ->and($payload['needs_reconnect'])->toBeFalse()
        ->and($payload['scopes'])->toBe(['instagram_basic', 'instagram_manage_insights']);

    expect($payload['accounts'])->toHaveCount(1)
        ->and($payload['accounts'][0]['id'])->toBe((int) $account->id)
        ->and($payload['accounts'][0]['platform'])->toBe('instagram')
        ->and($payload['accounts'][0]['kind'])->toBe('business')
        ->and($payload['accounts'][0]['display_name'])->toBe('Fabeat Studio')
        ->and($payload['accounts'][0]['followers'])->toBe(18750)
        ->and($payload['accounts'][0]['can_report'])->toBeTrue()
        ->and($payload['accounts'][0]['report_note'])->toBeNull();
});

it('says on the account that it cannot report, and why', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'scopes'       => 'openid profile w_member_social',
    ]);

    createSocialAccount($principalId, 'linkedin', 'organization', ['connection' => $connection]);

    $payload = SocialConnectionResource::toArray($connection->fresh() ?? $connection);

    expect($payload['accounts'][0]['can_report'])->toBeFalse()
        ->and($payload['accounts'][0]['report_note'])->toContain('rw_organization_admin');
});

it('flags a connection whose token has to be renewed', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('+5 days'),
    ]);

    $payload = SocialConnectionResource::toArray($connection->fresh() ?? $connection);

    expect($payload['needs_reconnect'])->toBeTrue()
        ->and($payload['status'])->toBe('active');
});

it('reports an expired connection as expired', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'linkedin', [
        'access_token' => 'the-token',
        'expires_at'   => new DateTimeImmutable('-1 day'),
    ]);

    $payload = SocialConnectionResource::toArray($connection->fresh() ?? $connection);

    expect($payload['status'])->toBe('expired')
        ->and($payload['needs_reconnect'])->toBeTrue();
});
