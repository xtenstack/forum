<?php
declare(strict_types=1);

namespace Forum;

use Forum\Adapter\FakeFlarumAdapter;
use Forum\Adapter\FlarumAdapterInterface;
use Forum\Adapter\FlarumHttpAdapter;
use Forum\Exception\AdapterRejected;
use Forum\Exception\AdapterUnavailable;
use Forum\Exception\DependencyPending;
use Forum\Exception\ForumException;
use Forum\Exception\Unauthorised;
use Forum\Exception\UnknownGame;
use Forum\Exception\ValidationFailed;
use Forum\Models\Area;
use Forum\Models\Event;
use Forum\Models\Game;
use Forum\Models\Link;
use Forum\Models\Membership;
use Phalcon\Di\DiInterface;

/**
 * The `forum` service (contract: plugins/docs/Forum-Service-Interface-v0.1.md).
 *
 * Two halves. ingest() is the synchronous side the API controller calls:
 * authorise the principal for the game, validate the envelope, store it
 * once (event_id is the idempotency key, a replay is a 202 duplicate).
 * processQueue() is the asynchronous side the cron worker calls: apply
 * stored events to the forum platform through the adapter, per lane in
 * occurred_at order, with bounded retry on transient failures and a dead
 * letter on permanent ones.
 *
 * Every handler runs inside one database transaction on the shared `db`
 * adapter, so a failed adapter call leaves no half-written links, areas or
 * memberships behind (the row is rescheduled instead). Handlers are
 * upserts keyed on forum_links / forum_areas / forum_memberships, which is
 * what makes re-applying an event — a seed re-run, an operator retry — a
 * no-op on the platform side.
 *
 * Database portability (contract §"Database portability requirements"):
 * every read and write goes through the Phalcon models (bound parameters,
 * PDO underneath) or the adapter's begin()/commit()/rollback(); nothing
 * here depends on an engine's upsert or locking syntax, and the one race
 * that matters (two deliveries of the same event_id at once) is settled
 * by the unique index, whose violation is read back as "duplicate".
 */
class ForumService implements ForumServiceInterface
{
    public const MAX_ATTEMPTS = 20;

    /** @var string[] base areas provisioned per (game_key, universe_id) */
    private const BASE_AREAS = [Area::KIND_TUTORIAL, Area::KIND_GENERAL, Area::KIND_ALLIANCES];

    private ?FlarumAdapterInterface $adapter = null;

    public function __construct(private DiInterface $di)
    {
    }

    public function supports(string $version): bool
    {
        return version_compare(self::VERSION, $version, '>=')
            && explode('.', self::VERSION)[0] === explode('.', $version)[0];
    }

    // ------------------------------------------------------------ ingest

    public function ingest(array $envelope, int $principalUserId, ?int $apiKeyId = null): array
    {
        $gameKey = isset($envelope['game_key']) && is_string($envelope['game_key']) ? $envelope['game_key'] : '';
        $game    = $gameKey !== '' ? Game::findByKey($gameKey) : null;

        if ($game === null || (int) $game->enabled !== 1) {
            throw new UnknownGame('Unknown game' . ($gameKey !== '' ? ' ' . $gameKey : ''));
        }

        if ($principalUserId < 1 || (int) $game->service_user_id !== $principalUserId) {
            throw new Unauthorised('This API key is not authorised for game ' . $gameKey);
        }

        $parsed = Envelope::fromArray($envelope);

        $existing = Event::findByEventId($parsed->eventId);

        if ($existing !== null) {
            return ['accepted' => true, 'duplicate' => true, 'id' => (int) $existing->id];
        }

        $event                      = new Event();
        $event->event_id            = $parsed->eventId;
        $event->game_key            = $parsed->gameKey;
        $event->universe_id         = $parsed->universeId;
        $event->entity_key          = $parsed->entityKey;
        $event->sequence            = $parsed->sequence;
        $event->event_type          = $parsed->eventType;
        $event->occurred_at         = $parsed->occurredAt;
        $event->envelope            = $parsed->toJson();
        $event->status              = Event::STATUS_PENDING;
        $event->attempts            = 0;
        $event->api_key_id          = $apiKeyId;
        $event->received_by_user_id = $principalUserId;

        try {
            if (!$event->save()) {
                throw new ForumException('Could not store the event: ' . implode('; ', array_map('strval', $event->getMessages())));
            }
        } catch (\PDOException $e) {
            if (self::isUniqueViolation($e)) {
                $existing = Event::findByEventId($parsed->eventId);

                if ($existing !== null) {
                    return ['accepted' => true, 'duplicate' => true, 'id' => (int) $existing->id];
                }
            }

            throw $e;
        }

        $this->fire('event.accepted', ['event_id' => $parsed->eventId, 'game_key' => $parsed->gameKey, 'event_type' => $parsed->eventType]);

        return ['accepted' => true, 'duplicate' => false, 'id' => (int) $event->id];
    }

