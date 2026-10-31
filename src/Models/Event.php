<?php
declare(strict_types=1);

namespace Forum\Models;

/** forum_events: the idempotent audit queue (contract §"Event endpoint"). */
class Event extends ModelBase
{
    public const STATUS_PENDING    = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_DONE       = 'done';
    public const STATUS_FAILED     = 'failed';
    public const STATUS_DEAD       = 'dead';
    public const STATUS_HELD       = 'held';

    public const STATUSES = [
        self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_DONE,
        self::STATUS_FAILED, self::STATUS_DEAD, self::STATUS_HELD,
    ];

    public $event_id;
    public $game_key;
    public $universe_id;
    public $entity_key;
    public $sequence;
    public $event_type;
    public $occurred_at;
    public $envelope;
    public $status;
    public $attempts;
    public $next_attempt_at;
    public $last_error;
    public $processed_at;
    public $api_key_id;
    public $received_by_user_id;

    protected function tableName(): string
    {
        return 'forum_events';
    }

    public static function findByEventId(string $eventId): ?self
    {
        $event = self::findFirst(['conditions' => 'event_id = :e:', 'bind' => ['e' => $eventId]]);

        return $event instanceof self ? $event : null;
    }

    /** @return array<string, mixed> the stored envelope, decoded */
    public function envelopeArray(): array
    {
        $decoded = json_decode((string) $this->envelope, true);

        return is_array($decoded) ? $decoded : [];
    }
}
