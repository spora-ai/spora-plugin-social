<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Carbon;
use Spora\Auth\AuthService;
use Spora\Core\SecurityManager;
use Spora\Plugins\Social\Model\SocialPost;
use Spora\Plugins\Social\Model\SocialPostTarget;
use Spora\Plugins\Social\OAuth\OAuthProviderResolver;
use Spora\Plugins\Social\OAuth\SocialStateCodec;
use Spora\Plugins\Social\Publish\FacebookAdapter;
use Spora\Plugins\Social\Publish\InstagramAdapter;
use Spora\Plugins\Social\Publish\LinkedInAdapter;
use Spora\Plugins\Social\Publish\PlatformApi;
use Spora\Plugins\Social\Publish\PlatformHttpClient;
use Spora\Plugins\Social\Publish\PublisherRegistry;
use Spora\Plugins\Social\Service\MediaPipeline;
use Spora\Plugins\Social\Service\SocialAppUrlProbe;
use Spora\Plugins\Social\Service\SocialConnectionService;
use Spora\Plugins\Social\Service\SocialMetricsService;
use Spora\Plugins\Social\Service\SocialPostService;
use Spora\Plugins\Social\Service\SocialPublisher;
use Spora\Plugins\Social\Service\SocialSettings;
use Spora\Plugins\Social\Tests\Support\FakeProviderResolver;
use Spora\Services\PrincipalContext;
use Spora\Services\PrincipalResolver;
use Spora\Services\PrincipalService;
use Spora\Tools\Attributes\ToolOperation;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;

/*
|--------------------------------------------------------------------------
| Pest Bootstrap
|--------------------------------------------------------------------------
|
| Duplicates core's `bootAuth` / `jsonRequest` / `simulateLoggedInSession` so
| this suite is self-contained against the symlinked spora-core.
*/

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

require_once BASE_PATH . '/vendor/autoload.php';

// The core extension points the plugin implements are not in the core
// release the plugin resolves against yet; the shim declares them so the
// suite can run. See compat/core-extension-points.php.
require_once BASE_PATH . '/compat/core-extension-points.php';

// delight-im/auth v9 uses implicit nullable parameter types, and PHP attributes
// the deprecation to the CALLING file — so the vendor path alone cannot filter
// it. The plugin is strict_types throughout, so matching the message is safe.
//
// Installed per test: the test framework replaces the error handler between
// tests, so a handler set at bootstrap time is gone by the time the suite runs.
function ignoreVendorDeprecations(): void
{
    set_error_handler(static function (int $errno, string $errstr, string $errfile): bool {
        if ($errno !== E_DEPRECATED) {
            return false;
        }

        return str_contains($errfile, DIRECTORY_SEPARATOR . 'delight-im' . DIRECTORY_SEPARATOR)
            || str_contains($errstr, 'Implicitly marking parameter');
    }, E_DEPRECATED);
}

function bootAuthLayer(): AuthService
{
    $pdo = Capsule::connection()->getPdo();
    $auth = new Delight\Auth\Auth($pdo, null, null, false);

    return new AuthService($auth);
}

function jsonRequest(string $method, string $uri, array $body = []): Request
{
    return Request::create(
        $uri,
        strtoupper($method),
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/json'],
        $body !== [] ? json_encode($body) : '',
    );
}

function simulateLoggedInSession(int $userId, string $email): void
{
    if (!isset($_SESSION)) {
        $_SESSION = [];
    }
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_LOGGED_IN] = true;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USER_ID] = $userId;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_EMAIL] = $email;
    $_SESSION[Delight\Auth\Auth::SESSION_FIELD_USERNAME] = null;
}

function clearSession(): void
{
    $_SESSION = [];
}

function bootAuth(AuthService $authService, string $email = 'test@example.com', string $password = 'Password1!', string $displayName = 'Test User'): int
{
    $userId = $authService->register($email, $password, $displayName);
    simulateLoggedInSession($userId, $email);

    return $userId;
}

