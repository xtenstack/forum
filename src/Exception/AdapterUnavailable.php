<?php
declare(strict_types=1);

namespace Forum\Exception;

/**
 * The forum platform could not be reached or answered with a transient
 * error: the event is rescheduled with backoff, never dead-lettered.
 */
class AdapterUnavailable extends ForumException
{
}
