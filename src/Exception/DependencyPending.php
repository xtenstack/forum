<?php
declare(strict_types=1);

namespace Forum\Exception;

/**
 * The event needs something another event provides (its user, its
 * alliance) that has not been applied yet. Retryable like an outage, but
 * it must not stop the lane: the provider may be the very next event in
 * it, so the worker carries on and re-tries the waiting event at the end
 * of the run.
 */
class DependencyPending extends AdapterUnavailable
{
}
