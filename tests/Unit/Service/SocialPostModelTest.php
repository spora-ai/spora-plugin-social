<?php

declare(strict_types=1);

use Spora\Plugins\Social\Http\SocialPostResource;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Service\PostInput;

it('strips the hashes, the blanks and the duplicates from the hashtags', function () {
    $fields = new PostInput([
        'hashtags' => ['#launch', ' shipit ', '', '#launch', 'shipit'],
    ]);

    expect($fields->hashtags())->toBe(['launch', 'shipit']);
});

it('has no hashtags when the post names none', function () {
    expect((new PostInput([]))->hashtags())->toBeNull()
        ->and((new PostInput(['hashtags' => []]))->hashtags())->toBeNull()
        ->and((new PostInput(['hashtags' => ['#']]))->hashtags())->toBeNull()
        ->and((new PostInput(['hashtags' => 'launch']))->hashtags())->toBeNull()
        ->and((new PostInput(['hashtags' => [42]]))->hashtags())->toBeNull();
});

it('names the channel a body belongs to, whatever the caller spelled it', function () {
    $fields = new PostInput([
        'platform_bodies' => [
            'LinkedIn' => 'The LinkedIn copy',
            ' FACEBOOK ' => 'The Facebook copy',
            'twitter' => 'Not a platform',
            'instagram' => 42,
        ],
    ]);

    expect($fields->platformBodies())->toBe([
        'direct:linkedin' => 'The LinkedIn copy',
        'direct:facebook' => 'The Facebook copy',
    ]);
});

it('keeps a body that names the route itself', function () {
    $fields = new PostInput([
        'platform_bodies' => [
            'direct:linkedin' => 'The LinkedIn copy',
            'zernio:bluesky' => 'The Bluesky copy',
            'Zernio:BlueSky' => 'The same copy again',
        ],
    ]);

    // The same channel twice is one body: the copy of the channel that
    // reaches the network through the aggregator.
    expect($fields->platformBodies())->toBe([
        'direct:linkedin' => 'The LinkedIn copy',
        'zernio:bluesky' => 'The same copy again',
    ]);
});

it('stores the hashtags on the post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->status = SocialPost::STATUS_DRAFT;
    $post->fill([
        'body'     => 'We shipped…',
        'hashtags' => ['launch', 'shipit'],
    ]);
    $post->save();

    expect($post->fresh()?->hashtags)->toBe(['launch', 'shipit']);
});

it('answers with the hashtags of the post', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->hashtags = ['launch', 'shipit'];
    $post->status = SocialPost::STATUS_DRAFT;
    $post->save();

    $payload = SocialPostResource::toArray($post->fresh() ?? $post);

    expect($payload['hashtags'])->toBe(['launch', 'shipit']);
});

it('answers with no hashtags when the post has none', function () {
    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_DRAFT;
    $post->save();

    $payload = SocialPostResource::toArray($post->fresh() ?? $post);

    expect($payload['hashtags'])->toBe([]);
});
