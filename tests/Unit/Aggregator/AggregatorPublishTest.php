<?php

declare(strict_types=1);

use Spora\Plugins\Social\Model\SocialAccount;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\Tests\Support\ZernioRecorder;

/**
 * A post that goes to the given accounts.
 *
 * @param list<SocialAccount> $accounts
 */
function aPostTo(int $principalId, array $accounts): SocialPost
{
    return postService()->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => array_map(static fn(SocialAccount $a): int => (int) $a->id, $accounts),
    ]);
}

/**
 * The requests the recorder saw.
 *
 * @return list<array{method: string, url: string, options: array<string, mixed>}>
 */
function zernioRequests(ZernioRecorder $recorder): array
{
    return $recorder->requests;
}

it('publishes a zernio target and lands it published', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->targets()->first();

    expect($post->status)->toBe('published')
        ->and($target?->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        // The target's id is Zernio's post id — that is what the
        // analytics lookup needs.
        ->and($target?->external_id)->toBe('id_001')
        // And its url is that network's own post, so the operator can
        // click it.
        ->and($target?->external_url)->toBe('https://bsky.app/redacted');
});

it('publishes two networks in one call, and writes both rows', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post-cross-post'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $bluesky = zernioAccount($principalId, 'bluesky', 'id_002');
    $twitter = zernioAccount($principalId, 'twitter', 'id_003');

    $post = aPostTo($principalId, [$bluesky, $twitter]);

    $post = $publisher->publish((int) $post->id, $principalId);

    // One cross-post in the aggregator, not a post per network.
    $posts = array_values(array_filter(
        zernioRequests($recorder),
        static fn(array $request): bool => str_ends_with($request['url'], '/posts'),
    ));

    expect($posts)->toHaveCount(1);

    $body = (array) json_decode((string) $posts[0]['options']['body'], true);

    expect($body['platforms'])->toHaveCount(2)
        ->and($body['platforms'][0]['platform'])->toBe('bluesky')
        ->and($body['platforms'][1]['platform'])->toBe('twitter')
        ->and($body['publishNow'])->toBeTrue();

    $targets = $post->targets()->orderBy('id')->get();

    expect($targets)->toHaveCount(2)
        ->and($targets[0]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($post->status)->toBe('published');
});

it('maps each result of one network to its own row', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post-two-accounts'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $first = zernioAccount($principalId, 'bluesky', 'id_002');
    $second = zernioAccount($principalId, 'bluesky', 'id_004');

    $post = aPostTo($principalId, [$first, $second]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    expect($targets)->toHaveCount(2)
        ->and($targets[0]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED);
});

it('writes the key to the target rows before the call', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $publisher->publish((int) $post->id, $principalId);

    $target = $post->fresh()?->targets()->first();

    $posts = array_values(array_filter(
        zernioRequests($recorder),
        static fn(array $request): bool => str_ends_with($request['url'], '/posts'),
    ));

    $headers = [];

    foreach ($posts[0]['options']['headers'] ?? [] as $header) {
        [$name, $value] = explode(': ', (string) $header, 2);
        $headers[strtolower($name)] = $value;
    }

    // The key is written down before the call, so a publish that dies
    // here leaves a key behind that the replay uses again.
    expect($target?->idempotency_key)->not->toBeNull()
        ->and($headers['idempotency-key'])->toBe($target?->idempotency_key);
});