    // ------------------------------------------------------------ queue

    public function processQueue(int $limit = 100, ?string $gameKey = null): array
    {
        $counts = ['done' => 0, 'failed' => 0, 'dead' => 0, 'held' => 0, 'skipped' => 0];
        $now    = gmdate('Y-m-d H:i:s');

        $conditions = '(status = :p: OR status = :h: OR (status = :f: AND (next_attempt_at IS NULL OR next_attempt_at <= :now:)))';
        $bind       = ['p' => Event::STATUS_PENDING, 'h' => Event::STATUS_HELD, 'f' => Event::STATUS_FAILED, 'now' => $now];

        if ($gameKey !== null) {
            $conditions .= ' AND game_key = :g:';
            $bind['g']   = $gameKey;
        }

        $due = Event::find([
            'conditions' => $conditions,
            'bind'       => $bind,
            'order'      => 'occurred_at ASC, id ASC',
            'limit'      => $limit,
        ]);

        if (!$this->adapter()->isConfigured()) {
            foreach ($due as $event) {
                if ($event->status !== Event::STATUS_HELD) {
                    $event->status     = Event::STATUS_HELD;
                    $event->last_error = 'No forum connection configured (external_connections "flarum")';
                    $event->save();
                }

                $counts['held']++;
            }

            return $counts;
        }

        // Lanes: one ordered list of events per serialisation key; a lane
        // stops at its first failure so nothing later overtakes it. Lanes
        // are visited in order of their earliest event.
        $lanes = [];

        foreach ($due as $event) {
            try {
                $parsed = Envelope::fromStored($event->envelopeArray());
            } catch (ValidationFailed $e) {
                $this->markDead($event, 'Stored envelope no longer validates: ' . $e->getMessage());
                $counts['dead']++;
                continue;
            }

            $lanes[$parsed->gameKey . '|' . $parsed->lane()][] = [$event, $parsed];
        }

        $deferred = [];

        foreach ($lanes as $lane) {
            foreach ($lane as $position => [$event, $parsed]) {
                $outcome = $this->apply($event, $parsed);

                if ($outcome === 'waiting') {
                    // Its provider may be later in this run: carry on.
                    $deferred[] = [$event, $parsed];
                    continue;
                }

                $counts[$outcome]++;

                if ($outcome !== 'done') {
                    $counts['skipped'] += count($lane) - $position - 1;
                    break;
                }
            }
        }

        // One more go for the events that were waiting on a dependency,
        // now that everything else in the run has been applied.
        foreach ($deferred as [$event, $parsed]) {
            $outcome = $counts['done'] > 0 ? $this->apply($event, $parsed) : 'waiting';
            $counts[$outcome === 'waiting' ? 'failed' : $outcome]++;
        }

        return $counts;
    }

