<?php
declare(strict_types=1);

namespace Forum;

use Forum\Exception\ValidationFailed;

/**
 * A validated event envelope (contract §"Event envelope" and §"Event
 * payloads"). Built from the decoded request body; everything the
 * handlers read later comes through the typed accessors, so a stored
 * envelope that passed here once is safe to re-apply.
 *
 * occurred_at is normalised to 'Y-m-d H:i:s.u' UTC — a fixed-width
 * string that sorts chronologically on every engine and round-trips
 * through TIMESTAMP(6) / DATETIME(6) / TEXT.
 */
final class Envelope
{
    public const MAX_EVENT_ID   = 191;
    public const MAX_ENTITY_KEY = 150;
    public const MAX_SEQUENCE   = 100;

    private function __construct(
        public readonly string $eventId,
        public readonly string $entityKey,
        public readonly string $sequence,
        public readonly string $eventType,
        public readonly string $gameKey,
        public readonly int $universeId,
        public readonly string $occurredAt,
        /** @var array<string, mixed> */
        public readonly array $payload,
        /** @var array<string, mixed> the original body, as received */
        public readonly array $raw,
    ) {
    }

    /**
     * @param array<string, mixed> $data decoded JSON body
     * @throws ValidationFailed
     */
    public static function fromArray(array $data): self
    {
        $errors = [];

        $eventType = self::str($data, 'event_type', $errors, 1, 40);
        $gameKey   = self::str($data, 'game_key', $errors, 1, 50);
        $eventId   = self::str($data, 'event_id', $errors, 1, self::MAX_EVENT_ID);
        $entityKey = self::str($data, 'entity_key', $errors, 1, self::MAX_ENTITY_KEY);
        $sequence  = self::str($data, 'sequence', $errors, 1, self::MAX_SEQUENCE);

        if ($eventType !== null && !in_array($eventType, ForumServiceInterface::EVENT_TYPES, true)) {
            $errors['event_type'] = 'is not a known event type';
        }

        if ($gameKey !== null && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $gameKey)) {
            $errors['game_key'] = 'must be lowercase letters, digits and hyphens';
        }

        $universeId = null;

        if (!array_key_exists('universe_id', $data) || !is_int($data['universe_id']) || $data['universe_id'] < 0) {
            $errors['universe_id'] = 'must be a non-negative integer';
        } else {
            $universeId = $data['universe_id'];
        }

        $occurredAt = null;

        if (!isset($data['occurred_at']) || !is_string($data['occurred_at']) || ($occurredAt = self::normaliseTime($data['occurred_at'])) === null) {
            $errors['occurred_at'] = 'must be an ISO 8601 UTC timestamp';
        }

        if (!isset($data['payload']) || !is_array($data['payload'])) {
            $errors['payload'] = 'must be an object';
        }

