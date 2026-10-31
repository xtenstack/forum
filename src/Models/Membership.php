<?php
declare(strict_types=1);

namespace Forum\Models;

/** forum_memberships: a player's access to one alliance area, with revoke/restore history on the row. */
class Membership extends ModelBase
{
    public const STATE_ACTIVE  = 'active';
    public const STATE_REVOKED = 'revoked';

    public $game_key;
    public $universe_id;
    public $game_user_id;
    public $game_alliance_id;
    public $state;
    public $granted_at;
    public $revoked_at;

    protected function tableName(): string
    {
        return 'forum_memberships';
    }

    public static function lookup(string $gameKey, int $universeId, int $gameUserId, int $gameAllianceId): ?self
    {
        $row = self::findFirst([
            'conditions' => 'game_key = :g: AND universe_id = :u: AND game_user_id = :p: AND game_alliance_id = :a:',
            'bind'       => ['g' => $gameKey, 'u' => $universeId, 'p' => $gameUserId, 'a' => $gameAllianceId],
        ]);

        return $row instanceof self ? $row : null;
    }

    /** @return self[] every active membership of a player in other alliances */
    public static function activeElsewhere(string $gameKey, int $universeId, int $gameUserId, int $exceptAllianceId): array
    {
        $rows = self::find([
            'conditions' => 'game_key = :g: AND universe_id = :u: AND game_user_id = :p: AND game_alliance_id <> :a: AND state = :s:',
            'bind'       => ['g' => $gameKey, 'u' => $universeId, 'p' => $gameUserId, 'a' => $exceptAllianceId, 's' => self::STATE_ACTIVE],
        ]);

        return iterator_to_array($rows, false);
    }
}
