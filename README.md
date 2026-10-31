# Forum (`forum`)

The XTen.Stack service boundary between XTen games and the shared Flarum
community (contract: [`docs/Forum-Service-Interface-v0.1.md`](../docs/Forum-Service-Interface-v0.1.md)).
A game sends its own identity, alliance, membership and tutorial events to
one endpoint with a per-game API key; the plugin stores each event once
(idempotent on `event_id`), and a cron worker applies them to Flarum in
order, with bounded retry. Games never touch the Flarum database.

Plugin tier, headless (`"routes": false`), module code `frm`, Composer
package `xtendeploy/forum`, PHP namespace `Forum\` → `src/`.

**Free, MIT-licensed** (`LICENSE`): no licence key, `"license":
{"keyRequired": false}`. The MIT licence covers the code only; it does not
cover the XTen name or logo, and the software comes with no warranty (see
`NOTICE`). Released in its own public repository, `xtenstack/forum`. Runs on
PostgreSQL, MySQL/MariaDB and SQLite (three parallel migration dirs; the
contract suite is the release gate on all three).

**Interface version: 0.1.0** (`Forum\ForumServiceInterface::VERSION`).

## Install

From this repository (app_skeleton `docs/INTERNAL-MODULES.md`): a `vcs`
repository in `composer.local.json` pointing at
`https://github.com/xtenstack/forum`, or a `path` repository for a local
checkout, plus `"xtendeploy/forum": "^0.1"` in `require`; then

```bash
composer install            # or: composer update xtendeploy/forum
./run migrate run           # applies forum:001_forum for the configured adapter
./run modules sync
./run modules enable forum
```

Tables (default schema, `forum_` prefix): `forum_games`, `forum_events`,
`forum_links`, `forum_areas`, `forum_memberships`. Migration 001 also seeds
the cron row `forum` / `process` (every 5 minutes).

## Configure

