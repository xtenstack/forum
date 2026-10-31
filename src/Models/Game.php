<?php
declare(strict_types=1);

namespace Forum\Models;

/** forum_games: which Stack service user may send events for a game_key. */
class Game extends ModelBase
{
    public $game_key;
    public $name;
    public $service_user_id;
    public $enabled;

    protected function tableName(): string
    {
        return 'forum_games';
    }

    public static function findByKey(string $gameKey): ?self
    {
        $game = self::findFirst(['conditions' => 'game_key = :k:', 'bind' => ['k' => $gameKey]]);

        return $game instanceof self ? $game : null;
    }
}
