-- Forum plugin v0.1 (plugins/docs/Forum-Service-Interface-v0.1.md): the
-- service boundary between XTen games and the shared Flarum community.
--
-- Every table lives in the default schema with a forum_ prefix because the
-- same plugin must run on MySQL/MariaDB and SQLite, which have no schemas;
-- mysql/001_forum.sql and sqlite/001_forum.sql mirror this file column for
-- column (the contract's "three parallel migration dirs" release gate).
--
-- forum_games      — which game_key a Stack service user may send events
--                    for. The bearer API key's user must equal
--                    service_user_id (ForumService::authorise()).
-- forum_events     — the idempotent audit queue. event_id is the unique
--                    idempotency key ({game_key}:{event_type}:{entity_key}:
--                    {sequence}); the full envelope is kept as JSON text so
--                    an event can be re-applied after an adapter fix.
--                    status: pending | processing | done | failed (retry
--                    scheduled, next_attempt_at) | dead (gave up or the
--                    payload is permanently invalid) | held (no Flarum
--                    connection configured yet).
-- forum_links      — stable game identity -> provider (flarum) id, unique
--                    per identity/provider. Handlers are upserts keyed on
--                    this, which is what makes a replayed event a no-op.
-- forum_areas      — the provisioned areas per (game_key, universe_id):
--                    tutorial, general, alliances (parent) and one
--                    `alliance` row per game alliance (game_alliance_id > 0;
--                    0 for the three fixed areas so the unique key works on
--                    every engine without COALESCE).
-- forum_memberships — current alliance access per player, revoke/restore
--                    history kept on the one row.
--
-- occurred_at is the game's UTC emission time with microseconds; events for
-- one entity_key are applied in (occurred_at, id) order so a later
-- membership.active can never overtake an earlier membership.revoked.
-- created_at/updated_at are written from PHP (Forum\Models\ModelBase) so
-- the values match across engines.

CREATE TABLE forum_games (
    id               SERIAL PRIMARY KEY,
    game_key         VARCHAR(50) NOT NULL UNIQUE,
    name             VARCHAR(150) NOT NULL,
    service_user_id  INTEGER NOT NULL,
    enabled          SMALLINT NOT NULL DEFAULT 1,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (service_user_id) REFERENCES users(id)
);

CREATE TABLE forum_events (
    id                   SERIAL PRIMARY KEY,
    event_id             VARCHAR(191) NOT NULL UNIQUE,
    game_key             VARCHAR(50) NOT NULL,
    universe_id          INTEGER NOT NULL,
    entity_key           VARCHAR(150) NOT NULL,
    sequence             VARCHAR(100) NOT NULL,
    event_type           VARCHAR(40) NOT NULL,
    occurred_at          TIMESTAMP(6) NOT NULL,
    envelope             TEXT NOT NULL,
    status               VARCHAR(16) NOT NULL DEFAULT 'pending',
    attempts             INTEGER NOT NULL DEFAULT 0,
    next_attempt_at      TIMESTAMP,
    last_error           TEXT,
    processed_at         TIMESTAMP,
    api_key_id           INTEGER,
    received_by_user_id  INTEGER,
    created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX forum_events_entity ON forum_events (game_key, universe_id, entity_key, occurred_at, id);
CREATE INDEX forum_events_queue ON forum_events (status, next_attempt_at);
CREATE INDEX forum_events_type ON forum_events (event_type, created_at);

CREATE TABLE forum_links (
    id               SERIAL PRIMARY KEY,
    game_key         VARCHAR(50) NOT NULL,
    universe_id      INTEGER NOT NULL,
    entity_type      VARCHAR(30) NOT NULL,
    game_entity_id   VARCHAR(100) NOT NULL,
    provider         VARCHAR(30) NOT NULL DEFAULT 'flarum',
    provider_id      VARCHAR(100) NOT NULL,
    meta             TEXT,
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (game_key, universe_id, entity_type, game_entity_id, provider)
);

CREATE INDEX forum_links_provider ON forum_links (provider, entity_type, provider_id);

CREATE TABLE forum_areas (
    id                 SERIAL PRIMARY KEY,
    game_key           VARCHAR(50) NOT NULL,
    universe_id        INTEGER NOT NULL,
    kind               VARCHAR(20) NOT NULL,
    game_alliance_id   INTEGER NOT NULL DEFAULT 0,
    name               VARCHAR(150) NOT NULL,
    tag                VARCHAR(30),
    state              VARCHAR(16) NOT NULL DEFAULT 'active',
    provider_tag_id    VARCHAR(100),
    provider_group_id  VARCHAR(100),
    archived_at        TIMESTAMP,
    created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (game_key, universe_id, kind, game_alliance_id)
);

CREATE TABLE forum_memberships (
    id                SERIAL PRIMARY KEY,
    game_key          VARCHAR(50) NOT NULL,
    universe_id       INTEGER NOT NULL,
    game_user_id      INTEGER NOT NULL,
    game_alliance_id  INTEGER NOT NULL,
    state             VARCHAR(16) NOT NULL DEFAULT 'active',
    granted_at        TIMESTAMP,
    revoked_at        TIMESTAMP,
    created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (game_key, universe_id, game_user_id, game_alliance_id)
);

CREATE INDEX forum_memberships_user ON forum_memberships (game_key, universe_id, game_user_id, state);

-- One cron row for the queue worker (./run forum process), seeded here the
-- way the announcements plugin seeds its poller: SeedTask only knows the
-- base engine's own jobs. Idempotent on re-run.
INSERT INTO cron_jobs (name, task, task_action, frequency, enabled)
SELECT 'Forum: deliver queued game events to Flarum', 'forum', 'process', '+5 minutes', 1
WHERE NOT EXISTS (SELECT 1 FROM cron_jobs WHERE task = 'forum');
