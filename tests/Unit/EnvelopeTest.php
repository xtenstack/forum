<?php
declare(strict_types=1);

use Forum\Envelope;
use Forum\Exception\ValidationFailed;
use PHPUnit\Framework\TestCase;

final class EnvelopeTest extends TestCase
{
    /** @return array<string, mixed> */
    public static function valid(string $type = 'alliance.upsert', array $payload = null, array $over = []): array
    {
        $payload ??= ['alliance' => ['game_key' => 'xwar-legacy', 'universe_id' => 1, 'game_alliance_id' => 42, 'name' => 'XTen Alliance', 'tag' => 'XTEN', 'state' => 'active']];
        $entity = $type === 'user.upsert' ? 'user:1:1234' : 'alliance:1:42';
        $seq    = '202610261200000000-abc123';

        return array_replace([
            'event_id'    => 'xwar-legacy:' . $type . ':' . $entity . ':' . $seq,
            'entity_key'  => $entity,
            'sequence'    => $seq,
            'event_type'  => $type,
            'game_key'    => 'xwar-legacy',
            'universe_id' => 1,
            'occurred_at' => '2026-10-26T12:00:00.250Z',
            'payload'     => $payload,
        ], $over);
    }

    public function testParsesTheContractExample(): void
    {
        $e = Envelope::fromArray(self::valid());

        self::assertSame('alliance:1:42', $e->entityKey);
        self::assertSame('2026-10-26 12:00:00.250000', $e->occurredAt);
        self::assertSame('alliance:1:42', $e->lane());
        self::assertSame(42, $e->subject()['game_alliance_id']);
    }

    public function testEventIdMustBeComposedFromTheParts(): void
    {
        $this->expectException(ValidationFailed::class);
        Envelope::fromArray(self::valid(over: ['event_id' => 'xwar-legacy:alliance.upsert:alliance:1:43:202610261200000000-abc123']));
    }

    public function testOffsetTimesAreNormalisedToUtc(): void
    {
        $e = Envelope::fromArray(self::valid(over: ['occurred_at' => '2026-10-26T20:00:00+08:00']));

        self::assertSame('2026-10-26 12:00:00.000000', $e->occurredAt);
    }

    public function testRejectsUnknownTypeAndBadUniverse(): void
    {
        try {
            Envelope::fromArray(self::valid(over: ['event_type' => 'user.deleted', 'universe_id' => '1']));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('event_type', $e->getErrors());
            self::assertArrayHasKey('universe_id', $e->getErrors());
        }
    }

    public function testPayloadScopeMustMatchEnvelope(): void
    {
        $payload = ['alliance' => ['game_key' => 'other-game', 'universe_id' => 2, 'game_alliance_id' => 42, 'name' => 'A', 'tag' => 'T']];

        try {
            Envelope::fromArray(self::valid(payload: $payload));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('payload.alliance.game_key', $e->getErrors());
            self::assertArrayHasKey('payload.alliance.universe_id', $e->getErrors());
        }
    }

    public function testMembershipLanesOnThePlayer(): void
    {
        $payload = ['membership' => ['game_key' => 'xwar-legacy', 'universe_id' => 1, 'game_user_id' => 7, 'game_alliance_id' => 42, 'state' => 'revoked']];
        $e       = Envelope::fromArray(self::valid('membership.revoked', $payload, [
            'entity_key' => 'membership:1:7:42',
            'event_id'   => 'xwar-legacy:membership.revoked:membership:1:7:42:202610261200000000-abc123',
        ]));

        self::assertSame('user:1:7', $e->lane());
    }

    public function testUserNeedsAValidEmail(): void
    {
        $payload = ['user' => ['game_key' => 'xwar-legacy', 'universe_id' => 1, 'game_user_id' => 1234, 'username' => 'Commander', 'email' => 'not-an-email', 'language' => 'en']];

        try {
            Envelope::fromArray(self::valid('user.upsert', $payload));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertSame(['payload.user.email'], array_keys($e->getErrors()));
        }
    }

    public function testTutorialPostSlugAndAuthor(): void
    {
        $post = ['tutorial_post' => ['game_key' => 'xwar-legacy', 'universe_id' => 1, 'area' => 'tutorial', 'slug' => 'Getting Started', 'title' => 'T', 'body' => '# b', 'format' => 'markdown', 'state' => 'published', 'author' => ['username' => 'admin']]];

        try {
            Envelope::fromArray(self::valid('tutorial.post.upsert', $post, [
                'entity_key' => 'tutorial:1:getting-started',
                'event_id'   => 'xwar-legacy:tutorial.post.upsert:tutorial:1:getting-started:202610261200000000-abc123',
            ]));
            self::fail('expected ValidationFailed');
        } catch (ValidationFailed $e) {
            self::assertArrayHasKey('payload.tutorial_post.slug', $e->getErrors());
            self::assertArrayHasKey('payload.tutorial_post.author.game_user_id', $e->getErrors());
        }
    }
}