function createUserPrincipal(int $userId): int
{
    return (int) new PrincipalService(new PrincipalResolver())->ensureUserPrincipal($userId)->id;
}

function createAgentForPrincipal(int $principalId, string $name = 'Test Agent'): int
{
    return (int) Capsule::table('agents')->insertGetId([
        'principal_id' => $principalId,
        'name'         => $name,
        'max_steps'    => 5,
        'is_active'    => true,
        'created_at'   => date('Y-m-d H:i:s'),
        'updated_at'   => date('Y-m-d H:i:s'),
    ]);
}

/**
 * A security manager on a throwaway key. The value is irrelevant; the
 * genuine encrypt/decrypt round-trip is not.
 */
function securityManager(): SecurityManager
{
    return new SecurityManager(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
}

/**
 * A config carrying the plugin's settings.
 *
 * @param array<string, mixed> $values
 */
function socialConfig(array $values = []): Spora\Core\Config
{
    return new Spora\Core\Config($values + [
        SocialSettings::KEY_LINKEDIN_CLIENT_ID => 'linkedin-id',
        SocialSettings::KEY_LINKEDIN_CLIENT_SECRET => 'linkedin-secret',
        SocialSettings::KEY_META_APP_ID => 'meta-app-id',
        SocialSettings::KEY_META_APP_SECRET => 'meta-app-secret',
        SocialSettings::KEY_META_CONFIG_ID => 'meta-config-id',
        'SPORA_APP_URL' => 'https://spora.test',
    ]);
}

/**
 * A connection: one login, one token, many accounts.
 *
 * @param array<string, mixed> $overrides
 */
function createSocialConnection(int $principalId, string $provider = 'linkedin', array $overrides = []): Spora\Plugins\Social\Model\SocialConnection
{
    $security = securityManager();

    $connection = new Spora\Plugins\Social\Model\SocialConnection();
    $connection->principal_id = $principalId;
    $connection->provider = $provider;
    $connection->business_external_id = $overrides['business_external_id'] ?? null;
    $connection->config_id = $overrides['config_id'] ?? null;
    $connection->access_token = $security->encrypt((string) ($overrides['access_token'] ?? 'access-token'))->toStorageString();
    $connection->refresh_token = isset($overrides['refresh_token'])
        ? $security->encrypt((string) $overrides['refresh_token'])->toStorageString()
        : null;
    $connection->expires_at = $overrides['expires_at'] ?? null;
    $connection->status = $overrides['status'] ?? Spora\Plugins\Social\Model\SocialConnection::STATUS_ACTIVE;
    $connection->scopes = $overrides['scopes'] ?? null;
    $connection->save();

    return $connection;
}

/**
 * A connected account, hanging off a connection.
 *
 * @param array<string, mixed> $overrides
 */
function createSocialAccount(
    int $principalId,
    string $platform = 'linkedin',
    string $kind = 'organization',
    array $overrides = [],
): Spora\Plugins\Social\Model\SocialAccount {
    $connection = $overrides['connection'] ?? createSocialConnection($principalId, 'linkedin');

    $account = new Spora\Plugins\Social\Model\SocialAccount();
    $account->principal_id = $principalId;
    $account->connection_id = $connection->id;
    $account->platform = $platform;
    $account->kind = $kind;
    $account->external_id = $overrides['external_id'] ?? ('urn:li:organization:' . $principalId);
    $account->display_name = $overrides['display_name'] ?? 'Fabeat GmbH';
    $account->username = $overrides['username'] ?? 'fabeat';
    $account->status = $overrides['status'] ?? Spora\Plugins\Social\Model\SocialAccount::STATUS_ACTIVE;
    $account->followers = $overrides['followers'] ?? null;
    $account->connected_at = $overrides['connected_at'] ?? new DateTimeImmutable('now');
    $account->save();

    return $account;
}

/**
 * A version-4 UUID.
 *
 * `Illuminate\Support\Str::uuid()` needs `ramsey/uuid`, which the host
 * does not require — core mints its own the same way.
 */
function uuid4(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
}

/**
 * A PNG of a solid colour, small enough to keep in a row.
 */
function pngBytes(): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 30, 30));

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

