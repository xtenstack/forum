<?php
declare(strict_types=1);

namespace Forum\Models;

/**
 * forum_areas: the provisioned areas per (game_key, universe_id). kind is
 * tutorial | general | alliances (the three fixed ones, game_alliance_id 0)
 * or alliance (one per game alliance, game_alliance_id > 0).
 */
class Area extends ModelBase
{
    public const KIND_TUTORIAL  = 'tutorial';
    public const KIND_GENERAL   = 'general';
    public const KIND_ALLIANCES = 'alliances';
    public const KIND_ALLIANCE  = 'alliance';

    public const STATE_ACTIVE   = 'active';
    public const STATE_ARCHIVED = 'archived';

    public $game_key;
    public $universe_id;
    public $kind;
    public $game_alliance_id;
    public $name;
    public $tag;
    public $state;
    public $provider_tag_id;
    public $provider_group_id;
    public $archived_at;

    protected function tableName(): string
    {
        return 'forum_areas';
    }

    public static function lookup(string $gameKey, int $universeId, string $kind, int $gameAllianceId = 0): ?self
    {
        $area = self::findFirst([
            'conditions' => 'game_key = :g: AND universe_id = :u: AND kind = :k: AND game_alliance_id = :a:',
            'bind'       => ['g' => $gameKey, 'u' => $universeId, 'k' => $kind, 'a' => $gameAllianceId],
        ]);

        return $area instanceof self ? $area : null;
    }
}
