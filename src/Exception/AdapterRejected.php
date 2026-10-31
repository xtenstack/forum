<?php
declare(strict_types=1);

namespace Forum\Exception;

/**
 * The forum platform refused the operation for a reason a retry will not
 * fix (a 4xx from Flarum, an identity that no longer exists there): the
 * event is marked dead for an operator to look at.
 */
class AdapterRejected extends ForumException
{
}
