<?php

declare(strict_types=1);

use Spora\Plugins\Social\Tests\Support\SocialFixtures;
use Symfony\Component\HttpClient\Response\MockResponse;

it('records the outcome of every target separately', function () {
    $publisher = postPublisher([
        'linkedin' => [['body' => SocialFixtures::LINKEDIN_SHARE]],
        'facebook' => [['body' => '{"error":{"message":"Missing permission"}}', 'status' => 400]],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $post = $service->create($principalId, [
        'title'       => 'Q4 release',
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id, $facebook->id],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    expect($targets)->toHaveCount(2);

    $published = $targets[0];
    $failed = $targets[1];

    expect($published->status)->toBe('published')
        ->and($published->external_id)->toBe('urn:li:share:9')
        ->and($published->external_url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:9')
        ->and($published->error)->toBeNull()
        ->and($published->published_at)->not->toBeNull();

    expect($failed->status)->toBe('failed')
        ->and($failed->external_id)->toBeNull()
        ->and($failed->error)->toBe('Missing permission');

    expect($post->status)->toBe('partial');
});

it('derives published when every target published', function () {
    $publisher = postPublisher([
        'linkedin' => [['body' => '{"id":"urn:li:share:1"}']],
        'facebook' => [['body' => '{"id":"111_222"}']],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id, $facebook->id],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);

    expect($post->status)->toBe('published')
        ->and($post->published_at)->not->toBeNull();
});

it('derives failed when no target published', function () {
    $publisher = postPublisher([
        'linkedin' => [['body' => '{"message":"Invalid author"}', 'status' => 422]],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);

    expect($post->status)->toBe('failed')
        ->and($post->published_at)->toBeNull();
});

it('retries only the targets that failed', function () {
    $publisher = postPublisher([
        'linkedin' => [['body' => SocialFixtures::LINKEDIN_SHARE]],
        'facebook' => [['body' => '{"error":{"message":"Missing permission"}}', 'status' => 400]],
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $facebook = publishableAccount($principalId, 'facebook', 'page');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id, $facebook->id],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    $retried = $publisher->retry((int) $post->id, $principalId);

    $targets = $retried->targets()->orderBy('id')->get();

    expect($targets[0]->status)->toBe('published')
        ->and($targets[0]->external_id)->toBe('urn:li:share:9')
        ->and($targets[1]->status)->toBe('failed')
        ->and($retried->status)->toBe('partial');
});

it('uses the body of the platform when the post carries one', function () {
    $captured = null;

    $publisher = postPublisher([
        'linkedin' => function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true);

            return new MockResponse(SocialFixtures::LINKEDIN_SHARE);
        },
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'           => 'The general copy',
        'platform_bodies' => ['linkedin' => 'The LinkedIn copy'],
        'account_ids'    => [$linkedIn->id],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    // The Posts API takes the text as `commentary`.
    //
    // @see https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api
    expect($captured['commentary'])->toBe('The LinkedIn copy');
});

it('refuses to publish an instagram post without media', function () {
    $publisher = postPublisher(['instagram' => [['body' => '{}']]]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $instagram = publishableAccount($principalId, 'instagram', 'business');

    $post = $service->create($principalId, [
        'body'        => 'Just words',
        'account_ids' => [$instagram->id],
    ]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->targets()->first();

    expect($target?->status)->toBe('failed')
        ->and($target?->error)->toContain('without media');
});

it('refuses a post that names an account of another principal', function () {
    $service = postService();

    $ownerId = createUserPrincipal(bootAuth(bootAuthLayer()));
    $otherId = createUserPrincipal(bootAuth(bootAuthLayer(), 'other@example.test'));

    $foreign = createSocialAccount($otherId, 'linkedin', 'organization');

    expect(fn() => $service->create($ownerId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$foreign->id],
    ]))->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'was not found for this principal');
});

it('writes a draft without an account, because a draft goes nowhere', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = $service->create($principalId, ['body' => SocialFixtures::POST_BODY]);

    // The accounts are what name the platforms. A draft has not been sent
    // anywhere, so it may sit without one.
    expect($post->status)->toBe('draft')
        ->and($post->targets()->count())->toBe(0);
});

it('refuses a scheduled post without an account, and names the way out', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    // A scheduled post commits the copy to go out, so it has to know where
    // to — and the refusal says what to do about it.
    expect(fn() => $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]))->toThrow(
        Spora\Plugins\Social\Exception\SocialException::class,
        'No social account is connected for this principal. Connect one first',
    );
});

it('names the connected accounts when the post names none of them', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    publishableAccount($principalId, 'linkedin', 'organization');

    expect(fn() => $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]))->toThrow(
        Spora\Plugins\Social\Exception\SocialException::class,
        'A post needs at least one account to publish to.',
    );
});