/**
 * A JPEG of a solid colour, small enough to keep in a row.
 *
 * Instagram takes no PNG, so the Instagram path needs one of these.
 */
function jpegBytes(): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 30, 90, 200));

    ob_start();
    imagejpeg($image);
    $bytes = (string) ob_get_clean();

    return $bytes;
}

/**
 * The real core derivative service, with core's own image producer
 * registered — the registry is populated by the host's container, which
 * this suite does not boot.
 */
function derivativeService(): Spora\Services\MediaArchive\MediaDerivativeService
{
    Spora\Services\MediaArchive\MediaDerivativeProducerDiscovery::add(
        Spora\Services\MediaArchive\Producers\ImageDerivativeProducer::class,
    );

    $container = new class implements Psr\Container\ContainerInterface {
        public function get(string $id): mixed
        {
            return new Spora\Services\MediaArchive\Producers\ImageDerivativeProducer();
        }

        public function has(string $id): bool
        {
            return true;
        }
    };

    return new Spora\Services\MediaArchive\MediaDerivativeService(
        new Spora\Services\DatabaseAssetStore(),
        new PrincipalService(new PrincipalResolver()),
        $container,
    );
}

function connectionService(
    ?OAuthProviderResolver $resolver = null,
    ?string $appUrl = 'https://spora.test',
): SocialConnectionService {
    $probe = new SocialAppUrlProbe(new MockHttpClient([
        new MockResponse('', ['http_code' => 200]),
    ]));

    return new SocialConnectionService(
        new SocialSettings(socialConfig($appUrl === null ? ['SPORA_APP_URL' => ''] : ['SPORA_APP_URL' => $appUrl])),
        $resolver ?? new FakeProviderResolver([]),
        new SocialStateCodec(securityManager()),
        securityManager(),
        $probe,
    );
}

/**
 * The form fields of a request body.
 *
 * @param array<string, mixed> $request
 * @return array<string, string>
 */
function formOf(array $request): array
{
    parse_str((string) ($request['body'] ?? ''), $fields);

    /** @var array<string, string> $fields */
    return $fields;
}

/**
 * The operations a tool declares, by name.
 *
 * @param class-string $class
 * @return array<string, ToolOperation>
 */
function operationsOf(string $class): array
{
    $operations = [];

    foreach ((new ReflectionClass($class))->getAttributes(ToolOperation::class) as $attribute) {
        $operation = $attribute->newInstance();
        $operations[$operation->name] = $operation;
    }

    return $operations;
}

/**
 * The agent template the plugin ships.
 *
 * @return array<string, mixed>
 */
