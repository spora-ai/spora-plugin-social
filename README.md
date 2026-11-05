# spora-plugin-social

Native social publishing for **LinkedIn**, **Facebook** and **Instagram**.

Connect your own accounts, write one post for every network, let an agent
schedule it, and see how each one did. This plugin replaces
`spora-plugin-zernio`, which was a thin proxy to a third-party service that
owned the tokens, the scheduling and the publishing.

## What it does

- **Connect accounts.** One login per provider. **Meta** is one login for
  both networks: the *Instagram API with Unified Login*, built on Facebook
  Login for Business. The client picks a business portfolio, the Instagram
  professional account and the Facebook Page, and the app gets one
  **system-user token** that covers every asset they shared. It has no
  refresh grant, so it is reconnected rather than renewed, and whether it
  expires at all is the configuration's decision — a Meta token *can* carry
  an expiry, and the expiry the platform sends is the expiry the
  connection keeps. **LinkedIn** keeps its own flow: PKCE, OpenID Connect,
  and one token that covers the member and the organisations they
  administer.
  One Instagram caveat the plugin cannot check for you: an account
  connected to a Page that *requires* Page Publishing Authorization cannot
  be published to until PPA is completed, and Meta says there is no way
  for an app to detect that.
- **Publish.** One post, aimed at several accounts. The outcome is recorded
  **per account**, so a post that reached LinkedIn and not Instagram is
  `partial`, not `failed`.
- **Write.** The composer's *Draft with AI* button hands the request to an
  agent, which writes the post with the `social_post` tool and saves it as a
  draft the operator can edit in the panel.
- **Take down.** A published post can be pulled back down, one network at a
  time. LinkedIn and Facebook can delete a published post; **Instagram
  cannot** — the delete endpoint exists only under Instagram's *Facebook
  Login*, and this plugin speaks the *Unified Login*, so the API has no
  delete for published media there — so that target stays published and
  says why.
- **Schedule.** The agent writes the copy, the operator approves the copy
  and the time, and the worker publishes it when the moment comes. The
  agent-driven mode is the headline: it is the reason the plugin exists.
- **Measure.** Each platform's own insights API, normalised into one set of
  counters with the platform's own numbers kept beside them. The networks
  stay apart — a summed figure is labelled a rough one.

There is **no fixed slot** — no "post the same text every Tuesday". It
published the same words every week, and LinkedIn and Meta ship native
scheduled posts now, so a third-party static repeater competed on the
weakest axis available. The agent-driven mode replaced it.

## The agent template and the skill

The plugin ships both:

- **`agent-templates/social-poster.json`** — an agent that posts for the
  operator on a schedule. Its system prompt carries the per-platform rules,
  because an agent that writes 3,000 characters for Instagram or forgets the
  media is worse than no agent at all. Reads and drafts are pre-approved;
  anything that publishes is not.
- **`skills/social-poster/SKILL.md`** — the operating manual: what each
  tool does, the per-platform rules, the media rules, the approval
  behaviour, and the worked flow from "the operator asked for a weekly
  post" to "the post is scheduled".

## Operator prerequisites

