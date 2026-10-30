<?php

declare(strict_types=1);

namespace Spora\Plugins\Social\Tests\Support;

/**
 * The values the suite reuses.
 *
 * A literal that appears in several test files is a fact about the
 * fixture, not about the test that reads it, so it lives here. The ones
 * a single file repeats are here for the same reason: a wire fixture
 * written twice is a fixture that can drift.
 */
final class SocialFixtures
{
    /**
     * The body the sample post carries.
     */
    public const POST_BODY = 'We shipped…';

    /**
     * The internal label the sample post carries.
     */
    public const POST_TITLE = 'Q4 release';

    /**
     * The post a LinkedIn publish answers with.
     */
    public const LINKEDIN_SHARE = '{"id":"urn:li:share:9"}';

    public const MIME_PNG = 'image/png';

    public const MIME_JPEG = 'image/jpeg';

    /**
     * The zone the sample timestamps are written in.
     */
    public const BERLIN = 'Europe/Berlin';

    /**
     * The redirect uri the OAuth providers are pointed at.
     */
    public const OAUTH_CALLBACK = 'https://spora.test/callback';

    /**
     * The same redirect, spelled short.
     */
    public const OAUTH_CALLBACK_SHORT = 'https://spora.test/cb';

    /**
     * The host Meta's API is served from.
     */
    public const GRAPH_HOST = 'https://graph.facebook.com/';

    /**
     * The address the sample install is served from.
     */
    public const APP_URL = 'https://spora.example.com';

    /**
     * The date format the plugin's columns hold.
     */
    public const DB_DATETIME = 'Y-m-d H:i:s';

    /**
     * The subject Meta's userinfo answers with.
     */
    public const META_SUBJECT = '{"sub":"abc123","name":"Fabeat Person"}';

    /**
     * The member id Meta's fixtures use.
     */
    public const META_MEMBER_ID = '987654321';

    /**
     * The Page a Facebook publish answers with.
     */
    public const FACEBOOK_PAGE_ID = '123456';

    /**
     * The post a Facebook publish answers with.
     */
    public const FACEBOOK_POST_ID = '123456_789';

    /**
     * The Instagram account the fixtures publish as.
     */
    public const INSTAGRAM_ACCOUNT_ID = '17841405822304914';

    /**
     * The container Instagram creates before it publishes.
     */
    public const INSTAGRAM_CONTAINER_ID = '17900000000000001';

    /**
     * The container Instagram answers with.
     */
    public const INSTAGRAM_CONTAINER_RESPONSE = '{"id":"17900000000000001"}';

    /**
     * The media Instagram publishes.
     */
    public const INSTAGRAM_MEDIA_ID = '17900000000000002';

    /**
     * The image the Instagram fixtures publish.
     */
    public const INSTAGRAM_IMAGE_URL = 'https://www.example.com/images/bronz-fonz.jpg';

    /**
     * The caption the Instagram fixtures publish.
     */
    public const INSTAGRAM_CAPTION = 'Fruit candies';

    /**
     * The quota Instagram reports when the account has published nothing.
     */
    public const INSTAGRAM_QUOTA = '{"data":[{"quota_usage":0,"config":{"quota_total":50,"quota_duration":86400}}]}';

    /**
     * The text a LinkedIn publish carries.
     */
    public const LINKEDIN_TEXT = 'Sample text Post';

    /**
     * The text a LinkedIn publish carries when it has an image.
     */
    public const LINKEDIN_IMAGE_TEXT = 'test strings!';

    /**
     * The LinkedIn API version the fixtures speak.
     */
    public const LINKEDIN_VERSION = '202610';

    /**
     * The slot LinkedIn opens for an image upload.
     */
    public const LINKEDIN_UPLOAD_URL = 'https://www.linkedin.com/dms-uploads/C4E10AQFoyyAjHPMQuQ/uploaded-image/0';

    /**
     * The path a Facebook publish answers on.
     */
    public const FACEBOOK_FEED_PATH = '/feed';

    /**
     * The moment the calendar fixtures schedule for.
     */
    public const CALENDAR_MOMENT = '2026-11-02T09:00:00+00:00';

    /**
     * The month the calendar fixtures schedule in.
     */
    public const CALENDAR_MONTH = '2026-11';

    /**
     * The catalogue the locale test reads.
     */
    public const LOCALE_CATALOGUE = '/locales/de.json';

    /**
     * The plugin's own endpoints, as the request tests call them.
     */
    public const PLUGIN_CONFIG_PATH = '/api/v1/plugins/social/config';

    public const PLUGIN_POSTS_PATH = '/api/v1/plugins/social/posts';

    public const PLUGIN_CALENDAR_PATH = '/api/v1/plugins/social/calendar';

    public const PLUGIN_CONNECTIONS_PATH = '/api/v1/plugins/social/connections';

    public const PLUGIN_CALLBACK_PATH = '/api/v1/plugins/social/oauth/linkedin/callback';

    /**
     * The query the principal is named in.
     */
    public const PRINCIPAL_QUERY = '?principal_id=';

    /**
     * The brief the draft tests write from.
     */
    public const BRIEF = 'We shipped';

    /**
     * The copy a model answers with when it does not answer in JSON.
     */
    public const PROSE_ANSWER = 'We shipped, and it went well.';
}