it('leaves the targets publishing when the call never landed', function () {
    // A call that never reached Zernio: the publish may still have
    // happened, so the target must not claim it failed.
    $dead = new ZernioRecorder([
        ['body' => '', 'transport' => true],
    ]);

    $publisher = postPublisher([], $dead);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->fresh()?->targets()->first();

    expect($target?->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($target?->idempotency_key)->not->toBeNull()
        ->and($target?->error)->toBeNull();

    // The post row says the same thing: `failed` means the aggregator
    // answered and refused, and it did not answer at all.
    expect($post->status)->toBe(SocialPost::STATUS_PUBLISHING);
});

it('does not publish a target that is still publishing under its own key', function () {
    // The first call never lands, so the target keeps the key it went
    // out under and the reconciliation owns it.
    $recorder = new ZernioRecorder([
        ['body' => '', 'transport' => true],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $publisher->publish((int) $post->id, $principalId);

    $target = $post->fresh()?->targets()->first();

    expect($target?->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($target?->idempotency_key)->not->toBeNull();

    // The operator presses "Publish now" again.
    $publisher->publish((int) $post->id, $principalId);

    $posts = array_values(array_filter(
        zernioRequests($recorder),
        static fn(array $request): bool => str_ends_with($request['url'], '/posts'),
    ));

    // One cross-post, not two: a fresh key would be a fresh post.
    expect($posts)->toHaveCount(1);
});

it('does not give two accounts of one network the same result', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post-one-of-two-accounts'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $first = zernioAccount($principalId, 'bluesky', 'id_002');
    $second = zernioAccount($principalId, 'bluesky', 'id_004');

    $post = aPostTo($principalId, [$first, $second]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    // The answer names one Bluesky entry and no account, so it does not
    // say which of the two accounts it is about. Neither target may take
    // it: a wrong url and a wrong status are worse than none.
    expect($targets[0]->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_PUBLISHING)
        ->and($targets[0]->external_url)->toBeNull()
        ->and($targets[1]->external_url)->toBeNull();
});

it('fails a target whose channel the plugin has no rules for, and publishes the others', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $bluesky = zernioAccount($principalId, 'bluesky', 'id_002');
    // A network the aggregator reports and the plugin has no rules for.
    $snapchat = zernioAccount($principalId, 'snapchat', 'id_005');

    $post = aPostTo($principalId, [$bluesky, $snapchat]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    // The one without rules fails on its own, and the other still goes out.
    expect($targets[0]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($targets[1]->error)->toContain('zernio:snapchat');

    $posts = array_values(array_filter(
        zernioRequests($recorder),
        static fn(array $request): bool => str_ends_with($request['url'], '/posts'),
    ));

    expect($posts)->toHaveCount(1);
});

it('fails the duplicate account alone, and publishes the rest of the group', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('duplicate-post'), 'status' => 409],
        ['body' => zernioFixture('create-post-twitter'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $bluesky = zernioAccount($principalId, 'bluesky', 'id_002');
    $twitter = zernioAccount($principalId, 'twitter', 'id_003');

    $post = aPostTo($principalId, [$bluesky, $twitter]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    // The duplicate is the aggregator's own message, on the account it
    // is about — and the other network is not taken down with it.
    expect($targets[0]->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($targets[0]->error)->toContain('already scheduled, publishing')
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->external_url)->toBe('https://twitter.com/redacted');

    // And the group went out without the duplicate: two calls, the
    // second one carrying the account that was not a duplicate.
    $posts = array_values(array_filter(
        zernioRequests($recorder),
        static fn(array $request): bool => str_ends_with($request['url'], '/posts'),
    ));

    expect($posts)->toHaveCount(2);

    $body = (array) json_decode((string) $posts[1]['options']['body'], true);

    expect($body['platforms'])->toHaveCount(1)
        ->and($body['platforms'][0]['platform'])->toBe('twitter');
});

it('fails a cancelled target, because it will never publish', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post-cancelled'), 'status' => 207],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $target = $post->targets()->first();

    // A cancelled target is not pending: the aggregator has taken it off
    // the platform, and a pending target would be replayed every cycle.
    expect($target?->status)->toBe(SocialPostTarget::STATUS_FAILED)
        ->and($target?->error)->toContain('taken down on the platform');
});

it('writes partial on the post and failed on the network that was refused', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post-partial'), 'status' => 207],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $bluesky = zernioAccount($principalId, 'bluesky', 'id_002');
    $twitter = zernioAccount($principalId, 'twitter', 'id_003');

    $post = aPostTo($principalId, [$bluesky, $twitter]);

    $post = $publisher->publish((int) $post->id, $principalId);

    $targets = $post->targets()->orderBy('id')->get();

    expect($post->status)->toBe('partial')
        ->and($targets[0]->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($targets[1]->status)->toBe(SocialPostTarget::STATUS_FAILED)
        // The refusal is Zernio's own message.
        ->and($targets[1]->error)->toContain('Tweet text is too long');
});

it('uploads the original bytes and sends the url the aggregator answers with', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('media-presign')],
        ['body' => '', 'status' => 200],
        ['body' => zernioFixture('create-post'), 'status' => 201],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $asset = createMediaAsset($principalId, ['mime_type' => 'image/png', 'payload' => pngBytes()]);

    $post = postService()->create($principalId, [
        'body'        => 'We shipped…',
        'account_ids' => [$account->id],
        'media'       => [['media_asset_id' => $asset->id]],
    ]);

    $publisher->publish((int) $post->id, $principalId);

    $requests = zernioRequests($recorder);

    // The presign, the bytes, the post.
    expect($requests)->toHaveCount(3)
        ->and($requests[0]['url'])->toBe('https://zernio.com/api/v1/media/presign')
        ->and($requests[1]['method'])->toBe('PUT')
        ->and($requests[1]['url'])->toBe('https://storage.zernio.com/redacted')
        ->and($requests[2]['url'])->toBe('https://zernio.com/api/v1/posts');

    // The original bytes, not a derivative: the aggregator takes PNG
    // as it is and compresses above a network's limit itself.
    expect($requests[1]['options']['body'])->toBe(pngBytes());

    $body = (array) json_decode((string) $requests[2]['options']['body'], true);

    expect($body['mediaItems'][0]['url'])->toBe('https://media.zernio.com/redacted')
        ->and($body['mediaItems'][0]['type'])->toBe('image');
});

it('takes the post down with the account id, so the aggregator never has to guess', function () {
    $recorder = new ZernioRecorder([
        ['body' => zernioFixture('create-post'), 'status' => 201],
        ['body' => '{"success":true,"message":"Post deleted from bluesky successfully"}', 'status' => 200],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $publisher->publish((int) $post->id, $principalId);

    $post = $publisher->delete((int) $post->id, $principalId);

    $requests = zernioRequests($recorder);
    $unpublish = $requests[count($requests) - 1];

    $body = (array) json_decode((string) $unpublish['options']['body'], true);

    expect($unpublish['url'])->toBe('https://zernio.com/api/v1/posts/id_001/unpublish')
        ->and($body['platform'])->toBe('bluesky')
        // The endpoint answers `409 ambiguous_account` when a post
        // reached several accounts of one network and no account was
        // named, and the operator must never see that.
        ->and($body['accountId'])->toBe('id_002')
        ->and($post->targets()->first()?->status)->toBe(SocialPostTarget::STATUS_DELETED);
});

it('replays a crashed publish with the key it went out under, and publishes nothing twice', function () {
    // The first call never lands, so the target keeps its key.
    $recorder = new ZernioRecorder([
        ['body' => '', 'transport' => true],
        ['body' => zernioFixture('create-post'), 'status' => 200],
    ]);

    $publisher = postPublisher([], $recorder);

    $principalId = createUserPrincipal(bootAuth(bootAuthLayer()));

    $account = zernioAccount($principalId, 'bluesky', 'id_002');

    $post = aPostTo($principalId, [$account]);

    $publisher->publish((int) $post->id, $principalId);

    $target = $post->fresh()?->targets()->first();

    expect($target?->status)->toBe(SocialPostTarget::STATUS_PUBLISHING);

    $key = (string) $target?->idempotency_key;

    // The reconciliation replays the publish with that key, so the
    // aggregator answers with the original post instead of publishing a
    // second one.
    $reconciliation = new Spora\Plugins\Social\Aggregator\AggregatorReconciliation(
        new Spora\Plugins\Social\Aggregator\AggregatorPublisher(
            aggregatorService($recorder->client()),
            zernioClient($recorder->client()),
        ),
        new Spora\Plugins\Social\Service\PostMedia(mediaPipeline()),
    );

    expect($reconciliation->resolve($target))->toBeTrue();

    $requests = zernioRequests($recorder);

    expect($requests)->toHaveCount(2);

    $keys = [];

    foreach ($requests as $request) {
        foreach ($request['options']['headers'] ?? [] as $header) {
            [$name, $value] = explode(': ', (string) $header, 2);

            if (strtolower($name) === 'idempotency-key') {
                $keys[] = $value;
            }
        }
    }

    expect($keys)->toHaveCount(2)
        ->and($keys[0])->toBe($key)
        ->and($keys[1])->toBe($key)
        ->and($target?->fresh()?->status)->toBe(SocialPostTarget::STATUS_PUBLISHED)
        ->and($target?->fresh()?->external_id)->toBe('id_001');
});
