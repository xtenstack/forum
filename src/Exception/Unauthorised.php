<?php
declare(strict_types=1);

namespace Forum\Exception;

/** The authenticated principal is not the game's service user (HTTP 403). */
class Unauthorised extends ForumException
{
}
