<?php

declare(strict_types=1);

use Spora\Plugins\Social\Exception\SocialException;
use Spora\Plugins\Social\OAuth\SocialStateCodec;

it('round-trips a signed state', function () {
    $codec = new SocialStateCodec(securityManager());

    $state = $codec->encode([
        'principal_id' => 3,
        'platform'     => 'linkedin',
        'nonce'        => 'abc',
        'pkce'         => 'verifier',
    ]);

    expect($codec->decode($state))->toBe([
        'principal_id' => 3,
        'platform'     => 'linkedin',
        'nonce'        => 'abc',
        'pkce'         => 'verifier',
    ]);
});

it('refuses a state whose signature does not match', function () {
    $codec = new SocialStateCodec(securityManager());

    $state = $codec->encode(['principal_id' => 3]);
    [$body] = explode('.', $state);

    $tampered = $body . '.' . strrev(substr(strrchr($state, '.'), 1));

    expect(fn() => $codec->decode($tampered))
        ->toThrow(SocialException::class, 'signature does not match');
});

it('refuses a state signed with another key', function () {
    $other = new SocialStateCodec(new Spora\Core\SecurityManager(str_repeat('x', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    $codec = new SocialStateCodec(securityManager());

    $state = $other->encode(['principal_id' => 3]);

    expect(fn() => $codec->decode($state))
        ->toThrow(SocialException::class, 'signature does not match');
});

it('refuses a malformed state', function () {
    $codec = new SocialStateCodec(securityManager());

    expect(fn() => $codec->decode('not-a-state'))
        ->toThrow(SocialException::class, 'malformed');
});