function socialAgentTemplate(): array
{
    $raw = file_get_contents(BASE_PATH . '/agent-templates/social-poster.json');

    return (array) json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The skill the plugin ships, split into frontmatter and body.
 *
 * @return array{frontmatter: array<string, mixed>, body: string}
 */
function socialSkill(): array
{
    $raw = (string) file_get_contents(BASE_PATH . '/skills/social-poster/SKILL.md');

    $parts = preg_split('/^---\s*$/m', $raw, 3);
    if ($parts === false || count($parts) < 3) {
        return ['frontmatter' => [], 'body' => ''];
    }

    $frontmatter = Symfony\Component\Yaml\Yaml::parse($parts[1]) ?? [];

    return ['frontmatter' => (array) $frontmatter, 'body' => $parts[2]];
}

/**
 * The description a tool's attribute carries — what the model reads.
 */
function toolDescriptionOf(string $class): string
{
    $attributes = (new ReflectionClass($class))->getAttributes(Spora\Tools\Attributes\Tool::class);

    if ($attributes === []) {
        return '';
    }

    return $attributes[0]->newInstance()->description;
}

/**
 * The headers the request carried, as a map.
 *
 * @param array<string, mixed> $options
 * @return array<string, string>
 */
function sentHeaders(array $options): array
{
    $headers = [];

    foreach ($options['headers'] ?? [] as $header) {
        [$name, $value] = explode(': ', (string) $header, 2);
        $headers[strtolower($name)] = $value;
    }

    return $headers;
}

/**
 * @param array<string, mixed> $options
 * @return array<string, mixed>
 */
function sentJson(array $options): array
{
    return (array) json_decode((string) ($options['body'] ?? ''), true);
}

/**
 * The API versions the contract tests speak.
 */
function apiVersions(): Spora\Plugins\Social\Service\ApiVersions
{
    return new Spora\Plugins\Social\Service\ApiVersions(
        Spora\Plugins\Social\Service\ApiVersions::DEFAULT_GRAPH,
        Spora\Plugins\Social\Service\ApiVersions::DEFAULT_LINKEDIN,
    );
}

/**
 * The client and the versions together, as the adapters take them.
 */
function platformApi(
    Symfony\Contracts\HttpClient\HttpClientInterface $client,
): PlatformApi {
    return new PlatformApi(
        new PlatformHttpClient($client),
        apiVersions(),
    );
}

/**
 * The principal an agent acts for.
 */
function principalContext(int $principalId): PrincipalContext
{
    return new PrincipalContext($principalId, 'user', 1, 1);
}

/**
 * The service graph the post service is exercised against.
 *
 * Each platform gets its own HTTP client, so a test can make one network
 * answer and the other refuse.
 *
 * @param array<string, list<array{body: string, status?: int}>|Closure> $answers
 */
function postPublisher(array $answers = []): SocialPublisher
{
    $client = static function (string $platform) use ($answers): PlatformHttpClient {
        $handler = $answers[$platform] ?? null;

        if ($handler instanceof Closure) {
            return new PlatformHttpClient(new MockHttpClient($handler));
        }

        $responses = array_map(
            static fn(array $response): MockResponse => new MockResponse(
                $response['body'],
                ['http_code' => $response['status'] ?? 200],
            ),
            $handler ?? [['body' => '{}']],
        );

        return new PlatformHttpClient(new MockHttpClient($responses));
    };

    $connections = new SocialConnectionService(
        new SocialSettings(socialConfig()),
        new FakeProviderResolver([]),
        new SocialStateCodec(securityManager()),
        securityManager(),
        new SocialAppUrlProbe(new MockHttpClient([new MockResponse('', ['http_code' => 200])])),
    );

    $paths = new Spora\Core\Paths(BASE_PATH);

    $media = new MediaPipeline(
        derivativeService(),
        new Spora\Services\MediaArchive\MediaAssetReader(
            new Spora\Services\DatabaseAssetStore(),
            new Spora\Services\LocalAssetStore($paths, securityManager()),
        ),
        new SocialSettings(socialConfig()),
    );

    $posts = new SocialPostService($connections);

    return new SocialPublisher(
        $posts,
        $connections,
        $media,
        new PublisherRegistry(
            new LinkedInAdapter(new PlatformApi($client('linkedin'), apiVersions())),
            new FacebookAdapter(new PlatformApi($client('facebook'), apiVersions())),
            new InstagramAdapter(new PlatformApi($client('instagram'), apiVersions())),
        ),
    );
}

/**
 * The CRUD half of the post service, against the same collaborators the
 * publisher uses.
 */
function postService(): SocialPostService
{
    return new SocialPostService(connectionService());
}

/**
 * A connected account with a token that does not need renewing.
 *
 * The token lives on the connection, so the account and the connection are
 * made together.
 */
function publishableAccount(int $principalId, string $platform, string $kind): Spora\Plugins\Social\Model\SocialAccount
{
    $provider = $platform === 'linkedin' ? 'linkedin' : 'meta';

    $connection = createSocialConnection($principalId, $provider, [
        'access_token' => $platform . '-token',
        'expires_at'   => new DateTimeImmutable('+50 days'),
    ]);

    return createSocialAccount($principalId, $platform, $kind, ['connection' => $connection]);
}

/**
 * @param list<array{body: string, status?: int}> $responses
 */
function metricsService(array $responses): SocialMetricsService
{
    $http = new MockHttpClient(array_map(
        static fn(array $response): MockResponse => new MockResponse(
            $response['body'],
            ['http_code' => $response['status'] ?? 200],
        ),
        $responses,
    ));

    return new SocialMetricsService(new PlatformHttpClient($http), connectionService(), apiVersions());
}

/**
 * A published target on a post.
 */
function publishedTarget(int $principalId, string $platform, string $kind, string $externalId): SocialPostTarget
{
    $account = publishableAccount($principalId, $platform, $kind);

    $post = new SocialPost();
    $post->principal_id = $principalId;
    $post->body = 'We shipped…';
    $post->status = SocialPost::STATUS_PUBLISHED;
    $post->save();

    $target = new SocialPostTarget();
    $target->post_id = $post->id;
    $target->account_id = $account->id;
    $target->platform = $platform;
    $target->status = SocialPostTarget::STATUS_PUBLISHED;
    $target->external_id = $externalId;
    $target->published_at = Carbon::now()->subHour();
    $target->save();

    return $target;
}

/**
 * The plugin's media pipeline, wired to core's real services.
 */
function mediaPipeline(): MediaPipeline
{
    $paths = new Spora\Core\Paths(BASE_PATH);

    return new MediaPipeline(
        derivativeService(),
        new Spora\Services\MediaArchive\MediaAssetReader(
            new Spora\Services\DatabaseAssetStore(),
            new Spora\Services\LocalAssetStore($paths, securityManager()),
        ),
        new SocialSettings(socialConfig()),
    );
}

/**
 * A Media Archive asset owned by the principal.
 *
 * @param array<string, mixed> $overrides
 */
function createMediaAsset(int $principalId, array $overrides = []): Spora\Models\MediaAsset
{
    $asset = new Spora\Models\MediaAsset();
    $asset->id = $overrides['id'] ?? uuid4();
    $asset->principal_id = $principalId;
    $asset->mime_type = $overrides['mime_type'] ?? 'image/png';
    $asset->byte_size = $overrides['byte_size'] ?? 1024;
    $asset->filename = $overrides['filename'] ?? 'chart.png';
    $asset->width = $overrides['width'] ?? 800;
    $asset->height = $overrides['height'] ?? 600;
    $asset->media_type = $overrides['media_type'] ?? 'image';
    $asset->storage_mode = $overrides['storage_mode'] ?? 'data_url';
    $asset->payload = $overrides['payload'] ?? 'fake-bytes';
    $asset->asset_url = $overrides['asset_url'] ?? '/api/v1/assets/' . $asset->id . '.png';
    $asset->is_temporary = false;
    $asset->save();

    return $asset;
}

/*
|--------------------------------------------------------------------------
| Test lifecycle
|--------------------------------------------------------------------------
*/

uses()
    ->beforeEach(function () {
        ignoreVendorDeprecations();

        Spora\Core\Database::resetBootState();
        $db = new Spora\Core\Database(new Spora\Core\Config(['SPORA_DB_DRIVER' => 'sqlite', 'SPORA_DB_PATH' => ':memory:']));
        $db->boot();
        Capsule::connection()->beginTransaction();

        // The host installer only runs plugin migrations behind a `PluginLoader`, which this suite does not wire.
        foreach (glob(__DIR__ . '/../database/migrations/*.php') ?: [] as $file) {
            $migration = require $file;
            if (is_object($migration) && method_exists($migration, 'up')) {
                $migration->up();
            }
        }
    })
    ->afterEach(function () {
        restore_error_handler();

        if (Capsule::connection()->transactionLevel() > 0) {
            Capsule::connection()->rollBack();
        }
        Spora\Core\Database::resetBootState();
        Mockery::close();
    })
    ->in(__DIR__);
