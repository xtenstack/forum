<?php
declare(strict_types=1);

use Forum\Adapter\FakeFlarumAdapter;
use Forum\Exception\ForumException;
use Forum\Exception\Unauthorised;
use Forum\Exception\UnknownGame;
use Forum\Exception\ValidationFailed;
use Forum\ForumServiceInterface;
use Forum\Models\Area;
use Forum\Models\Event;
use Forum\Models\Game;
use Forum\Models\Link;
use Forum\Models\Membership;
use PHPUnit\Framework\TestCase;

/**
 * The contract suite (Forum-Service-Interface v0.1, "Database portability
 * requirements"): the `forum` service driven in-process against the
 * fake platform adapter on whichever database the instance is configured
 * with — run it on PostgreSQL, MySQL/MariaDB and SQLite. Covers event
 * insertion and duplicate delivery, transaction rollback, retry
 * scheduling, link uniqueness, alliance membership revoke/restore,
 * per-player ordering and the dead letter.
 *
 * Fixtures: one Stack user as the game's service user and one game with a
 * per-run random key; everything is deleted in tearDownAfterClass().
 * Tests run in declaration order and build on each other's state.
 */
final class ForumContractTest extends TestCase
{
    private static ForumServiceInterface $forum;

    private static FakeFlarumAdapter $fake;

    private static string $gameKey;

    private static int $userId;

    private const UNI = 7;

    private static int $seq = 0;

    public static function setUpBeforeClass(): void
    {
        $di          = \Phalcon\Di\Di::getDefault();
        self::$forum = $di->getShared('forum');
        self::$fake  = new FakeFlarumAdapter();
        self::$forum->setAdapter(self::$fake);
        $di->getShared('session')->remove('auth');

        $tag  = bin2hex(random_bytes(4));
        $user = new \Users();
        $user->email         = 'phpunit-forum-' . $tag . '@example.test';
        $user->password_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $user->role_id       = \Roles::idsByNames(['agent'])[0] ?? 2;
        $user->first_name    = 'Forum';
        $user->last_name     = 'Fixture';
        self::assertTrue($user->save(), 'fixture user failed to save: ' . implode('; ', array_map('strval', $user->getMessages())));
        self::$userId  = (int) $user->id;
        self::$gameKey = 'phpunit-' . $tag;

        self::$forum->registerGame(self::$gameKey, 'PHPUnit Game ' . $tag, self::$userId);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([Event::class, Link::class, Area::class, Membership::class] as $class) {
            foreach ($class::find(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]) as $row) {
                $row->delete();
            }
        }