    /** @return 'done'|'failed'|'dead'|'waiting' (waiting = rescheduled because a dependency is not applied yet) */
    private function apply(Event $event, Envelope $parsed): string
    {
        $db = $this->db();

        $event->status   = Event::STATUS_PROCESSING;
        $event->attempts = (int) $event->attempts + 1;
        $event->save();

        $db->begin();

        try {
            match ($parsed->eventType) {
                'user.upsert'          => $this->handleUserUpsert($parsed),
                'alliance.upsert'      => $this->handleAllianceUpsert($parsed),
                'membership.active'    => $this->handleMembershipActive($parsed),
                'membership.revoked'   => $this->handleMembershipRevoked($parsed),
                'alliance.archived'    => $this->handleAllianceArchived($parsed),
                'tutorial.post.upsert' => $this->handleTutorialPostUpsert($parsed),
            };

            $event->status          = Event::STATUS_DONE;
            $event->last_error      = null;
            $event->next_attempt_at = null;
            $event->processed_at    = gmdate('Y-m-d H:i:s');
            $event->save();
            $db->commit();

            $this->fire('event.applied', ['event_id' => $parsed->eventId, 'game_key' => $parsed->gameKey, 'event_type' => $parsed->eventType]);

            return 'done';
        } catch (DependencyPending $e) {
            $this->rollback($db);
            $this->reschedule($event, $e->getMessage());

            return (string) $event->status === Event::STATUS_DEAD ? 'dead' : 'waiting';
        } catch (AdapterUnavailable $e) {
            $this->rollback($db);
            $this->reschedule($event, $e->getMessage());

            return (string) $event->status === Event::STATUS_DEAD ? 'dead' : 'failed';
        } catch (AdapterRejected | ValidationFailed $e) {
            $this->rollback($db);
            $this->markDead($event, $e->getMessage());

            return 'dead';
        } catch (\Throwable $e) {
            $this->rollback($db);
            $this->reschedule($event, get_class($e) . ': ' . $e->getMessage());

            return (string) $event->status === Event::STATUS_DEAD ? 'dead' : 'failed';
        }
    }

    private function rollback(\Phalcon\Db\Adapter\AdapterInterface $db): void
    {
        if ($db->isUnderTransaction()) {
            $db->rollback();
        }
    }

    /** Exponential backoff: 2, 4, 8 … minutes, capped at six hours; dead after MAX_ATTEMPTS. */
    private function reschedule(Event $event, string $error): void
    {
        $attempts = (int) $event->attempts;

        if ($attempts >= self::MAX_ATTEMPTS) {
            $this->markDead($event, 'Gave up after ' . $attempts . ' attempts. Last error: ' . $error);

            return;
        }

        $delay = min(6 * 3600, (2 ** min($attempts, 20)) * 60);

        $event->status          = Event::STATUS_FAILED;
        $event->last_error      = mb_substr($error, 0, 2000);
        $event->next_attempt_at = gmdate('Y-m-d H:i:s', time() + $delay);
        $event->save();
    }

    private function markDead(Event $event, string $error): void
    {
        $event->status          = Event::STATUS_DEAD;
        $event->last_error      = mb_substr($error, 0, 2000);
        $event->next_attempt_at = null;
        $event->save();
    }

