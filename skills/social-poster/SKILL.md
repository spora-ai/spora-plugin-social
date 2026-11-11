---
name: social-poster
description: "Write, schedule and publish the operator's social posts — their own LinkedIn, Facebook and Instagram accounts, and the networks an aggregator reaches for them. Use when the operator asks for a social media post, wants something posted to a network, wants a post scheduled for later, asks what is already going out, or asks how a post performed. Carries the per-platform rules — Instagram needs media and takes no PNG, LinkedIn takes images only, the character limits differ — the aggregator's own rules, and the media and approval rules."
license: MIT
compatibility: spora>=0.32 spora-plugin-social>=0.1
metadata:
  author: spora-ai
  version: "1.0"
allowed-tools: social_connections social_post social_media social_analytics social_calendar
---

# Social poster

Five tools, one job: a post goes out on the operator's networks, in the
operator's voice, with the operator's approval.

| Tool | What it is for |
|---|---|
| `social_connections` | The connections: one login per provider, and the accounts it covers. |
| `social_post` | The posts: draft, schedule, publish, list, inspect, update, retry, delete. |
| `social_media` | The Media Archive: what is there, whether it fits, and attaching it. |
| `social_analytics` | What the posts did: one post, a period, the top posts, the networks side by side. |
| `social_calendar` | The posts of a month with the days they are due and the channels they go to. |

## The flow, from the operator's request to a scheduled post

> "Post something about the release next Tuesday morning."

1. **Know the accounts.** `social_connections` with action `list`. Which
   accounts are connected, on which platforms — the answer carries the
   account id of each one, and that id is what `social_post` takes. A
   connection whose token is
   close to its expiry (`needs_reconnect`) is flagged there — renew it with
   action `refresh` and the connection's `connection_id` before you plan a
   post around it. A Meta connection is not renewed: it has no refresh
   grant, and a Meta token that has stopped working is reconnected.
2. **Know what is already going out.** `social_post` with action `list`
   and `from` / `to` set to the days around the requested date, or
   `social_calendar` with action `list_month` for the month. Do not post
   the same thing twice.
3. **Write the copy.** One body for every platform — see the rules below.
   A platform gets a body of its own only where its copy really differs.
4. **Pick the media.** `social_media` with action `list_assets` for the
   library, then `check_fit` for the asset against every platform you are
   targeting.
5. **Hand it over.** `social_post` with action `schedule`, the body (and
   the per-platform bodies where they differ), the hashtags, the account
   ids, the media asset ids and the time. That is one call — `schedule`
   creates the post and dates it, so there is no separate "create" first.
6. **Later, read the result.** `social_analytics` with action
   `post_metrics` for one post, or `period_totals` for the period.

## `create` versus `schedule`

- `create` makes a **draft**. Nothing goes out; it is for a post the
  operator wants to see and rewrite. A draft needs no approval, and it
  needs no account either — the accounts are what name the platforms, so
  a draft may sit without one. It cannot go out until an account is
  picked, and the answer says so. Say it to the operator too.
- `schedule` makes a post that **goes out at a moment**. The worker
  publishes it when the time comes. This is the call for "post this on
  Tuesday". A scheduled post needs at least one account: it commits the
  copy to go out, so it has to know where to.
- `publish` sends it **now**.
- `retry` sends again only the accounts that failed. The ones that
  published are left alone.
- `update` changes a post — its body, its per-platform bodies, its
  accounts, its media.
- `delete` takes a **published** post back down, one network at a time.
  LinkedIn and Facebook can delete a published post. **Instagram cannot** —
  the delete endpoint exists only under Instagram's *Facebook Login*, and
  this plugin speaks the *Unified Login*, so the API has no delete for
  published media there — so that target stays published and the result
  says so.

## The per-platform rules

One body goes to every platform. A platform gets a body of its own only
where its readers, its conventions or its limit ask for one: the copy that
fits one of them need not fit another. Three bodies by reflex is as wrong as
one body that ignores the platform.

| Platform | Media | Types | Cap | Body |
|---|---|---|---|---|
| LinkedIn | optional | JPEG, PNG, GIF | 8 MB | up to 3,000 characters, ~200 shown before "see more" |
| Facebook | optional | JPEG, PNG, GIF, BMP, MP4 | 1 GB | up to 63,206 characters, ~125 shown in the feed |
| Instagram | **required** | JPEG (image), MP4 (video) | 100 MB | up to 2,200 characters, ~125 shown before "more" |

A body over the limit does not go out. The publish fails with the same
wording the composer shows: "Over the limit: 5000 / 2200 characters for
Instagram."

The visible count is not a limit: the whole body is published, and the
platform folds the rest away until a reader opens it.

