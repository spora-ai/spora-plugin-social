---
name: social-poster
description: "Write, schedule and publish the operator's LinkedIn, Facebook and Instagram posts. Use when the operator asks for a social media post, wants something posted to a network, wants a post scheduled for later, asks what is already going out, or asks how a post performed. Carries the per-platform rules — Instagram needs media and takes no PNG, LinkedIn takes images only, the character limits differ — and the media and approval rules."
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
| `social_calendar` | The posts of a month with the days they are due. |

## The flow, from the operator's request to a scheduled post

> "Post something about the release next Tuesday morning."

1. **Know the accounts.** `social_connections` with action `list`. Which
   accounts are connected, on which platforms. A connection whose token is
   close to its expiry (`needs_reconnect`) is flagged there — renew it with
   action `refresh` and the connection's `connection_id` before you plan a
   post around it. A Meta connection is not renewed: it has no refresh
   grant, and a Meta token that has stopped working is reconnected.
2. **Know what is already going out.** `social_post` with action `list`
   and `from` / `to` set to the days around the requested date, or
   `social_calendar` with action `list_month` for the month. Do not post
   the same thing twice.
3. **Write the copy.** One body per platform — see the rules below.
4. **Pick the media.** `social_media` with action `list_assets` for the
   library, then `check_fit` for the asset against every platform you are
   targeting.
5. **Hand it over.** `social_post` with action `schedule`, the body (and
   the per-platform bodies), the account ids, the media asset ids and the
   time. That is one call — `schedule` creates the post and dates it, so
   there is no separate "create" first.
6. **Later, read the result.** `social_analytics` with action
   `post_metrics` for one post, or `period_totals` for the period.

## `create` versus `schedule`

- `create` makes a **draft**. Nothing goes out; it is for a post the
  operator wants to see and rewrite. A draft needs no approval.
- `schedule` makes a post that **goes out at a moment**. The worker
  publishes it when the time comes. This is the call for "post this on
  Tuesday".
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

One body per platform. The platforms have different conventions, different
limits and different readers; one text for all three reads as an
afterthought on at least one of them.

| Platform | Media | Types | Cap | Body |
|---|---|---|---|---|
| LinkedIn | optional | JPEG, PNG, GIF | 8 MB | up to 3,000 characters, ~200 shown before "see more" |
| Facebook | optional | JPEG, PNG, GIF, BMP, MP4 | 1 GB | up to 63,206 characters, ~125 shown in the feed |
| Instagram | **required** | JPEG (image), MP4 (video) | 100 MB | up to 2,200 characters, ~125 shown before "more" |

A body over the limit does not go out. The publish fails with the same
wording the composer shows: "Over the limit: 5000 / 2200 characters for
Instagram."

**LinkedIn publishes images only in this version.** No video, no carousels,
no reels, no stories.

**Instagram has no text-only post.** Media is required, and **Instagram
takes no PNG at all** — a PNG asset has to be converted. You do not convert
it yourself: attach the original asset id and the backend converts it to
JPEG when it publishes. Use `social_media` action `prepare` to see what
Instagram will get — the JPEG derivative and the public URL it will fetch.

The character limits are the platforms' limits, and the plugin **enforces
them at publish time**: a body over its platform's limit is refused, and the
refusal names the platform and the numbers. Each platform is judged against
its own body — `platform_bodies[platform]`, or the shared `body` when there
is none for it — so a post aimed at Instagram and Facebook with one body
is checked twice, against each platform's limit.

## The media rules

- Media comes from the **Spora Media Archive**. `list_assets` shows it;
  the ids are what you attach.
- Attach the **original** asset id. The backend converts for Instagram and
  publishes the bytes for LinkedIn and Facebook.
- **Check before you attach.** `check_fit` answers per platform and names
  the remedy: "Convert to JPEG", "Compress", "Convert the file to MP4".
  Attach an asset only when it fits every platform you are targeting.
- One attachment per post in this version, and the backend refuses a second
  one.
- **Instagram fetches the media from the internet**, so the file has to be
  reachable from outside — the backend serves it from a public URL for you.
  LinkedIn and Facebook take the bytes.

## The approval behaviour

| Operation | Approval |
|---|---|
| `list`, `inspect`, `list_assets`, `check_fit`, `prepare`, `list_month`, `health`, every `social_analytics` operation | not needed |
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
  `by_network` is the honest one.

## What not to do

- Do not publish on your own initiative. "Post this every Tuesday" is a
  schedule the operator approves.
- Do not post the same thing twice — look at what is going out first.
- Do not write one text for three platforms.
- Do not attach an asset you have not checked.
- Do not invent an account id — list them.