it('names the accounts screen when the only accounts are aggregator accounts', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $connection = createSocialConnection($principalId, 'zernio');

    createSocialAccount($principalId, 'bluesky', 'aggregator', [
        'connection' => $connection,
        'via'        => 'zernio',
    ]);

    // There is no connect URL for an aggregator, so the way out is the
    // screen the operator added the key on.
    expect(fn() => $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]))->toThrow(
        Spora\Plugins\Social\Exception\SocialException::class,
        'Accounts screen',
    );
});

it('refuses to give a draft a date while it has no account', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = $service->create($principalId, ['body' => SocialFixtures::POST_BODY]);

    // The date is what sends it, and a post with no account has nowhere
    // to go.
    expect(fn() => $service->update((int) $post->id, $principalId, [
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]))->toThrow(
        Spora\Plugins\Social\Exception\SocialException::class,
        'No social account is connected for this principal.',
    );
});

it('takes the accounts off a draft', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]);

    $updated = $service->update((int) $post->id, $principalId, ['account_ids' => []]);

    expect($updated->targets()->count())->toBe(0);
});

it('refuses to publish a post that has no account', function () {
    $publisher = postPublisher();
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $post = $service->create($principalId, ['body' => SocialFixtures::POST_BODY]);

    expect(fn() => $publisher->publish((int) $post->id, $principalId))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'there is nothing to publish');
});

it('lists only the posts of the days asked for', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $service->create($principalId, [
        'body'          => 'This week',
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    $service->create($principalId, [
        'body'          => 'Next month',
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-12-02T09:00:00+00:00',
    ]);

    $window = $service->listForPrincipal($principalId, [
        'from' => '2026-11-01',
        'to'   => '2026-11-30',
    ]);

    $all = $service->listForPrincipal($principalId);

    expect($window['total'])->toBe(1)
        ->and($window['items'][0]->body)->toBe('This week')
        ->and($all['total'])->toBe(2);
});

it('reads a wall-clock time in the zone the post names', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'timezone'      => SocialFixtures::BERLIN,
        'scheduled_for' => '2026-11-02 09:00',
    ]);

    expect($post->timezone)->toBe(SocialFixtures::BERLIN)
        // 09:00 in Berlin is 08:00 UTC.
        ->and($post->scheduled_for?->toIso8601String())->toBe('2026-11-02T08:00:00+00:00');
});

it('keeps a time that carries its own offset where it is', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'timezone'      => SocialFixtures::BERLIN,
        'scheduled_for' => '2026-11-02T09:00:00+05:00',
    ]);

    // The offset says exactly when; the named zone does not move it.
    expect($post->scheduled_for?->toIso8601String())->toBe('2026-11-02T04:00:00+00:00');
});

it('refuses a time it cannot read', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    expect(fn() => $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => 'sometime next week',
    ]))->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'scheduled_for is not a valid date');
});

