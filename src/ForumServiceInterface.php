<?php
declare(strict_types=1);

namespace Forum;

use Forum\Adapter\FlarumAdapterInterface;

/**
 * What the `forum` DI service offers (contract: plugins/docs/
 * Forum-Service-Interface-v0.1.md). The HTTP endpoint, the cron worker
 * and the admin screens are thin callers of this; the contract tests
 * drive it in-process on every supported database.
 */
interface ForumServiceInterface
{
    public const VERSION = '0.1.0';

    public const EVENT_TYPES = [
        'user.upsert',
        'alliance.upsert',
        'membership.active',
        'membership.revoked',
        'alliance.archived',
        'tutorial.post.upsert',
    ];

    public function supports(string $version): bool;

    /**
     * Authenticate-then-validate-then-store one event (contract: the
     * endpoint authenticates before parsing). Returns
     * ['accepted' => true, 'duplicate' => bool, 'id' => int].
     *
     * @param array<string, mixed> $envelope the decoded JSON body
     * @param int $principalUserId the bearer API key's user (0 = no principal)
     * @param int|null $apiKeyId   the key, for the audit row
     *
     * @throws Exception\UnknownGame        no enabled game for game_key (404)
     * @throws Exception\Unauthorised       principal is not the game's service user (403)
     * @throws Exception\ValidationFailed   envelope or payload invalid (422)
     */
    public function ingest(array $envelope, int $principalUserId, ?int $apiKeyId = null): array;

    /**
     * Apply queued events to the forum platform, per entity lane in
     * occurred_at order, at most $limit events (one game's only when
     * $gameKey is given). Returns counters
     * ['done' => n, 'failed' => n, 'dead' => n, 'held' => n, 'skipped' => n].
     */
    public function processQueue(int $limit = 100, ?string $gameKey = null): array;

    /** Put a failed/dead/held event back on the queue (admin "Retry"). */
    public function retry(int $eventId): void;

    /** Register (or update) a game and the Stack service user allowed to send for it. */
    public function registerGame(string $gameKey, string $name, int $serviceUserId, bool $enabled = true): Models\Game;

    /** Swap the forum platform adapter (tests use the fake). */
    public function setAdapter(FlarumAdapterInterface $adapter): void;

    public function adapter(): FlarumAdapterInterface;
}
