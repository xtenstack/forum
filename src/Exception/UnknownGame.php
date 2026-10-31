<?php
declare(strict_types=1);

namespace Forum\Exception;

/** No enabled forum_games row for the claimed game_key (HTTP 404). */
class UnknownGame extends ForumException
{
}