it('refuses a body over the platform limit', function () {
    $publisher = postPublisher(['linkedin' => [['body' => SocialFixtures::LINKEDIN_SHARE]]]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = postService()->create($principalId, [
        'body'        => str_repeat('a', 3001),
        'account_ids' => [$linkedIn->id],
    ]);

    $published = $publisher->publish((int) $post->id, $principalId);

    $target = $published->targets()->first();

    expect($target?->status)->toBe('failed')
        ->and($target?->error)->toContain('Over the limit')
        ->and($target?->error)->toContain('3000')
        ->and($target?->error)->toContain('LinkedIn');
});

it('judges every platform against its own body and its own limit', function () {
    $publisher = postPublisher([
        'linkedin'  => [['body' => SocialFixtures::LINKEDIN_SHARE]],
        'instagram' => [['body' => '{"id":"17900000000000002"}']],
    ]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');
    $instagram = publishableAccount($principalId, 'instagram', 'business');

    $post = postService()->create($principalId, [
        // One body for both, but it is too long for Instagram and fine for
        // LinkedIn: each target is judged on its own limit.
        'body'            => str_repeat('a', 2500),
        'platform_bodies' => ['instagram' => str_repeat('b', 2201)],
        'account_ids'     => [$linkedIn->id, $instagram->id],
    ]);

    $published = $publisher->publish((int) $post->id, $principalId);

    $targets = $published->targets()->orderBy('id')->get();

    expect($targets[0]->status)->toBe('published')
        ->and($targets[1]->status)->toBe('failed')
        ->and($targets[1]->error)->toContain('2200')
        ->and($targets[1]->error)->toContain('Instagram');
});

it('replaces the accounts and the attachments of a post', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $first = publishableAccount($principalId, 'linkedin', 'organization');
    $second = publishableAccount($principalId, 'facebook', 'page');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$first->id],
    ]);

    $updated = $service->update((int) $post->id, $principalId, [
        'account_ids' => [$second->id],
        'media'       => [['media_asset_id' => $asset->id]],
    ]);

    expect($updated->targets()->get())->toHaveCount(1)
        ->and($updated->targets()->first()?->account_id)->toBe($second->id)
        ->and($updated->media()->get())->toHaveCount(1);
});

it('stores the hashtags the caller named', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'hashtags'    => ['#launch', 'shipit'],
        'account_ids' => [$account->id],
    ]);

    expect($post->hashtags)->toBe(['launch', 'shipit']);
});

it('sends the body and the hashtags to the network', function () {
    $captured = null;

    $publisher = postPublisher([
        'linkedin' => function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true);

            return new MockResponse(SocialFixtures::LINKEDIN_SHARE);
        },
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => 'The general copy',
        'hashtags'    => ['launch', 'shipit'],
        'account_ids' => [$linkedIn->id],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    expect($captured['commentary'])->toBe("The general copy\n\n#launch #shipit");
});

it('counts the hashtags against the character limit', function () {
    $publisher = postPublisher(['linkedin' => [['body' => SocialFixtures::LINKEDIN_SHARE]]]);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = postService()->create($principalId, [
        // The body alone fits LinkedIn; the hashtags do not fit with it.
        'body'        => str_repeat('a', 2995),
        'hashtags'    => ['launch'],
        'account_ids' => [$linkedIn->id],
    ]);

    $published = $publisher->publish((int) $post->id, $principalId);

    $target = $published->targets()->first();

    expect($target?->status)->toBe('failed')
        ->and($target?->error)->toContain('Over the limit')
        ->and($target?->error)->toContain('3000');
});

it('gives a platform the body written for it, whatever the key was spelled', function () {
    $captured = null;

    $publisher = postPublisher([
        'linkedin' => function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true);

            return new MockResponse(SocialFixtures::LINKEDIN_SHARE);
        },
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'           => 'The general copy',
        'platform_bodies' => ['LinkedIn' => 'The LinkedIn copy'],
        'account_ids'    => [$linkedIn->id],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    expect($captured['commentary'])->toBe('The LinkedIn copy');
});