        Game::findByKey(self::$gameKey)?->delete();
        \Phalcon\Di\Di::getDefault()->getShared('db')->execute('DELETE FROM users WHERE id = ?', [self::$userId]);
    }

    // ------------------------------------------------------------ helpers

    private static function envelope(string $type, string $entityKey, array $payload, string $occurredAt = '2026-10-26T12:00:00Z', ?string $sequence = null): array
    {
        $sequence ??= sprintf('%s-%06d', gmdate('YmdHis'), ++self::$seq);

        return [
            'event_id'    => self::$gameKey . ':' . $type . ':' . $entityKey . ':' . $sequence,
            'entity_key'  => $entityKey,
            'sequence'    => $sequence,
            'event_type'  => $type,
            'game_key'    => self::$gameKey,
            'universe_id' => self::UNI,
            'occurred_at' => $occurredAt,
            'payload'     => $payload,
        ];
    }

    private static function scope(array $node): array
    {
        return ['game_key' => self::$gameKey, 'universe_id' => self::UNI] + $node;
    }

    private static function user(int $id, string $name = 'Commander', ?string $allianceTag = null): array
    {
        return self::envelope('user.upsert', 'user:' . self::UNI . ':' . $id, ['user' => self::scope([
            'game_user_id' => $id, 'username' => $name . $id, 'email' => 'player' . $id . '@example.test', 'language' => 'en',
            'profile' => ['avatar_url' => null, 'alliance_tag' => $allianceTag, 'join_date' => '2026-10-22T04:00:00Z'],
        ])]);
    }

    private static function alliance(int $id, string $name, string $tag): array
    {
        return self::envelope('alliance.upsert', 'alliance:' . self::UNI . ':' . $id, ['alliance' => self::scope(['game_alliance_id' => $id, 'name' => $name, 'tag' => $tag, 'state' => 'active'])]);
    }

    private static function membership(int $userId, int $allianceId, bool $active, string $occurredAt = '2026-10-26T12:00:00Z'): array
    {
        return self::envelope(
            $active ? 'membership.active' : 'membership.revoked',
            'membership:' . self::UNI . ':' . $userId . ':' . $allianceId,
            ['membership' => self::scope(['game_user_id' => $userId, 'game_alliance_id' => $allianceId, 'state' => $active ? 'active' : 'revoked'])],
            $occurredAt
        );
    }

    private static function ingest(array $envelope): array
    {
        return self::$forum->ingest($envelope, self::$userId);
    }

    /** This game's lane only: the instance's queue may hold other games' events. */
    private static function process(): array
    {
        return self::$forum->processQueue(100, self::$gameKey);
    }

    private static function event(string $eventId): Event
    {
        $event = Event::findByEventId($eventId);
        self::assertNotNull($event, 'event ' . $eventId . ' not stored');

        return $event;
    }

    private static function dueNow(Event $event): void
    {
        $event->next_attempt_at = null;
        $event->save();
    }

    private static function userLink(int $gameUserId): ?Link
    {
        return Link::lookup(self::$gameKey, self::UNI, Link::TYPE_USER, (string) $gameUserId, 'fake');
    }

    private static function area(int $allianceId): ?Area
    {
        return Area::lookup(self::$gameKey, self::UNI, Area::KIND_ALLIANCE, $allianceId);
    }

    private static function callNames(): array
    {
        return array_map(static fn (array $c): string => $c[0] . '(' . implode(',', array_map(static fn ($a) => is_scalar($a) ? (string) $a : '…', $c[1])) . ')', self::$fake->calls);
    }

    // ------------------------------------------------------------ tests

    public function testSupportsTheContractVersion(): void
    {
        self::assertTrue(self::$forum->supports('0.1'));
        self::assertFalse(self::$forum->supports('1.0'));
    }

    public function testUnknownGameIsRefusedBeforeValidation(): void
    {
        $this->expectException(UnknownGame::class);
        self::$forum->ingest(['game_key' => 'no-such-game', 'event_id' => 'garbage'], self::$userId);
    }

    public function testAnotherPrincipalIsRefused(): void
    {
        $this->expectException(Unauthorised::class);
        self::$forum->ingest(self::alliance(42, 'XTen Alliance', 'XTEN'), self::$userId + 1000000);
    }

    public function testInvalidEnvelopeIsRefusedAndNotStored(): void
    {
        $bad = self::alliance(42, 'XTen Alliance', 'XTEN');
        unset($bad['payload']['alliance']['tag']);

        try {
            self::ingest($bad);
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('payload.alliance.tag', $e->getErrors());
        }

        self::assertSame(0, Event::count(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]));
    }

    public function testAnEventIsStoredOnceAndReplayIsADuplicate(): void
    {
        $envelope = self::alliance(42, 'XTen Alliance', 'XTEN');

        $first  = self::ingest($envelope);
        $second = self::ingest($envelope);

        self::assertSame(['accepted' => true, 'duplicate' => false], array_intersect_key($first, ['accepted' => 1, 'duplicate' => 1]));
        self::assertSame(['accepted' => true, 'duplicate' => true], array_intersect_key($second, ['accepted' => 1, 'duplicate' => 1]));
        self::assertSame($first['id'], $second['id']);
        self::assertSame(1, Event::count(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]));

        $event = self::event($envelope['event_id']);
        self::assertSame(Event::STATUS_PENDING, $event->status);
        self::assertSame(self::$userId, (int) $event->received_by_user_id);
        self::assertSame($envelope['payload'], $event->envelopeArray()['payload']);
    }

    public function testEventsAreHeldWhileNoForumIsConfigured(): void
    {
        self::$forum->setAdapter(new FakeFlarumAdapter(false));

        try {
            $counts = self::process();
        } finally {
            self::$forum->setAdapter(self::$fake);
        }

        self::assertSame(1, $counts['held']);
        self::assertSame(Event::STATUS_HELD, Event::find(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]])->getFirst()->status);
    }

    public function testUserAllianceAndMembershipAreAppliedInOrder(): void
    {
        self::ingest(self::user(1234, 'Commander', 'XTEN'));
        self::ingest(self::membership(1234, 42, true, '2026-10-26T12:00:02Z'));

        $counts = self::process();

        self::assertSame(3, $counts['done'], 'held alliance + user + membership: ' . json_encode($counts));
        self::assertSame(0, $counts['failed'] + $counts['dead']);

        // Base areas once, plus the alliance area.
        self::assertSame(4, Area::count(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]));
        $tutorial = Area::lookup(self::$gameKey, self::UNI, Area::KIND_TUTORIAL);
        $general  = Area::lookup(self::$gameKey, self::UNI, Area::KIND_GENERAL);
        $alliance = self::area(42);
        self::assertNotNull($tutorial);
        self::assertNotNull($general);
        self::assertNotNull($alliance);
        self::assertNull(self::$fake->tagAccess[$tutorial->provider_tag_id], 'tutorial is public');
        self::assertSame([$general->provider_group_id], self::$fake->tagAccess[$general->provider_tag_id]);
        self::assertSame([$alliance->provider_group_id], self::$fake->tagAccess[$alliance->provider_tag_id]);
        self::assertSame($alliance->provider_tag_id, array_key_last(array_filter(self::$fake->tags, static fn (array $t): bool => $t['parent'] !== null)));

        $link = self::userLink(1234);
        self::assertNotNull($link);
        self::assertSame('Commander1234', self::$fake->users[$link->provider_id]['username']);
        self::assertEqualsCanonicalizing([$general->provider_group_id, $alliance->provider_group_id], self::$fake->memberships[$link->provider_id]);
        self::assertTrue(self::$fake->userCanSee($link->provider_id, $alliance->provider_tag_id));

        $membership = Membership::lookup(self::$gameKey, self::UNI, 1234, 42);
        self::assertSame(Membership::STATE_ACTIVE, $membership->state);
        self::assertNotNull($membership->granted_at);
    }

    public function testReplayingAnAppliedEventDoesNothing(): void
    {
        $calls = count(self::$fake->calls);
        $event = Event::find(['conditions' => 'game_key = :g: AND event_type = :t:', 'bind' => ['g' => self::$gameKey, 't' => 'user.upsert']])->getFirst();

        $result = self::ingest($event->envelopeArray());
        self::process();

        self::assertTrue($result['duplicate']);
        self::assertCount($calls, self::$fake->calls, 'a duplicate must not reach the platform again');

        $this->expectException(ForumException::class);
        self::$forum->retry((int) $event->id);
    }

    public function testAnAllianceChangeAppliesTheRevokeBeforeTheGrantByOccurredAt(): void
    {
        self::ingest(self::alliance(43, 'Second Alliance', 'SECD'));
        // Arrives first, happened later:
        $grant = self::membership(1234, 43, true, '2026-10-26T12:10:05Z');
        self::ingest($grant);
        // Arrives second, happened earlier:
        $revoke = self::membership(1234, 42, false, '2026-10-26T12:10:01Z');
        self::ingest($revoke);

        $before = count(self::$fake->calls);
        $counts = self::process();
        self::assertSame(3, $counts['done'], json_encode($counts));

        $names   = array_slice(self::callNames(), $before);
        $removed = array_search('removeUserFromGroup(' . self::userLink(1234)->provider_id . ',' . self::area(42)->provider_group_id . ')', $names, true);
        $added   = array_search('addUserToGroup(' . self::userLink(1234)->provider_id . ',' . self::area(43)->provider_group_id . ')', $names, true);
        self::assertNotFalse($removed, implode("\n", $names));
        self::assertNotFalse($added, implode("\n", $names));
        self::assertLessThan($added, $removed, 'revoke of the old alliance must be applied before the grant of the new one');

        self::assertSame(Membership::STATE_REVOKED, Membership::lookup(self::$gameKey, self::UNI, 1234, 42)->state);
        self::assertSame(Membership::STATE_ACTIVE, Membership::lookup(self::$gameKey, self::UNI, 1234, 43)->state);
        self::assertFalse(self::$fake->userCanSee(self::userLink(1234)->provider_id, self::area(42)->provider_tag_id));
        self::assertTrue(self::$fake->userCanSee(self::userLink(1234)->provider_id, self::area(43)->provider_tag_id));
    }

    public function testRevokeThenRestoreOnTheSameAlliance(): void
    {
        self::ingest(self::membership(1234, 43, false, '2026-10-26T12:20:00Z'));
        self::ingest(self::membership(1234, 43, true, '2026-10-26T12:20:30Z'));

        self::assertSame(2, self::process()['done']);

        $membership = Membership::lookup(self::$gameKey, self::UNI, 1234, 43);
        self::assertSame(Membership::STATE_ACTIVE, $membership->state);
        self::assertNull($membership->revoked_at);
        self::assertTrue(self::$fake->userCanSee(self::userLink(1234)->provider_id, self::area(43)->provider_tag_id));
    }

    public function testATransientFailureRollsBackAndReschedules(): void
    {
        $envelope = self::user(1235, 'Pilot');
        self::ingest($envelope);
        // createUser succeeds, the link row is written, then addUserToGroup fails.
        self::$fake->failAfter(1, new \Forum\Exception\AdapterUnavailable('simulated outage'));

        $counts = self::process();

        self::assertSame(1, $counts['failed'], json_encode($counts));
        $event = self::event($envelope['event_id']);
        self::assertSame(Event::STATUS_FAILED, $event->status);
        self::assertSame(1, (int) $event->attempts);
        self::assertNotNull($event->next_attempt_at);
        self::assertStringContainsString('simulated outage', (string) $event->last_error);
        self::assertNull(self::userLink(1235), 'the link written before the failure must be rolled back');

        // Not due yet: nothing happens.
        self::assertSame(0, self::process()['done'] + self::process()['failed']);

        self::dueNow($event);
        self::assertSame(1, self::process()['done']);
        self::assertNotNull(self::userLink(1235));
        self::assertSame(Event::STATUS_DONE, self::event($envelope['event_id'])->status);
        self::assertNull(self::event($envelope['event_id'])->last_error);
    }

    public function testAPermanentRejectionIsDeadUntilAnOperatorRetries(): void
    {
        $envelope = self::alliance(44, 'Doomed Alliance', 'DOOM');
        self::ingest($envelope);
        self::$fake->failNextPermanent('simulated rejection');

        self::assertSame(1, self::process()['dead']);
        self::assertSame(Event::STATUS_DEAD, self::event($envelope['event_id'])->status);
        self::assertNull(self::area(44));

        self::$forum->retry((int) self::event($envelope['event_id'])->id);
        self::assertSame(Event::STATUS_PENDING, self::event($envelope['event_id'])->status);
        self::assertSame(1, self::process()['done']);
        self::assertNotNull(self::area(44));
    }

    public function testAMembershipWaitsForItsUserInsteadOfDying(): void
    {
        $envelope = self::membership(9999, 44, true);
        self::ingest($envelope);

        self::assertSame(1, self::process()['failed']);
        $event = self::event($envelope['event_id']);
        self::assertSame(Event::STATUS_FAILED, $event->status);
        self::assertStringContainsString('Waiting for user:' . self::UNI . ':9999', (string) $event->last_error);

        self::ingest(self::user(9999, 'Late'));
        self::dueNow($event);
        // The membership sorts first in the player's lane (same occurred_at,
        // lower id) and waits again; the worker applies the user and then
        // re-tries the waiting event in the same run.
        self::assertSame(2, self::process()['done']);
        self::assertSame(Membership::STATE_ACTIVE, Membership::lookup(self::$gameKey, self::UNI, 9999, 44)->state);
    }

    public function testArchivingAnAllianceRevokesEveryMemberAndHidesTheArea(): void
    {
        self::ingest(self::user(1236, 'Third'));
        self::ingest(self::membership(1236, 44, true, '2026-10-26T12:30:00Z'));
        self::assertSame(2, self::process()['done']);

        self::ingest(self::envelope('alliance.archived', 'alliance:' . self::UNI . ':44', ['alliance' => self::scope(['game_alliance_id' => 44, 'state' => 'archived'])], '2026-10-26T12:31:00Z'));
        self::assertSame(1, self::process()['done']);

        $area = self::area(44);
        self::assertSame(Area::STATE_ARCHIVED, $area->state);
        self::assertTrue(self::$fake->tags[$area->provider_tag_id]['hidden']);
        self::assertArrayHasKey($area->provider_group_id, self::$fake->groups, 'the group stays for moderators');

        foreach ([1236, 9999] as $player) {
            self::assertSame(Membership::STATE_REVOKED, Membership::lookup(self::$gameKey, self::UNI, $player, 44)->state);
            self::assertFalse(self::$fake->userCanSee(self::userLink($player)->provider_id, $area->provider_tag_id));
        }

        // Granting into an archived alliance is a permanent error.
        $late = self::membership(1236, 44, true, '2026-10-26T12:32:00Z');
        self::ingest($late);
        self::assertSame(1, self::process()['dead']);
        self::assertStringContainsString('archived', (string) self::event($late['event_id'])->last_error);

        // A new upsert revives it.
        self::ingest(self::alliance(44, 'Reborn Alliance', 'BORN'));
        self::assertSame(1, self::process()['done']);
        self::assertSame(Area::STATE_ACTIVE, self::area(44)->state);
        self::assertFalse(self::$fake->tags[$area->provider_tag_id]['hidden']);
        self::assertSame('Reborn Alliance [BORN]', self::$fake->tags[$area->provider_tag_id]['name']);
    }

    public function testTutorialPostsAreUpsertedBySlug(): void
    {
        $post = static fn (string $title): array => ['tutorial_post' => self::scope([
            'area' => 'tutorial', 'slug' => 'getting-started', 'title' => $title, 'body' => "# Getting started\n\nBuild a mine.", 'format' => 'markdown', 'state' => 'published',
            'author' => ['game_user_id' => 1234, 'username' => 'Commander1234'],
        ])];

        self::ingest(self::envelope('tutorial.post.upsert', 'tutorial:' . self::UNI . ':getting-started', $post('Getting started'), '2026-10-26T13:00:00Z'));
        self::assertSame(1, self::process()['done']);

        $link = Link::lookup(self::$gameKey, self::UNI, Link::TYPE_TUTORIAL_POST, 'getting-started', 'fake');
        self::assertNotNull($link);
        $tutorial = Area::lookup(self::$gameKey, self::UNI, Area::KIND_TUTORIAL);
        self::assertSame($tutorial->provider_tag_id, self::$fake->discussions[$link->provider_id]['tag']);
        self::assertSame(self::userLink(1234)->provider_id, self::$fake->discussions[$link->provider_id]['author']);

        self::ingest(self::envelope('tutorial.post.upsert', 'tutorial:' . self::UNI . ':getting-started', $post('Getting started: your first ten minutes'), '2026-10-26T13:05:00Z'));
        self::assertSame(1, self::process()['done']);
        self::assertCount(1, self::$fake->discussions);
        self::assertSame('Getting started: your first ten minutes', self::$fake->discussions[$link->provider_id]['title']);
    }

    public function testLinkUniquenessIsEnforcedByTheDatabase(): void
    {
        $existing = self::userLink(1234);
        $copy     = new Link();

        foreach (['game_key', 'universe_id', 'entity_type', 'game_entity_id', 'provider'] as $field) {
            $copy->$field = $existing->$field;
        }

        $copy->provider_id = 'u-duplicate';

        try {
            $saved = $copy->save();
        } catch (\PDOException $e) {
            $saved = false;
            self::assertMatchesRegularExpression('/unique|duplicate/i', $e->getMessage());
        }

        self::assertFalse($saved, 'a second link for the same game identity/provider must be refused');
        self::assertSame(1, Link::count(['conditions' => 'game_key = :g: AND entity_type = :t: AND game_entity_id = :i:', 'bind' => ['g' => self::$gameKey, 't' => Link::TYPE_USER, 'i' => '1234']]));
    }

    public function testEveryStoredEventEndedAppliedOrDeadOnPurpose(): void
    {
        $statuses = [];

        foreach (Event::find(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]) as $event) {
            $statuses[$event->status] = ($statuses[$event->status] ?? 0) + 1;
        }

        self::assertSame(['done', 'dead'], array_keys(array_filter($statuses)), json_encode($statuses));
        self::assertSame(1, $statuses['dead'], 'the archived-alliance grant and nothing else: ' . json_encode($statuses));
    }
}