1. **Flarum connection** — an `external_connections` row named `flarum`
   (Configuration → External connections): `base_url` = the Flarum root
   (no trailing `/api`), credential = a Flarum **master API key** (created
   in Flarum's `api_keys` table with `user_id` 1). Until it exists the
   worker *holds* events (`status = held`) and applies them once it does;
   nothing is lost. For local development without a Flarum, set
   `FORUM_FAKE_ADAPTER=1` and the in-memory fake is used instead.
2. **A game** — Forum → Forum games (admin), or
   `./run forum game <game_key> "<name>" <service-user-email>`. The service
   user is an ordinary Stack user (role `agent` is enough); the game sends
   with one of that user's API keys (Users → API keys). A key belonging to
   any other user is `403` for that `game_key`.

## What the game sends

```http
POST /api/forum/v1/events
Authorization: Bearer <api key of the game's service user>
Content-Type: application/json
```

Body: the contract envelope (`event_id`, `entity_key`, `sequence`,
`event_type`, `game_key`, `universe_id`, `occurred_at`, `payload`).
Responses:

| Status | Meaning | Game should |
|---|---|---|
| `202 {"accepted":true,"duplicate":false}` | stored | mark delivered |
| `202 {"accepted":true,"duplicate":true}` | same `event_id` already stored | mark delivered |
| `400` | body is not a JSON object | dead-letter |
| `401` | no / unknown API key | fix configuration |
| `403` | key's user is not the game's service user | fix configuration |
| `404` | no enabled game for `game_key` | fix configuration |
| `422 {"error","errors":{field: problem}}` | envelope or payload invalid | dead-letter |
| `5xx` / timeout | transient | retry with backoff |

Event types: `user.upsert`, `alliance.upsert`, `membership.active`,
`membership.revoked`, `alliance.archived`, `tutorial.post.upsert`
(payloads in the contract; `Forum\Envelope` is the validator).

## How events are applied

`./run forum process [limit] [game_key]` (cron every 5 minutes) takes due events in
`occurred_at` order and groups them into **lanes**: one per `entity_key`,
except membership events, which lane on the *player* so a `membership.revoked`
for the old alliance and the `membership.active` for the new one — two
entity keys — stay ordered. A lane stops at its first failure, so nothing
later overtakes it.

Each event runs in one database transaction: a failed Flarum call leaves no
half-written link, area or membership (the event is rescheduled instead).
Handlers are upserts keyed on `forum_links` / `forum_areas` /
`forum_memberships`, so re-applying an event is a no-op on Flarum.

| Outcome | `status` | Then |
|---|---|---|
| applied | `done` | — |
| Flarum unreachable / 5xx / 429, or a dependency not yet applied (`membership.active` before its `user.upsert`) | `failed` | retried at `next_attempt_at` (2, 4, 8 … minutes, capped at 6 h); `dead` after 20 attempts |
| Flarum 4xx, or a payload that no longer validates, or a grant into an archived alliance | `dead` | operator reads the error on Forum → Forum events and clicks **Retry** once the cause is fixed |
| no `flarum` connection | `held` | applied automatically once configured |

Per `(game_key, universe_id)` the first event provisions: **Tutorial** (public
tag), **General Discussion** (tag visible to the game/universe *Players*
group, which every `user.upsert` joins) and **Alliances** (private parent
tag). `alliance.upsert` adds a child tag plus a group under it and restricts
the tag to that group; `membership.active` adds the player to the group
(and drops any other alliance group first — one alliance at a time);
`membership.revoked` removes them; `alliance.archived` removes everyone and
hides the tag, keeping the group for moderators; `tutorial.post.upsert`
creates or updates one discussion per slug, authored as the mapped game
user.

## Admin screens

- **Forum → Forum events** (admin, operator): the audit list with status /
  game / type filters and search, one event with its stored envelope and
  last error, and Retry for `failed` / `dead` / `held`.
- **Forum → Forum games** (admin): register a game and its service user.

`./run forum status` prints queue counts per game and status.

## Using the service from another module

```php
if ($this->getDI()->has('forum')) {
    /** @var \Forum\ForumServiceInterface $forum */
    $forum = $this->getDI()->getShared('forum');
    $forum->ingest($envelope, $serviceUserId);     // same rules as the endpoint
}
```

Only `ForumServiceInterface`, `Forum\Envelope`, the `Forum\Exception\*`
classes and `Forum\Adapter\FlarumAdapterInterface` are public.

## Tests

```bash
# pure PHP (no stack): the envelope validator
<app_skeleton>/vendor/bin/phpunit -c <plugins>/forum/phpunit.unit.xml

# contract + HTTP, from the app_skeleton root, once per database
APP_CONFIG_LOCAL=/path/config.sqlite.php vendor/bin/phpunit -c vendor/xtendeploy/forum/phpunit.xml
APP_CONFIG_LOCAL=/path/config.pgsql.php  vendor/bin/phpunit -c vendor/xtendeploy/forum/phpunit.xml
APP_CONFIG_LOCAL=/path/config.mysql.php  vendor/bin/phpunit -c vendor/xtendeploy/forum/phpunit.xml
```

`ForumContractTest` drives the service in-process against the fake adapter
(idempotency, held/retry/dead, rollback, ordering, revoke/restore, archive,
tutorial upsert, link uniqueness). `ForumHttpTest` needs `APP_TEST_BASE_URL`
pointing at a server on the same database and skips otherwise.

## Known gaps (v0.1)

- `FlarumHttpAdapter` is written against Flarum 1.8's JSON:API but has not
  yet run against a live Flarum at the time of this release. Expect to tune: whether `avatarUrl` is writable on PATCH
  (core treats avatars as upload-only), the `/api/permission` group-id
  typing, and the user lookup used to recover from a 422 on create.
- Player `language` is accepted but not pushed to Flarum (no per-user
  locale in the core API).
- The SSO token endpoint (`POST /api/forum/v1/sso/token`) is the agreed
  follow-up, not in this version.
- A retried event may create a duplicate Flarum object if the first attempt
  created it and the transaction then rolled back before the link was
  committed; the HTTP adapter de-duplicates users by username/email, groups
  and tags are not yet de-duplicated by name.