it('keeps a body written for a network the plugin reaches through the aggregator', function () {
    $captured = null;

    $publisher = postPublisher([
        'linkedin' => function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) $options['body'], true);

            return new MockResponse(SocialFixtures::LINKEDIN_SHARE);
        },
    ]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'           => 'The general copy',
        'platform_bodies' => ['twitter' => 'The Twitter copy'],
        'account_ids'    => [$linkedIn->id],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    // A bare name is the route the plugin has for the network, and the
    // plugin reaches Twitter through the aggregator.
    expect($post->platform_bodies)->toBe(['zernio:twitter' => 'The Twitter copy'])
        ->and($captured['commentary'])->toBe('The general copy');
});

it('refuses a body written for a network the plugin does not publish to', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    // A body nobody would publish is a body the operator did not mean,
    // and the shared body would go out in its place.
    expect(fn() => $service->create($principalId, [
        'body'           => 'The general copy',
        'platform_bodies' => ['myspace' => 'Not a network'],
        'account_ids'    => [$linkedIn->id],
    ]))->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'names no network the plugin publishes to');
});

it('refuses a post that says nothing and carries nothing', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    expect(fn() => $service->create($principalId, [
        'account_ids' => [$account->id],
    ]))->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'A post needs a message or media.');
});

it('accepts a post that only has a body for one platform', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'platform_bodies' => ['linkedin' => 'Only LinkedIn'],
        'account_ids'     => [$account->id],
    ]);

    expect($post->platform_bodies)->toBe(['direct:linkedin' => 'Only LinkedIn']);
});

it('accepts a post that carries media and no message', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'instagram', 'business');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $post = $service->create($principalId, [
        'account_ids' => [$account->id],
        'media'       => [['media_asset_id' => $asset->id]],
    ]);

    expect($post->media()->get())->toHaveCount(1);
});

it('judges an edit on the post it leaves behind', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]);

    expect(fn() => $service->update((int) $post->id, $principalId, ['body' => '']))
        ->toThrow(Spora\Plugins\Social\Exception\SocialException::class, 'A post needs a message or media.');
});

it('keeps an edit that leaves the post with media alone', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'instagram', 'business');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/jpeg', 'payload' => jpegBytes()]);

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
        'media'       => [['media_asset_id' => $asset->id]],
    ]);

    $updated = $service->update((int) $post->id, $principalId, ['body' => '']);

    expect($updated->body)->toBeNull()
        ->and($updated->media()->get())->toHaveCount(1);
});

it('schedules a draft when an edit gives it a date', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$account->id],
    ]);

    expect($post->status)->toBe('draft');

    $updated = $service->update((int) $post->id, $principalId, [
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    expect($updated->status)->toBe('scheduled')
        ->and($updated->scheduled_for?->toIso8601String())->toBe('2026-11-02T09:00:00+00:00');
});

it('turns a scheduled post back into a draft when the date is cleared', function () {
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'          => SocialFixtures::POST_BODY,
        'account_ids'   => [$account->id],
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    expect($post->status)->toBe('scheduled');

    $updated = $service->update((int) $post->id, $principalId, ['scheduled_for' => null]);

    expect($updated->status)->toBe('draft')
        ->and($updated->scheduled_for)->toBeNull();
});

it('leaves a published post its status when it is edited', function () {
    $publisher = postPublisher(['linkedin' => [['body' => SocialFixtures::LINKEDIN_SHARE]]]);
    $service = postService();

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $linkedIn = publishableAccount($principalId, 'linkedin', 'organization');

    $post = $service->create($principalId, [
        'body'        => SocialFixtures::POST_BODY,
        'account_ids' => [$linkedIn->id],
    ]);

    $published = $publisher->publish((int) $post->id, $principalId);

    expect($published->status)->toBe('published');

    $updated = $service->update((int) $post->id, $principalId, [
        'body'          => 'A different copy',
        'scheduled_for' => '2026-11-02T09:00:00+00:00',
    ]);

    expect($updated->status)->toBe('published');
});