| Setting | Why |
|---|---|
| `SPORA_SOCIAL_LINKEDIN_CLIENT_ID` / `_SECRET` | Your LinkedIn app. Member posting needs no review; organisation pages need a **second** app, because the Community Management API has to be the only product on an app. The setting's own description carries the redirect URL to register with it. |
| `SPORA_SOCIAL_LINKEDIN_MEMBER_SCOPE` | On by default. Turn it off for an app that cannot post as a member. |
| `SPORA_SOCIAL_LINKEDIN_SIGN_IN_SCOPE` | On by default. Turn it off for a Community Management API app, which cannot serve OpenID Connect — asking for it refuses the whole request. |
| `SPORA_SOCIAL_META_APP_ID` / `_SECRET` | Your Meta app — **one app for both networks**. `pages_manage_posts` needs the app reviewed. |
| `SPORA_SOCIAL_META_CONFIG_ID` | The id of the Facebook Login for Business configuration the login dialog uses: the permissions and assets your app asks a client for. Not a secret, but the login cannot happen without it. The setting's own description carries the redirect URL to register with it. |
| `SPORA_SOCIAL_LINKEDIN_ORGANIZATION_SCOPE` | Turn on **only** once your app holds `w_organization_social` — asking for a scope the app does not have makes the whole authorization request fail. |
| `SPORA_SOCIAL_LINKEDIN_READ_ORG_ACLS` | Turn on **only** once your app holds `r_organization_admin` (the organisations the member administers). One setting per permission, because an app that asks for one it does not hold is refused the whole request. |
| `SPORA_SOCIAL_LINKEDIN_READ_ORG_STATS` | Turn on **only** once your app holds `rw_organization_admin` (an organisation's share statistics). |
| `SPORA_SOCIAL_LINKEDIN_READ_MEMBER_FEED` | Turn on **only** once your app holds `r_member_social_feed` (a member post's reactions). |
| `SPORA_SOCIAL_LINKEDIN_READ_ORG_FEED` | Turn on **only** once your app holds `r_organization_social_feed` (an organisation post's reactions). |
| `SPORA_SOCIAL_LINKEDIN_API_VERSION` | The LinkedIn API version, in `YYYYMM`. Defaults to the current one; LinkedIn versions its APIs quarterly, so a fixed version stops working on a date nobody chose. |
| `SPORA_SOCIAL_GRAPH_API_VERSION` | The Meta Graph API version every Facebook and Instagram endpoint carries. Defaults to the current one; Meta retires a version every year. |
| `SPORA_SOCIAL_FRONTEND_URL` | Where the browser lands after an account is connected. Defaults to `SPORA_APP_URL`; set it when the SPA runs on another origin, as it does in local development. |
| `SPORA_APP_URL` | Must be **publicly reachable over HTTPS**. Instagram fetches the published media from the internet, so an address only your network can see fails at the last step of a publish. The plugin checks this at connect time, and `GET /config` reports it as `app_url_public`. The OAuth redirect URI is built from it, and the platforms need that address registered — so it has to be the address you registered, not the one the request happened to arrive on. |

## Checking it against the real platforms

The adapter tests answer with hand-written responses, so they prove the
plugin builds the request the way the code already understood it. They
cannot prove the request is right — and things have been wrong that only
the documentation could have told us. The live suite is the answer to that:
it signs in for real, publishes a post on every account it can reach, reads
the numbers back and takes the post down again where the platform allows
it.

It runs only when you ask for it, because it needs credentials and the
network, and it is excluded from `composer test`. Run it from the plugin's
directory, after `composer install`.

```bash
composer live
```

The run prints the redirect URI to register, the authorization URL of every
platform you gave it credentials for, and asks you to paste back the URL
the platform sent you to. Then it prints a verdict per platform:

```
  linkedin   PASSED   7 passed, 0 failed, 0 skipped
  facebook   FAILED   3 passed, 1 failed, 2 skipped
  instagram  SKIPPED  0 passed, 0 failed, 5 skipped
             skipped: SPORA_LIVE_META_CLIENT_ID is not in the environment
```

A skipped check is not a passed check. The command exits non-zero when
nothing was verified, so a run without credentials cannot be mistaken for
a green one.

### The variables it reads

The names carry `LIVE`, so it is visible that they belong to the harness
and not to the install — the plugin's own settings are `SPORA_SOCIAL_*`
and the live suite never reads those.

| Variable | What it is |
|---|---|
| `SPORA_LIVE_LINKEDIN_CLIENT_ID` / `SPORA_LIVE_LINKEDIN_CLIENT_SECRET` | Your LinkedIn app's credentials. |
| `SPORA_LIVE_META_CLIENT_ID` / `SPORA_LIVE_META_CLIENT_SECRET` / `SPORA_LIVE_META_CONFIG_ID` | Your Meta app's credentials and the configuration id. Facebook and Instagram are one login now. |
| `SPORA_LIVE_META_REDIRECT_URL` / `SPORA_LIVE_LINKEDIN_REDIRECT_URL` | The url you landed on, when the run is a second one. Named after the provider; the platform spellings (`SPORA_LIVE_FACEBOOK_REDIRECT_URL`, `SPORA_LIVE_INSTAGRAM_REDIRECT_URL`) are still accepted. |
| `SPORA_LIVE_REDIRECT_URI` | The public address of your install, e.g. `https://spora.example.com`. The per-platform callback is built from it exactly as the plugin builds it. |
| `SPORA_LIVE_PUBLIC_IMAGE_URL` | A publicly reachable **JPEG** for Instagram to fetch. Instagram has no text-only post, and it takes no PNG. |
| `SPORA_LIVE_LINKEDIN_ORGANIZATION_SCOPE` | Set it once your app holds the organisation scopes. |
| `SPORA_LIVE_LINKEDIN_READ_ORG_ACLS` / `_READ_ORG_STATS` / `_READ_MEMBER_FEED` / `_READ_ORG_FEED` | Set the one once your app holds that read permission. |
| `SPORA_LIVE_GRAPH_API_VERSION` / `SPORA_LIVE_LINKEDIN_API_VERSION` | The versions to check against, when they differ from the plugin's defaults. |
| `SPORA_LIVE_<PLATFORM>_REDIRECT_URL` | The URL the platform sent you back to, when you collected it in an earlier run. |

### What to do first

**LinkedIn** — [developer portal](https://www.linkedin.com/developers/apps)

LinkedIn splits this in two, and the two halves cannot share an app.

**Posting as yourself** is self-service. One app does it:

1. Create an app and add the **Sign in with LinkedIn** product. It brings
   OpenID Connect and the member id with it.
2. Copy the **Client ID** and the **Client Secret** from *Auth*.
3. Register the redirect URI
   `https://<your-spora>/api/v1/plugins/social/oauth/linkedin/callback`
   under *Products → Sign in with LinkedIn → Redirect URLs*.
4. Add the **Share on LinkedIn** product.

Member posting (`w_member_social`) is **self-service**: an app in
development mode posts as the member who signed in, and no review is
involved. **This is as far as an individual developer goes.** The Share on
LinkedIn product grants `w_member_social` and nothing else — it posts as
the signed-in member, never as a page.

**Posting to a company page** is a second app, and a different kind of
applicant. `w_organization_social` — the permission that posts on behalf of
a page — comes only with the **Community Management API**, and LinkedIn's
rules for that product are strict:

- **It has to be the only product on the app.** "Share on LinkedIn" is one
  of the products that blocks it, so the app above can never hold it. The
  Community Management API app is a **second app**, created with no
  products at all and verified against the **same company page**.
  > "The Community Management API requires that it be the only product
  > associated with a developer application due to legal and security
  > constraints." — [LinkedIn API Partner Support](https://learn.microsoft.com/en-us/linkedin/shared/linkedin-api-partner-support-guide)
- **Only a legal organisation can ask for it.** LinkedIn vets the
  applicant: a verified business email, the organisation's legal name and
  registered address, its website and a privacy policy, and a company page
  whose super admin verified the app. "Personal email addresses won't pass
  the vetting process." An individual developer without a registered legal
  entity cannot get it.
  > "our Community Management APIs are only available to registered legal
  > organizations for commercial use cases only" — [Community Management app review](https://learn.microsoft.com/en-us/linkedin/marketing/community-management-app-review)
- **It is a review in two tiers.** Development tier first — 500 calls a day
  for the app, 100 a day per member — then Standard tier, which needs a
  screen recording of the app working.

Point the plugin at the **Community Management API app**. It grants
`w_member_social` as well, so that one app covers member posting and page
posting together. It cannot serve OpenID Connect, so turn **Ask LinkedIn for
the member's identity** off: the member is then named by the organisation
access-control list, which says who the role belongs to.

The four read permissions are asked for one at a time — turn each on once
the app holds it, never before, because an app that asks for a permission
it does not hold is refused the whole authorization request. They are one
setting each, so an app that holds one of them is not refused over the
other three:

| Permission | Reads |
|---|---|
| `r_organization_admin` | the organisations the member administers |
| `rw_organization_admin` | an organisation's share statistics |
| `r_member_social_feed` | a member post's reactions |
| `r_organization_social_feed` | an organisation post's reactions |

All four are off by default. Without them the plugin still publishes; the
numbers simply stay empty.

**Meta** — [app dashboard](https://developers.facebook.com/apps)

1. Create a **Business** app. A *Consumer* app cannot use Instagram.
   The app must be owned by a business portfolio you control.
2. Copy the **App ID** and the **App Secret**.
3. Add the **Facebook Login for Business** product.
4. Add the **Manage messaging & content on Instagram** use case. It is what
   brings up the *API setup with Unified login* tab, and that tab is where
   the configuration and the permissions live.
5. Open *Facebook Login for Business → Configurations* and create one:
   the **system-user access token** type, the **Instagram professional
   account** and the **Facebook Page** as the assets, and the permissions
   below. Copy the **Configuration ID** into `SPORA_SOCIAL_META_CONFIG_ID`.
6. Register the redirect URL
   `https://<your-spora>/api/v1/plugins/social/oauth/meta/callback`
   under the configuration.
7. Request the permissions: `instagram_basic`, `instagram_content_publish`
   and `instagram_manage_insights` for Instagram, `pages_show_list`,
   `pages_manage_posts`, `pages_read_engagement` and `read_insights` for
   the Pages.
8. **To list the Pages** — the client shares them, and the plugin needs
   their ids — add `business_management` to the configuration. It grants
   the whole business portfolio and needs its own review, so the plugin
   does not ask for it: without it the connection covers the Instagram
   account alone, and the Pages have to be published to by their ids.

**Standard Access against Advanced Access** is the review state of the
permissions, not of the app. Standard Access is enough to use the app with
people who already have a role on it — the app's own testers and
administrators — which is what you need to try the plugin with your own
accounts. Advanced Access is what makes the permissions available to the
public, and it needs an App Review, which takes weeks. So: **start on
Standard Access, get the live check green with your own accounts, and
start the review before you write the copy.** Nothing in the plugin waits
for the review — it runs against a developer-mode app — but a post that
reaches an audience of strangers needs it.

**A public image for Instagram.** Instagram fetches the media from the
internet, so the image has to be served from an address the platform can
reach, and it has to be a JPEG. Put one on any public host and set
`SPORA_LIVE_PUBLIC_IMAGE_URL` to it.

### What the plugin does not set up for you

The Community Management API app is **the operator's work**: the second app,
the legal entity, the access-request form, the screen recording for
Standard tier. The plugin points at one LinkedIn app and asks it for the
scopes the operator says it holds — it does not build the app, and it
cannot get one approved.

That is deliberate, and it is also the reason a project without a legal
entity cannot reach a page at all. **Posting as a page is worth the path
when there is an organisation behind it.** An open-source project that
wants a page of its own needs a registered legal entity to ask with, and
that is the part that has to be arranged before any of this works.

### What it leaves behind

The LinkedIn post is deleted again, and the Facebook post too where the
platform allows it. **The Instagram post stays** — the API cannot delete a
published post, so the live check leaves one post on your Instagram
account. The run says so in its report.

## The tools

Five tools, for agents that write the posts:

| Tool | Operations |
|---|---|
| `social_connections` | `list`, `connect`, `disconnect`, `refresh`, `health` |
| `social_post` | `create`, `schedule`, `publish`, `list`, `inspect`, `update`, `retry`, `delete` |
| `social_media` | `list_assets`, `check_fit`, `prepare`, `attach`, `detach` |
| `social_analytics` | `post_metrics`, `period_totals`, `top_posts`, `network_comparison` |
| `social_calendar` | `list_month` |

`schedule` is the one call that schedules a post — it creates it and dates
it, so there is no separate `create` first. `social_post` with action
`list` takes a `from` / `to` window, which is how an agent sees what is
already going out.

A **draft** may be written without an account: the accounts are what name
the platforms, and a draft has not been sent anywhere. It cannot go out
until one is picked, and the plugin says so — on the answer, on the post
in the list, and on the refusal to publish it.

Every read answers with the rows, not with a count: the account ids a
post can go to, the asset ids that can be attached, the post ids and the
days they are due. A model that cannot see an id has to guess it, and a
guessed account id is a post that goes nowhere.

`social_media` with action `check_fit` judges an asset as the platform
**receives** it: the backend converts a PNG to a JPEG for Instagram
before it publishes, so a PNG fits Instagram and the answer says so.

Publishing needs the operator's approval: it cannot be undone on the
network, and the copy goes out under the account's name. A **scheduled**
post needs it too — it commits the copy to go out later without asking
again.

## What each platform accepts

| Platform | Media | Types | Cap |
|---|---|---|---|
| LinkedIn | optional | JPEG, PNG, GIF | 8 MB |
| Facebook | optional | JPEG, PNG, GIF, BMP, MP4 | 1 GB |
| Instagram | **required** | JPEG (images), MP4 (video) | 100 MB |

An attachment that does not fit is flagged **on the attachment**, with the
remedy named — "Convert to JPEG", "Compress" — because the operator can act
on that and not on a platform's error text.

A post carries **one** attachment, and the backend refuses a second one:
the publish sends one file, so a second would be a file the operator
believes is going out and is not. Instagram **fetches** the media from the
internet, so the bytes have to be reachable from outside — the plugin
serves them from a public URL for it; LinkedIn and Facebook take the bytes.

Instagram takes no PNG at all, so the plugin converts it through core's own
image producer before publishing.

A post carries **one** body, and every platform publishes it. A platform
gets a body of its own only where its readers, its conventions or its limit
ask for one, and that copy goes in `platform_bodies`.

The **body** has a limit too, and the plugin enforces it at publish time:
LinkedIn 3,000 · Facebook 63,206 · Instagram 2,200 characters. Each target
is judged against its own platform's limit and its own body
(`platform_bodies[platform]`, or the shared `body`), so a post aimed at
Instagram and Facebook with one body is checked twice. The refusal reads
the way the composer reads: *"Over the limit: 5000 / 2200 characters for
Instagram."*

Each platform shows part of the body before it folds the rest away:
LinkedIn 200 characters before "see more", Facebook 125 in the feed,
Instagram 125 before "more". The whole body is published either way.

**Hashtags** are a list of tags without the hashes. The plugin adds them
under the body when the post goes out, on every platform.

## Times and timezones

A post's `scheduled_for` is read in the post's own `timezone`:

- a value that carries an offset (or a `Z`) is **absolute** — the named
  zone does not move it;
- a value without one is a **wall-clock time in the named zone**, so
  `"2026-11-02 09:00"` with `timezone: Europe/Berlin` goes out at 08:00
  UTC.

A `timezone` PHP does not know falls back to UTC rather than failing the
post.

## What the numbers mean

- **A LinkedIn member post has no impressions.** `organizationalEntityShareStatistics`
  exists for organisation pages only; a member post reports its reactions
  alone. The plugin says so instead of showing a zero, which reads as
  "nobody saw it".
- **The account says what it cannot report, and why.** The grid names the
  reason on the account itself — *"Member posts report reactions only — no
  impressions"*, *"Personal accounts have no media insights"* — and, when
  the reason is a permission the token does not carry, it names that
  permission too. The permissions are the connection's, so an Instagram
  account whose token does not carry `instagram_manage_insights` says so.
  A token that never reported its scopes is not treated as one without
  them.
- **Reach counts people, impressions count views.** LinkedIn's reach is
  `uniqueImpressionsCount`, Facebook's is `post_impressions_unique` and
  Instagram's is `reach` — the same question, asked of each network.
- **The token's health sits on the connection.** One login, one token: the
  expiry, the status (`active`, `expired`, `revoked`, `error`) and the
  *needs reconnect* flag belong to the connection, not to each account it
  covers.
- **A Meta token is not renewed, it is reconnected.** It has no refresh
  grant: the refresh is for a token with an expiry, and a Meta token
  "defaults to never expire" — the configuration decides, and the expiry
  the platform sends is the expiry the connection carries. A Meta token
  that has stopped working is reconnected, not refreshed.
- **Every reading is a dated snapshot.** Impressions keep climbing for days
  and the platforms restate them. Nothing here is final.
- **The networks are kept apart.** They count reach differently, so the
  rows stay separate and the combined figure is labelled a rough one.

## Install

```bash
composer require spora-ai/spora-plugin-social
```

The frontend is a separate Composer package
(`spora-ai/spora-plugin-social-frontend`) and is pulled in with it.

> The frontend package is built in parallel with this one. Until it is
> published, `composer install` needs a local stand-in for it — a Composer
> package of type `spora-plugin-frontend` with
> `extra.spora-plugin-slug: social` — registered as a `path` repository.
> `composer.lock` then pins that path and has to be regenerated once the
> real package is on Packagist:
> `composer update spora-ai/spora-plugin-social-frontend`.

## What the plugin needs from core

`composer.json` requires **`spora-ai/spora-core >=0.32.0 <2.0.0`**.

Two core extension points carry this plugin, and neither exists below 0.32:

- **`Spora\Scheduling\DueWorkProviderInterface`** — the plugin's own work
  (a scheduled post whose moment has come, a reading that is due) is
  claimed and executed by the worker through this hook. Without it a post
  can be published by hand but nothing fires on its own.
- **`Spora\Settings\PluginSettingsProviderInterface`** — the client ids and
  secrets below are operator-facing settings, and core's registry is what
  gives them a key, a storage group and a place on the Plugins tab.

**The core release carrying them must be tagged before this plugin ships.**
`compat/core-extension-points.php` declares the two contracts so the
analysis and the suite can run against a core that predates them; it
declares nothing once core ships the real classes, and it is not a
substitute for the release.

## Development

```bash
composer analyse          # PHPStan
composer test:parallel    # Pest
composer live             # the live check against the real platforms
composer format           # php-cs-fixer
```

### Connecting an account on a local install

`SPORA_APP_URL` has to be an address the internet can reach, because
Instagram fetches the published media with Meta's own servers. A
`localhost` address cannot be — so the connect is refused before the
platform is even involved.

A development install can say that it knows that:

| Setting | Value |
|---|---|
| `SPORA_SOCIAL_ALLOW_LOCAL_APP_URL` | `true` — also a toggle in **Settings → Plugins** |

**What it buys.** The connect goes through, so there is a token, a draft,
a schedule and a publish to develop against. LinkedIn and Facebook photos
upload the bytes and work either way.

**What it does not buy.** Instagram still fails: the platform fetches the
media from `{SPORA_APP_URL}/api/v1/public/media/{id}?token=…` with its own
servers, and no setting makes a loopback address reachable from Meta.
Those publishes fail with the platform's own reason, recorded on the
target — which is the failure worth seeing while developing.

For a local install that publishes to Instagram, put a tunnel in front of
it instead:

```bash
cloudflared tunnel --url http://127.0.0.1:8080   # → https://<host>.trycloudflare.com
```

and set `SPORA_APP_URL` to that address. It is the address the OAuth
redirect URI is built from *and* the address the platform fetches the
media from, so it has to be the one registered with the app — the address
of your API, not the one the request happened to carry. Core never reads
`X-Forwarded-*`, and the tunnel ends TLS locally, so the detected origin
would be `http://<host>` rather than `https://<host>`: set it explicitly.
Set `SPORA_SOCIAL_FRONTEND_URL` to the origin the panel runs on when that
is not the API's own, so the browser lands back on the panel after the
OAuth round trip.

**Leave the setting off in production.** A production install that
carries it publishes to Instagram and fails with a platform error that
says nothing about the address.

### The local checkouts

The two dependencies this package needs — `spora-ai/spora-core` and
`spora-ai/spora-plugin-social-frontend` — are resolved from **Packagist**, so
the repository carries no machine-specific path. To work against the local
checkouts, add them as path repositories **on your machine**:

```bash
composer config --global repositories.spora-core-local '{"type":"path","url":"../spora-core","options":{"symlink":true,"versions":{"spora-ai/spora-core":"0.32.0"}}}'
composer config --global repositories.spora-frontend-local '{"type":"path","url":"../spora-plugin-social-frontend","options":{"symlink":true,"versions":{"spora-ai/spora-plugin-social-frontend":"0.1.0"}}}'
```

**`composer.lock` is not committed until both dependencies are published.**
A lock generated now could only pin a local checkout or an untagged branch,
and it would be committed by accident on the next machine that ran
`composer install`. It is committed at release, like every other package in
the house.

The documentation for this plugin lives at
[docs.spora-ai.com/develop/plugins/reference/social](https://docs.spora-ai.com/develop/plugins/reference/social);
the live-check runbook above is the operator-facing half of it.

## Licence

MIT.