    public function retry(int $eventId): void
    {
        $event = Event::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $eventId]]);

        if (!$event instanceof Event) {
            throw new ForumException('No event ' . $eventId);
        }

        if ($event->status === Event::STATUS_DONE) {
            throw new ForumException('Event ' . $eventId . ' is already applied');
        }

        $event->status          = Event::STATUS_PENDING;
        $event->attempts        = 0;
        $event->next_attempt_at = null;
        $event->last_error      = null;
        $event->save();
    }

    // ------------------------------------------------------------ handlers

    private function handleUserUpsert(Envelope $e): void
    {
        $u       = $e->subject();
        $areas   = $this->ensureBaseAreas($e->gameKey, $e->universeId);
        $adapter = $this->adapter();
        $fields  = [
            'username'   => (string) $u['username'],
            'email'      => (string) $u['email'],
            'language'   => isset($u['language']) && $u['language'] !== '' ? (string) $u['language'] : null,
            'avatar_url' => $u['profile']['avatar_url'] ?? null,
            'bio'        => $this->bioFor($e->gameKey, $e->universeId, $u),
        ];

        $link = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_USER, (string) $u['game_user_id'], $adapter->name());

        if ($link === null) {
            $providerId = $adapter->createUser($fields);
            $link       = $this->saveLink($e, Link::TYPE_USER, (string) $u['game_user_id'], $providerId, ['username' => $fields['username']]);
        } else {
            $adapter->updateUser((string) $link->provider_id, $fields);
            $link->meta = json_encode(['username' => $fields['username']]);
            $link->save();
        }

        // Every registered player of this game/universe reads General Discussion.
        $adapter->addUserToGroup((string) $link->provider_id, (string) $areas[Area::KIND_GENERAL]->provider_group_id);
    }

    private function handleAllianceUpsert(Envelope $e): void
    {
        $a       = $e->subject();
        $areas   = $this->ensureBaseAreas($e->gameKey, $e->universeId);
        $adapter = $this->adapter();
        $id      = (int) $a['game_alliance_id'];
        $name    = (string) $a['name'];
        $tag     = (string) $a['tag'];
        $area    = Area::lookup($e->gameKey, $e->universeId, Area::KIND_ALLIANCE, $id);

        if ($area === null) {
            $groupId = $adapter->createGroup($this->groupName($e, 'Alliance ' . $tag), $this->groupName($e, 'Alliance ' . $tag . ' members'));
            $tagId   = $adapter->createTag(
                $name . ' [' . $tag . ']',
                $this->slug($e, 'alliance-' . $id . '-' . $tag),
                'Private area of the ' . $name . ' alliance',
                (string) $areas[Area::KIND_ALLIANCES]->provider_tag_id,
                false
            );
            $adapter->setTagAccess($tagId, [$groupId], true);

            $area                    = new Area();
            $area->game_key          = $e->gameKey;
            $area->universe_id       = $e->universeId;
            $area->kind              = Area::KIND_ALLIANCE;
            $area->game_alliance_id  = $id;
            $area->name              = $name;
            $area->tag               = $tag;
            $area->state             = Area::STATE_ACTIVE;
            $area->provider_tag_id   = $tagId;
            $area->provider_group_id = $groupId;
            $this->save($area);

            $this->saveLink($e, Link::TYPE_ALLIANCE_TAG, (string) $id, $tagId);
            $this->saveLink($e, Link::TYPE_ALLIANCE_GRP, (string) $id, $groupId);

            return;
        }

        $changed = $area->name !== $name || $area->tag !== $tag;
        $revived = $area->state !== Area::STATE_ACTIVE;

        if ($changed || $revived) {
            $adapter->updateTag((string) $area->provider_tag_id, [
                'name'        => $name . ' [' . $tag . ']',
                'description' => 'Private area of the ' . $name . ' alliance',
                'hidden'      => false,
            ]);
            $adapter->renameGroup((string) $area->provider_group_id, $this->groupName($e, 'Alliance ' . $tag), $this->groupName($e, 'Alliance ' . $tag . ' members'));

            $area->name        = $name;
            $area->tag         = $tag;
            $area->state       = Area::STATE_ACTIVE;
            $area->archived_at = null;
            $this->save($area);
        }
    }

    private function handleMembershipActive(Envelope $e): void
    {
        $m          = $e->subject();
        $adapter    = $this->adapter();
        $userId     = (int) $m['game_user_id'];
        $allianceId = (int) $m['game_alliance_id'];

        $userLink = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_USER, (string) $userId, $adapter->name());
        $area     = Area::lookup($e->gameKey, $e->universeId, Area::KIND_ALLIANCE, $allianceId);

        if ($userLink === null || $area === null) {
            // The user.upsert / alliance.upsert travels in another lane and
            // may simply not have been applied yet: retry, don't dead-letter.
            throw new DependencyPending(sprintf(
                'Waiting for %s to be applied first',
                $userLink === null ? 'user:' . $e->universeId . ':' . $userId : 'alliance:' . $e->universeId . ':' . $allianceId
            ));
        }

        if ($area->state !== Area::STATE_ACTIVE) {
            throw new AdapterRejected('Alliance ' . $allianceId . ' is archived; membership cannot be granted');
        }

        // One alliance at a time (contract): drop any other active access first.
        foreach (Membership::activeElsewhere($e->gameKey, $e->universeId, $userId, $allianceId) as $other) {
            $otherArea = Area::lookup($e->gameKey, $e->universeId, Area::KIND_ALLIANCE, (int) $other->game_alliance_id);

            if ($otherArea !== null && $otherArea->provider_group_id !== null) {
                $adapter->removeUserFromGroup((string) $userLink->provider_id, (string) $otherArea->provider_group_id);
            }

            $other->state      = Membership::STATE_REVOKED;
            $other->revoked_at = gmdate('Y-m-d H:i:s');
            $this->save($other);
        }

        $adapter->addUserToGroup((string) $userLink->provider_id, (string) $area->provider_group_id);

        $membership = Membership::lookup($e->gameKey, $e->universeId, $userId, $allianceId) ?? new Membership();
        $membership->game_key         = $e->gameKey;
        $membership->universe_id      = $e->universeId;
        $membership->game_user_id     = $userId;
        $membership->game_alliance_id = $allianceId;
        $membership->state            = Membership::STATE_ACTIVE;
        $membership->granted_at       = gmdate('Y-m-d H:i:s');
        $membership->revoked_at       = null;
        $this->save($membership);
    }

    private function handleMembershipRevoked(Envelope $e): void
    {
        $m          = $e->subject();
        $adapter    = $this->adapter();
        $userId     = (int) $m['game_user_id'];
        $allianceId = (int) $m['game_alliance_id'];

        $userLink = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_USER, (string) $userId, $adapter->name());
        $area     = Area::lookup($e->gameKey, $e->universeId, Area::KIND_ALLIANCE, $allianceId);

        if ($userLink !== null && $area !== null && $area->provider_group_id !== null) {
            $adapter->removeUserFromGroup((string) $userLink->provider_id, (string) $area->provider_group_id);
        }

        $membership = Membership::lookup($e->gameKey, $e->universeId, $userId, $allianceId);

        if ($membership === null) {
            // Nothing was ever granted: record the revoke so a later
            // out-of-order grant replay still finds history, then done.
            $membership                   = new Membership();
            $membership->game_key         = $e->gameKey;
            $membership->universe_id      = $e->universeId;
            $membership->game_user_id     = $userId;
            $membership->game_alliance_id = $allianceId;
        }

        $membership->state      = Membership::STATE_REVOKED;
        $membership->revoked_at = gmdate('Y-m-d H:i:s');
        $this->save($membership);
    }

    private function handleAllianceArchived(Envelope $e): void
    {
        $a          = $e->subject();
        $adapter    = $this->adapter();
        $allianceId = (int) $a['game_alliance_id'];
        $area       = Area::lookup($e->gameKey, $e->universeId, Area::KIND_ALLIANCE, $allianceId);

        if ($area === null) {
            // Archiving something never provisioned: nothing to do on the
            // platform; done (an upsert that arrives later recreates it).
            return;
        }

        $members = Membership::find([
            'conditions' => 'game_key = :g: AND universe_id = :u: AND game_alliance_id = :a: AND state = :s:',
            'bind'       => ['g' => $e->gameKey, 'u' => $e->universeId, 'a' => $allianceId, 's' => Membership::STATE_ACTIVE],
        ]);

        foreach ($members as $membership) {
            $userLink = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_USER, (string) $membership->game_user_id, $adapter->name());

            if ($userLink !== null) {
                $adapter->removeUserFromGroup((string) $userLink->provider_id, (string) $area->provider_group_id);
            }

            $membership->state      = Membership::STATE_REVOKED;
            $membership->revoked_at = gmdate('Y-m-d H:i:s');
            $this->save($membership);
        }

        // Hidden tag, group kept: moderators keep the history (contract).
        $adapter->updateTag((string) $area->provider_tag_id, ['hidden' => true]);

        $area->state       = Area::STATE_ARCHIVED;
        $area->archived_at = gmdate('Y-m-d H:i:s');
        $this->save($area);
    }

    private function handleTutorialPostUpsert(Envelope $e): void
    {
        $t       = $e->subject();
        $areas   = $this->ensureBaseAreas($e->gameKey, $e->universeId);
        $adapter = $this->adapter();
        $slug    = (string) $t['slug'];
        $hidden  = ($t['state'] ?? 'published') !== 'published';

        $authorLink = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_USER, (string) $t['author']['game_user_id'], $adapter->name());

        if ($authorLink === null) {
            throw new DependencyPending('Waiting for the author user:' . $e->universeId . ':' . (int) $t['author']['game_user_id'] . ' to be applied first');
        }

        $link = Link::lookup($e->gameKey, $e->universeId, Link::TYPE_TUTORIAL_POST, $slug, $adapter->name());

        if ($link === null) {
            $discussionId = $adapter->createDiscussion((string) $areas[Area::KIND_TUTORIAL]->provider_tag_id, (string) $authorLink->provider_id, (string) $t['title'], (string) $t['body']);

            if ($hidden) {
                $adapter->updateDiscussion($discussionId, (string) $t['title'], (string) $t['body'], true);
            }

            $this->saveLink($e, Link::TYPE_TUTORIAL_POST, $slug, $discussionId, ['title' => $t['title']]);

            return;
        }

        $adapter->updateDiscussion((string) $link->provider_id, (string) $t['title'], (string) $t['body'], $hidden);
        $link->meta = json_encode(['title' => $t['title']]);
        $link->save();
    }

    // ------------------------------------------------------------ areas

    /**
     * Tutorial (public), General Discussion (the game/universe members
     * group) and the private Alliances parent, created once per
     * (game_key, universe_id) and reused.
     *
     * @return array<string, Area> keyed by kind
     */
    private function ensureBaseAreas(string $gameKey, int $universeId): array
    {
        $adapter = $this->adapter();
        $areas   = [];

        foreach (self::BASE_AREAS as $kind) {
            $areas[$kind] = Area::lookup($gameKey, $universeId, $kind, 0);
        }

        if ($areas[Area::KIND_GENERAL] === null) {
            $groupId = $adapter->createGroup($this->groupNameFor($gameKey, $universeId, 'Players'), $this->groupNameFor($gameKey, $universeId, 'Players'));
            $tagId   = $adapter->createTag(
                $this->areaNameFor($gameKey, $universeId, 'General Discussion'),
                $this->slugFor($gameKey, $universeId, 'general'),
                'General discussion for ' . $gameKey . ' universe ' . $universeId,
                null,
                false
            );
            $adapter->setTagAccess($tagId, [$groupId], true);
            $areas[Area::KIND_GENERAL] = $this->saveArea($gameKey, $universeId, Area::KIND_GENERAL, 'General Discussion', $tagId, $groupId);
        }

        if ($areas[Area::KIND_TUTORIAL] === null) {
            $tagId = $adapter->createTag(
                $this->areaNameFor($gameKey, $universeId, 'Tutorial'),
                $this->slugFor($gameKey, $universeId, 'tutorial'),
                'Guides for ' . $gameKey . ' universe ' . $universeId,
                null,
                false
            );
            $adapter->setTagAccess($tagId, null, false);
            $areas[Area::KIND_TUTORIAL] = $this->saveArea($gameKey, $universeId, Area::KIND_TUTORIAL, 'Tutorial', $tagId, null);
        }

        if ($areas[Area::KIND_ALLIANCES] === null) {
            $tagId = $adapter->createTag(
                $this->areaNameFor($gameKey, $universeId, 'Alliances'),
                $this->slugFor($gameKey, $universeId, 'alliances'),
                'Private alliance areas for ' . $gameKey . ' universe ' . $universeId,
                null,
                false
            );
            $adapter->setTagAccess($tagId, [(string) $areas[Area::KIND_GENERAL]->provider_group_id], false);
            $areas[Area::KIND_ALLIANCES] = $this->saveArea($gameKey, $universeId, Area::KIND_ALLIANCES, 'Alliances', $tagId, null);
        }

        return $areas;
    }

    private function saveArea(string $gameKey, int $universeId, string $kind, string $name, string $tagId, ?string $groupId): Area
    {
        $area                    = new Area();
        $area->game_key          = $gameKey;
        $area->universe_id       = $universeId;
        $area->kind              = $kind;
        $area->game_alliance_id  = 0;
        $area->name              = $name;
        $area->state             = Area::STATE_ACTIVE;
        $area->provider_tag_id   = $tagId;
        $area->provider_group_id = $groupId;
        $this->save($area);

        return $area;
    }

    private function saveLink(Envelope $e, string $type, string $gameEntityId, string $providerId, ?array $meta = null): Link
    {
        $link                 = new Link();
        $link->game_key       = $e->gameKey;
        $link->universe_id    = $e->universeId;
        $link->entity_type    = $type;
        $link->game_entity_id = $gameEntityId;
        $link->provider       = $this->adapter()->name();
        $link->provider_id    = $providerId;
        $link->meta           = $meta === null ? null : json_encode($meta);
        $this->save($link);

        return $link;
    }

    private function save(\Phalcon\Mvc\Model $model): void
    {
        if (!$model->save()) {
            throw new ForumException(get_class($model) . ' could not be saved: ' . implode('; ', array_map('strval', $model->getMessages())));
        }
    }

    private function groupName(Envelope $e, string $suffix): string
    {
        return $this->groupNameFor($e->gameKey, $e->universeId, $suffix);
    }

    private function groupNameFor(string $gameKey, int $universeId, string $suffix): string
    {
        return $this->gameLabel($gameKey) . ' U' . $universeId . ' ' . $suffix;
    }

    private function areaNameFor(string $gameKey, int $universeId, string $name): string
    {
        return $name . ' — ' . $this->gameLabel($gameKey) . ' U' . $universeId;
    }

    private function slug(Envelope $e, string $suffix): string
    {
        return $this->slugFor($e->gameKey, $e->universeId, $suffix);
    }

    private function slugFor(string $gameKey, int $universeId, string $suffix): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $gameKey . '-u' . $universeId . '-' . $suffix));

        return trim(substr($slug, 0, 100), '-');
    }

    private function gameLabel(string $gameKey): string
    {
        $game = Game::findByKey($gameKey);

        return $game !== null ? (string) $game->name : $gameKey;
    }

    private function bioFor(string $gameKey, int $universeId, array $u): ?string
    {
        $parts = [$this->gameLabel($gameKey) . ' universe ' . $universeId];

        if (!empty($u['profile']['alliance_tag'])) {
            $parts[] = 'Alliance [' . $u['profile']['alliance_tag'] . ']';
        }

        if (!empty($u['profile']['join_date'])) {
            $parts[] = 'Playing since ' . substr((string) $u['profile']['join_date'], 0, 10);
        }

        return implode(' · ', $parts);
    }

    // ------------------------------------------------------------ games / adapter

    public function registerGame(string $gameKey, string $name, int $serviceUserId, bool $enabled = true): Game
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/', $gameKey) || strlen($gameKey) > 50) {
            throw new ValidationFailed(['game_key' => 'must be lowercase letters, digits and hyphens (max 50)']);
        }

        if (trim($name) === '' || mb_strlen($name) > 150) {
            throw new ValidationFailed(['name' => 'is required (max 150 characters)']);
        }

        $user = \Users::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => $serviceUserId]]);

        if (!$user instanceof \Users) {
            throw new ValidationFailed(['service_user_id' => 'is not a Stack user']);
        }

        $game                  = Game::findByKey($gameKey) ?? new Game();
        $game->game_key        = $gameKey;
        $game->name            = trim($name);
        $game->service_user_id = $serviceUserId;
        $game->enabled         = $enabled ? 1 : 0;
        $this->save($game);

        return $game;
    }

    public function setAdapter(FlarumAdapterInterface $adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * The configured platform adapter: the `flarum` external connection
     * when one exists, the in-memory fake when FORUM_FAKE_ADAPTER=1 (local
     * development), otherwise an unconfigured placeholder that makes
     * processQueue() hold events until an operator configures Flarum.
     */
    public function adapter(): FlarumAdapterInterface
    {
        if ($this->adapter !== null) {
            return $this->adapter;
        }

        $http = FlarumHttpAdapter::fromExternalConnections();

        if ($http->isConfigured()) {
            return $this->adapter = $http;
        }

        return $this->adapter = new FakeFlarumAdapter(getenv('FORUM_FAKE_ADAPTER') === '1');
    }

    private function db(): \Phalcon\Db\Adapter\AdapterInterface
    {
        return $this->di->getShared('db');
    }

    private function fire(string $type, array $data): void
    {
        if ($this->di->has('eventsBus')) {
            $this->di->getShared('eventsBus')->fire('forum:' . $type, $this, $data);
        }
    }

    private static function isUniqueViolation(\PDOException $e): bool
    {
        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return str_starts_with($state, '23') || stripos($e->getMessage(), 'unique') !== false || stripos($e->getMessage(), 'duplicate') !== false;
    }
}