**LinkedIn publishes images only in this version.** No video, no carousels,
no reels, no stories.

**Instagram has no text-only post.** Media is required, and **Instagram
takes no PNG at all** — a PNG asset has to be converted. You do not convert
it yourself: attach the original asset id and the backend converts it to
JPEG when it publishes. Use `social_media` action `prepare` to see what
Instagram will get — the JPEG derivative, its asset id and the public URL
it will fetch.

**A file the network will not take is converted, not refused.** The same
holds for every network the plugin publishes itself: a WebP on its way to
LinkedIn becomes a JPEG on the way there, because the archive can re-encode
an image and the first type the network accepts is the one it gets. Attach
the **original** asset id — the original stays in the library and the
conversion is a row beside it.

The aggregator is the exception: one post goes to the aggregator as one file
for a group of networks, so the file has to satisfy all of them. A file the
archive cannot convert — an SVG, a video where a network wants an image — is
left as it is, and `check_fit` says so.

The character limits are the platforms' limits, and the plugin **enforces
them at publish time**: a body over its platform's limit is refused, and the
refusal names the platform and the numbers. Each platform is judged against
its own body — `platform_bodies[platform]`, or the shared `body` when there
is none for it — so a post aimed at Instagram and Facebook with one body
is checked twice, against each platform's limit.

**Hashtags** are a list of tags without the hashes. The plugin adds them
under the body when the post goes out, on every platform. Keep it to a few —
a hashtag wall is not a voice.

## The aggregator

Some networks are not reached by the plugin's own connections. **LinkedIn,
Facebook and Instagram are**: the operator connects them, and the plugin
publishes to them directly.

**Everything else goes through an aggregator.** The operator adds Zernio on
the Accounts screen, pastes the API key, and Zernio's own accounts become
publishable targets: Bluesky, Threads, TikTok, YouTube, Pinterest, Reddit,
X, Google Business, Telegram. `social_connections` with action `list` shows
them like any other account, and `social_connections` with action `sync`
reads the accounts Zernio holds again. The operator manages them in Zernio's
own dashboard — the plugin never creates, renames or disconnects one.

**An aggregator target's limits are Zernio's, and they differ from the
native ones for the same network.** The table above is the plugin's own
route; this one is Zernio's:

| Network | Media | Cap | Body |
|---|---|---|---|
| X | optional | JPEG, PNG, WebP or GIF up to 5 MB, or an MP4 or MOV video up to 512 MB | 280 characters free, 25,000 on Premium — a URL counts as 23 and an emoji as 2 |
| Instagram | **required** | JPEG or PNG up to 8 MB, or an MP4 or MOV video up to 300 MB | up to 2,200 characters |
| Facebook | optional | JPEG, PNG or GIF up to 4 MB, or an MP4 or MOV video up to 4 GB | up to 63,206 characters, ~480 shown before "See more" |
| LinkedIn | optional | JPEG, PNG or GIF up to 8 MB, or an MP4, MOV or AVI video up to 5 GB | up to 3,000 characters |
| TikTok | **required** | JPEG, PNG or WebP up to 20 MB, or an MP4, MOV or WebM video up to 4 GB | up to 2,200 characters |
| YouTube | **required** | an MP4, MOV, AVI, WMV, FLV, 3GP or WebM video up to 256 GB | up to 5,000 characters |
| Pinterest | optional | JPEG, PNG, WebP or GIF up to 32 MB, or an MP4 or MOV video up to 2 GB | up to 800 characters |
| Reddit | optional | JPEG, PNG or GIF up to 20 MB, or an MP4 video up to 1 GB | up to 40,000 characters |
| Bluesky | optional | JPEG, PNG, WebP or GIF up to 1 MB, or an MP4 video up to 50 MB | up to 300 characters |
| Threads | optional | JPEG or PNG up to 8 MB, or an MP4 video up to 1 GB | up to 500 characters |
| Google Business | optional | JPEG or PNG up to 5 MB, no video | up to 1,500 characters |
| Telegram | optional | JPEG, PNG, GIF or WebP up to 10 MB, or an MP4 or MOV video up to 50 MB | up to 4,096 characters |

Zernio's own limits are the wider ones: a LinkedIn post through Zernio may
carry several images and a video, where the plugin's own LinkedIn route
carries one image and no video. **The plugin's rule of one attachment per
post still holds on every channel**: the publish sends one file, and a second
is a file the operator believes is going out and is not. **X counts
its characters its own way** — a URL is 23 characters whatever its length and
an emoji is 2 — so the plugin does not count it for you: keep an X body short
and let X refuse it if it is too long.

