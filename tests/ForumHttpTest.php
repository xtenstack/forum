<?php
declare(strict_types=1);

use Forum\Models\Event;
use Forum\Models\Game;
use PHPUnit\Framework\TestCase;

/**
 * POST /api/forum/v1/events over real HTTP (contract §"Event endpoint"):
 * the status codes and the {accepted, duplicate} shape, identical on
 * every database. Needs a running instance of this same database
 * (APP_TEST_BASE_URL); skips otherwise.
 */
final class ForumHttpTest extends TestCase
{
    private static HttpClient $client;

    private static string $gameKey;

    private static array $userIds = [];

    private static array $keyIds = [];

    private static string $goodToken;

    private static string $otherToken;

    public static function setUpBeforeClass(): void
    {
        if (!getenv('APP_TEST_BASE_URL')) {
            self::markTestSkipped('APP_TEST_BASE_URL not set');
        }

        self::$client  = new HttpClient();
        $tag           = bin2hex(random_bytes(4));
        self::$gameKey = 'phpunit-http-' . $tag;

        [$serviceUserId, self::$goodToken] = self::userWithKey('service-' . $tag);
        [, self::$otherToken]              = self::userWithKey('other-' . $tag);

        \Phalcon\Di\Di::getDefault()->getShared('forum')->registerGame(self::$gameKey, 'HTTP Game ' . $tag, $serviceUserId);
    }

    public static function tearDownAfterClass(): void
    {
        if (!isset(self::$gameKey)) {
            return;
        }

        foreach (Event::find(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]) as $row) {
            $row->delete();
        }

        Game::findByKey(self::$gameKey)?->delete();

        $db = \Phalcon\Di\Di::getDefault()->getShared('db');

        foreach (self::$keyIds as $id) {
            $db->execute('DELETE FROM api_keys WHERE id = ?', [$id]);
        }

        foreach (self::$userIds as $id) {
            $db->execute('DELETE FROM users WHERE id = ?', [$id]);
        }
    }

    /** @return array{0: int, 1: string} user id, raw token */
    private static function userWithKey(string $label): array
    {
        $user                = new \Users();
        $user->email         = 'phpunit-forum-' . $label . '@example.test';
        $user->password_hash = password_hash(bin2hex(random_bytes(8)), PASSWORD_DEFAULT);
        $user->role_id       = \Roles::idsByNames(['agent'])[0] ?? 2;
        $user->first_name    = 'Forum';
        $user->last_name     = $label;
        self::assertTrue($user->save(), implode('; ', array_map('strval', $user->getMessages())));
        self::$userIds[] = (int) $user->id;

        $token              = 'sk_phpunit_' . bin2hex(random_bytes(16));
        $key                = new \ApiKeys();
        $key->user_id       = (int) $user->id;
        $key->name          = 'phpunit-forum-' . $label;
        $key->token_hash    = hash('sha256', $token);
        $key->token_prefix  = substr($token, 0, 10);
        self::assertTrue($key->save(), implode('; ', array_map('strval', $key->getMessages())));
        self::$keyIds[] = (int) $key->id;

        return [(int) $user->id, $token];
    }

    private static function post(array $body, ?string $token): array
    {
        $headers  = $token === null ? [] : ['Authorization: Bearer ' . $token];
        $response = self::$client->postJson('/api/forum/v1/events', $body, $headers);

        return [$response['status'], json_decode($response['body'], true)];
    }

    private static function envelope(string $sequence = 'seq-1'): array
    {
        return [
            'event_id'    => self::$gameKey . ':alliance.upsert:alliance:1:42:' . $sequence,
            'entity_key'  => 'alliance:1:42',
            'sequence'    => $sequence,
            'event_type'  => 'alliance.upsert',
            'game_key'    => self::$gameKey,
            'universe_id' => 1,
            'occurred_at' => '2026-10-26T12:00:00Z',
            'payload'     => ['alliance' => ['game_key' => self::$gameKey, 'universe_id' => 1, 'game_alliance_id' => 42, 'name' => 'XTen Alliance', 'tag' => 'XTEN', 'state' => 'active']],
        ];
    }

    public function testNoCredentialIs401(): void
    {
        [$status] = self::post(self::envelope(), null);
        self::assertSame(401, $status);
    }

    public function testMalformedBodyIs400(): void
    {
        $response = self::$client->postJson('/api/forum/v1/events', [], ['Authorization: Bearer ' . self::$goodToken]);
        self::assertSame(400, $response['status'], $response['body']);
    }

    public function testUnknownGameIs404(): void
    {
        [$status, $json] = self::post(['game_key' => 'no-such-game'] + self::envelope(), self::$goodToken);
        self::assertSame(404, $status, json_encode($json));
    }

    public function testAnotherUsersKeyIs403(): void
    {
        [$status] = self::post(self::envelope(), self::$otherToken);
        self::assertSame(403, $status);
    }

    public function testInvalidPayloadIs422WithFieldErrors(): void
    {
        $bad = self::envelope();
        unset($bad['payload']['alliance']['name']);
        [$status, $json] = self::post($bad, self::$goodToken);

        self::assertSame(422, $status, json_encode($json));
        self::assertArrayHasKey('payload.alliance.name', $json['errors']);
    }

    public function testAcceptedThenDuplicateAre202(): void
    {
        [$status, $json] = self::post(self::envelope('seq-http'), self::$goodToken);
        self::assertSame(202, $status, json_encode($json));
        self::assertSame(['accepted' => true, 'duplicate' => false], $json);

        [$status, $json] = self::post(self::envelope('seq-http'), self::$goodToken);
        self::assertSame(202, $status, json_encode($json));
        self::assertSame(['accepted' => true, 'duplicate' => true], $json);

        self::assertSame(1, Event::count(['conditions' => 'game_key = :g:', 'bind' => ['g' => self::$gameKey]]));
    }
}