        if ($eventId !== null && $eventType !== null && $gameKey !== null && $entityKey !== null && $sequence !== null
            && $eventId !== $gameKey . ':' . $eventType . ':' . $entityKey . ':' . $sequence) {
            $errors['event_id'] = 'must be {game_key}:{event_type}:{entity_key}:{sequence}';
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors, 'Invalid envelope');
        }

        /** @var array<string, mixed> $payload */
        $payload = $data['payload'];
        self::validatePayload((string) $eventType, (string) $gameKey, (int) $universeId, $payload);

        return new self((string) $eventId, (string) $entityKey, (string) $sequence, (string) $eventType, (string) $gameKey, (int) $universeId, (string) $occurredAt, $payload, $data);
    }

    /** Rebuild from a stored forum_events row's envelope (already validated once). */
    public static function fromStored(array $data): self
    {
        return self::fromArray($data);
    }

    public function toJson(): string
    {
        return json_encode($this->raw, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * The serialisation lane: events in one lane are applied strictly in
     * occurred_at order. Membership events lane on the *player*, not the
     * (player, alliance) pair, so a revoke from the old alliance and the
     * grant for the new one — different entity_keys — stay ordered.
     */
    public function lane(): string
    {
        if (str_starts_with($this->eventType, 'membership.')) {
            return 'user:' . $this->universeId . ':' . (int) ($this->payload['membership']['game_user_id'] ?? 0);
        }

        return $this->entityKey;
    }

    /** The payload's one top-level object (user / alliance / membership / tutorial_post). */
    public function subject(): array
    {
        $key = match ($this->eventType) {
            'user.upsert'          => 'user',
            'alliance.upsert', 'alliance.archived' => 'alliance',
            'membership.active', 'membership.revoked' => 'membership',
            'tutorial.post.upsert' => 'tutorial_post',
        };

        return $this->payload[$key];
    }

    /** @param array<string, mixed> $payload */
    private static function validatePayload(string $eventType, string $gameKey, int $universeId, array $payload): void
    {
        $errors = [];

        $need = function (array $node, string $prefix, string $field, string $kind, array &$errors, ?int $max = null, bool $required = true): mixed {
            $path = $prefix . '.' . $field;

            if (!array_key_exists($field, $node) || $node[$field] === null) {
                if ($required) {
                    $errors[$path] = 'is required';
                }

                return null;
            }

            $value = $node[$field];

            switch ($kind) {
                case 'int':
                    if (!is_int($value) || $value < 1) {
                        $errors[$path] = 'must be a positive integer';

                        return null;
                    }

                    return $value;
                case 'string':
                    if (!is_string($value) || trim($value) === '' || ($max !== null && mb_strlen($value) > $max)) {
                        $errors[$path] = 'must be a non-empty string' . ($max !== null ? ' of at most ' . $max . ' characters' : '');

                        return null;
                    }

                    return $value;
                case 'text':
                    if (!is_string($value)) {
                        $errors[$path] = 'must be a string';

                        return null;
                    }

                    return $value;
                default:
                    return $value;
            }
        };

        $scoped = function (array $node, string $prefix, array &$errors) use ($gameKey, $universeId): void {
            if (isset($node['game_key']) && $node['game_key'] !== $gameKey) {
                $errors[$prefix . '.game_key'] = 'does not match the envelope';
            }

            if (isset($node['universe_id']) && $node['universe_id'] !== $universeId) {
                $errors[$prefix . '.universe_id'] = 'does not match the envelope';
            }
        };

        switch ($eventType) {
            case 'user.upsert':
                $u = $payload['user'] ?? null;

                if (!is_array($u)) {
                    $errors['payload.user'] = 'is required';
                    break;
                }

                $scoped($u, 'payload.user', $errors);
                $need($u, 'payload.user', 'game_user_id', 'int', $errors);
                $need($u, 'payload.user', 'username', 'string', $errors, 100);
                $email = $need($u, 'payload.user', 'email', 'string', $errors, 150);

                if (is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors['payload.user.email'] = 'must be a valid email address';
                }

                $need($u, 'payload.user', 'language', 'string', $errors, 10, false);

                if (isset($u['profile']) && !is_array($u['profile'])) {
                    $errors['payload.user.profile'] = 'must be an object';
                }
                break;

            case 'alliance.upsert':
            case 'alliance.archived':
                $a = $payload['alliance'] ?? null;

                if (!is_array($a)) {
                    $errors['payload.alliance'] = 'is required';
                    break;
                }

                $scoped($a, 'payload.alliance', $errors);
                $need($a, 'payload.alliance', 'game_alliance_id', 'int', $errors);

                if ($eventType === 'alliance.upsert') {
                    $need($a, 'payload.alliance', 'name', 'string', $errors, 150);
                    $need($a, 'payload.alliance', 'tag', 'string', $errors, 30);

                    if (isset($a['state']) && $a['state'] !== 'active') {
                        $errors['payload.alliance.state'] = 'must be active for alliance.upsert';
                    }
                } elseif (isset($a['state']) && $a['state'] !== 'archived') {
                    $errors['payload.alliance.state'] = 'must be archived for alliance.archived';
                }
                break;

            case 'membership.active':
            case 'membership.revoked':
                $m = $payload['membership'] ?? null;

                if (!is_array($m)) {
                    $errors['payload.membership'] = 'is required';
                    break;
                }

                $scoped($m, 'payload.membership', $errors);
                $need($m, 'payload.membership', 'game_user_id', 'int', $errors);
                $need($m, 'payload.membership', 'game_alliance_id', 'int', $errors);
                $expected = $eventType === 'membership.active' ? 'active' : 'revoked';

                if (isset($m['state']) && $m['state'] !== $expected) {
                    $errors['payload.membership.state'] = 'must be ' . $expected . ' for ' . $eventType;
                }
                break;

            case 'tutorial.post.upsert':
                $t = $payload['tutorial_post'] ?? null;

                if (!is_array($t)) {
                    $errors['payload.tutorial_post'] = 'is required';
                    break;
                }

                $scoped($t, 'payload.tutorial_post', $errors);

                if (isset($t['area']) && $t['area'] !== 'tutorial') {
                    $errors['payload.tutorial_post.area'] = 'must be tutorial';
                }

                $slug = $need($t, 'payload.tutorial_post', 'slug', 'string', $errors, 100);

                if (is_string($slug) && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) {
                    $errors['payload.tutorial_post.slug'] = 'must be lowercase letters, digits and hyphens';
                }

                $need($t, 'payload.tutorial_post', 'title', 'string', $errors, 200);
                $need($t, 'payload.tutorial_post', 'body', 'text', $errors);

                if (isset($t['format']) && $t['format'] !== 'markdown') {
                    $errors['payload.tutorial_post.format'] = 'must be markdown';
                }

                if (isset($t['state']) && !in_array($t['state'], ['published', 'draft'], true)) {
                    $errors['payload.tutorial_post.state'] = 'must be published or draft';
                }

                if (!isset($t['author']) || !is_array($t['author'])) {
                    $errors['payload.tutorial_post.author'] = 'is required';
                } else {
                    $need($t['author'], 'payload.tutorial_post.author', 'game_user_id', 'int', $errors);
                }
                break;
        }

        if ($errors !== []) {
            throw new ValidationFailed($errors, 'Invalid ' . $eventType . ' payload');
        }
    }

    private static function str(array $data, string $key, array &$errors, int $min, int $max): ?string
    {
        if (!isset($data[$key]) || !is_string($data[$key]) || mb_strlen($data[$key]) < $min || mb_strlen($data[$key]) > $max) {
            $errors[$key] = 'must be a string of ' . $min . ' to ' . $max . ' characters';

            return null;
        }

        return $data[$key];
    }

    /** ISO 8601 (any offset, optional fraction) -> 'Y-m-d H:i:s.u' UTC, or null. */
    public static function normaliseTime(string $value): ?string
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(\.\d{1,9})?(Z|[+-]\d{2}:?\d{2})?$/', $value)) {
            return null;
        }

        // DateTime parses at most 6 fractional digits; trim longer ones.
        $value = preg_replace_callback('/\.(\d{7,9})/', static fn (array $m): string => '.' . substr($m[1], 0, 6), $value);

        try {
            $dt = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }

        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
}