**An aggregator target publishes at the moment it is due, not when the
aggregator says so.** Spora sends it with `publishNow: true`; Zernio's own
scheduler and queue are not used. The post goes out at the moment the
operator asked for.

**A failed aggregator target may be refused as a duplicate within 24
hours.** Zernio refuses the same content on the same account again within a
day, and it says so in its own words — the operator reads that message on the
target. It is Zernio's rule, not the plugin's, and there is nothing to work
around: the dedup is keyed on the whole content, so the way out is the one
Zernio names — change the caption, the media or the account.

**An aggregator's numbers need the analytics add-on on a legacy plan** — a
usage-based plan includes them. Without them the target reports no numbers,
and the note says why. And when the aggregator gives one aggregate for the
whole post rather than a number per network, the target reports that
aggregate with a note saying it covers every network in the post.

## The media rules

- Media comes from the **Spora Media Archive**. `list_assets` shows it;
  the ids are what you attach.
- Attach the **original** asset id. The backend converts what the network
  will not take — for Instagram and for every network the plugin publishes
  itself — and publishes the bytes.
- **Check before you attach.** `check_fit` answers per platform and names
  the remedy: "Convert to JPEG", "Compress", "Convert the file to MP4".
  It judges the asset as the platform receives it, so a PNG fits Instagram
  and a WebP fits LinkedIn: the backend converts before it publishes. A
  remedy appears only for a file the plugin **cannot** convert. Attach an
  asset only when it fits every platform you are targeting.
- One attachment per post in this version, and the backend refuses a second
  one.
- **Instagram fetches the media from the internet**, so the file has to be
  reachable from outside — the backend serves it from a public URL for you.
  LinkedIn and Facebook take the bytes.
- **An aggregator target gets the original asset's bytes**, not a converted
  derivative: Zernio takes JPEG, PNG, GIF and WebP, and it compresses above
  a network's limit itself.

## The approval behaviour

| Operation | Approval |
|---|---|
| `list`, `inspect`, `list_assets`, `check_fit`, `prepare`, `list_month`, `health`, `sync`, every `social_analytics` operation | not needed |
| `create` | not needed — a draft goes nowhere |
| `schedule`, `publish`, `retry`, `update`, `attach`, `detach`, `delete` | **the operator's** |

A scheduled post is published by the worker when its moment comes. The
operator approves the copy and the time once — not again at the moment.

## The numbers

- A **LinkedIn member post** (a personal account) reports reactions only:
  no impressions, no reach. The reach column is empty **by design**, and
  the plugin says so in the notes — it is not "nobody saw it".
- An **Instagram personal account** has no media insights at all.
- The LinkedIn numbers need the operator's read permissions, and each one
  is a separate setting, **all off by default**:

  | Setting | Permission | Reads |
  |---|---|---|
  | `SPORA_SOCIAL_LINKEDIN_READ_ORG_ACLS` | `r_organization_admin` | the organisations the member administers |
  | `SPORA_SOCIAL_LINKEDIN_READ_ORG_STATS` | `rw_organization_admin` | an organisation's share statistics |
  | `SPORA_SOCIAL_LINKEDIN_READ_MEMBER_FEED` | `r_member_social_feed` | a member post's reactions |
  | `SPORA_SOCIAL_LINKEDIN_READ_ORG_FEED` | `r_organization_social_feed` | an organisation post's reactions |

  Without them the posts still go out; the numbers stay empty. Tell the
  operator which one is missing rather than guessing at the numbers.
- Every reading is a **dated snapshot**. Impressions keep climbing for
  days and the platforms restate them. Nothing is final.
- The networks stay apart. A combined figure is a rough one: the three
  platforms count reach differently. `totals` is that rough figure;
  `by_network` is the honest one. A row is a **channel** — the network and
  the route to it — so the same network on two routes is two rows.
- **An aggregator's numbers need the analytics add-on on a legacy plan** — a
  usage-based plan includes them. Without them the numbers stay empty and
  the note says why. And one aggregate for the whole aggregator post covers
  every network in it, not the one account.

## What not to do

- Do not publish on your own initiative. "Post this every Tuesday" is a
  schedule the operator approves.
- Do not post the same thing twice — look at what is going out first.
- Do not give every platform a body of its own by reflex. One shared body,
  and a body of its own only where the copy really differs.
- Do not attach an asset you have not checked.
- Do not invent an account id — list them.
- Do not call a draft ready. A draft without an account has no platform
  to go to, and the operator has to know that before they wait for a post
  that was never going to go out.
- Do not promise an aggregator a time of its own. An aggregator target goes
  out at the moment it is due, like every other target.
- Do not work around a duplicate refusal. Zernio refuses the same content
  on the same account within 24 hours, and its own message is on the target
  for the operator to read.
