<?php
declare(strict_types=1);

namespace Forum\Models;

/**
 * forum_links: stable game identity -> provider id, unique per
 * (game_key, universe_id, entity_type, game_entity_id, provider).
 */
class Link extends ModelBase
{
    public const TYPE_USER          = 'user';
    public const TYPE_ALLIANCE_TAG  = 'alliance_tag';
    public const TYPE_ALLIANCE_GRP  = 'alliance_group';
    public const TYPE_AREA_TAG      = 'area_tag';
    public const TYPE_AREA_GROUP    = 'area_group';
    public const TYPE_TUTORIAL_POST = 'tutorial_post';

    public $game_key;
    public $universe_id;
    public $entity_type;
    public $game_entity_id;
    public $provider;
    public $provider_id;
    public $meta;

    protected function tableName(): string
    {
        return 'forum_links';
    }

    public static function lookup(string $gameKey, int $universeId, string $entityType, string $gameEntityId, string $provider): ?self
    {
        $link = self::findFirst([
            'conditions' => 'game_key = :g: AND universe_id = :u: AND entity_type = :t: AND game_entity_id = :i: AND provider = :p:',
            'bind'       => ['g' => $gameKey, 'u' => $universeId, 't' => $entityType, 'i' => $gameEntityId, 'p' => $provider],
        ]);

        return $link instanceof self ? $link : null;
    }
}
