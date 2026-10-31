# Changelog

## 0.1.1 — 2026-10-31

First public release, MIT, in its own repository (`xtenstack/forum`): `LICENSE`,
`NOTICE`, README install line and `"license": "MIT"` in `composer.json`;
`"license": {"keyRequired": false}` in `module.json`. Example values in the
game form and the CLI comment are now neutral. No behaviour change from 0.1.0.

## 0.1.0 — 2026-10-26

- First version of the Forum plugin (Forum-Service-Interface v0.1):
  `POST /api/forum/v1/events` with per-game API-key authorisation, the
  idempotent `forum_events` audit queue, lane-ordered delivery with bounded
  retry and a dead letter, handlers for `user.upsert`, `alliance.upsert`,
  `membership.active`, `membership.revoked`, `alliance.archived` and
  `tutorial.post.upsert`, the Flarum JSON:API adapter and an in-memory fake,
  `./run forum process|status|game`, the admin event audit and game
  registry, migrations for PostgreSQL, MySQL/MariaDB and SQLite, and the
  contract, HTTP and unit suites.
