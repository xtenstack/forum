<?php
declare(strict_types=1);

namespace Forum\Adapter;

use Forum\Exception\AdapterRejected;
use Forum\Exception\AdapterUnavailable;

/**
 * In-memory stand-in for Flarum: the contract tests assert against its
 * state (users, groups, tags, memberships, tag access, discussions) and
 * its call log, and inject failures with failNext() to exercise retry
 * and rollback. Also what the service runs against when no `flarum`
 * external connection exists and FORUM_FAKE_ADAPTER=1 is set (local
 * development without a Flarum).
 */
final class FakeFlarumAdapter implements FlarumAdapterInterface
{
    /** @var array<string, array<string, mixed>> */
    public array $users = [];

    /** @var array<string, array<string, mixed>> */
    public array $groups = [];

    /** @var array<string, array<string, mixed>> */
    public array $tags = [];

    /** @var array<string, array<string, mixed>> */
    public array $discussions = [];

    /** @var array<string, string[]> providerUserId => providerGroupIds */
    public array $memberships = [];

    /** @var array<string, string[]|null> providerTagId => allowed group ids (null = public) */
    public array $tagAccess = [];

    /** @var list<array{0: string, 1: array}> */
    public array $calls = [];

    private int $nextId = 1;

    /** @var list<array{0: int, 1: \Throwable}> [calls still to succeed before throwing, failure] */
    private array $failures = [];

    public function __construct(private bool $configured = true)
    {
    }

    public function name(): string
    {
        return 'fake';
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    /** The next call throws $failure (queue several for several calls). */
    public function failNext(\Throwable $failure): void
    {
        $this->failAfter(0, $failure);
    }

    /** Let $successfulCalls more calls through, then throw $failure on the one after. */
    public function failAfter(int $successfulCalls, \Throwable $failure): void
    {
        $this->failures[] = [$successfulCalls, $failure];
    }

    public function failNextTransient(string $message = 'simulated outage'): void
    {
        $this->failNext(new AdapterUnavailable($message));
    }

    public function failNextPermanent(string $message = 'simulated rejection'): void
    {
        $this->failNext(new AdapterRejected($message));
    }

    public function reset(): void
    {
        $this->users = $this->groups = $this->tags = $this->discussions = $this->memberships = $this->tagAccess = $this->calls = [];
        $this->failures = [];
        $this->nextId   = 1;
    }

    public function createUser(array $user): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $id               = $this->id('u');
        $this->users[$id] = $user + ['language' => null, 'avatar_url' => null, 'bio' => null];

        return $id;
    }

    public function updateUser(string $providerUserId, array $user): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->users[$providerUserId]), 'user ' . $providerUserId);
        $this->users[$providerUserId] = array_merge($this->users[$providerUserId], $user);
    }

    public function createGroup(string $name, string $namePlural, ?string $color = null): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $id                = $this->id('g');
        $this->groups[$id] = ['name' => $name, 'name_plural' => $namePlural, 'color' => $color];

        return $id;
    }

    public function renameGroup(string $providerGroupId, string $name, string $namePlural): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->groups[$providerGroupId]), 'group ' . $providerGroupId);
        $this->groups[$providerGroupId]['name']        = $name;
        $this->groups[$providerGroupId]['name_plural'] = $namePlural;
    }

    public function createTag(string $name, string $slug, ?string $description, ?string $parentProviderTagId, bool $hidden): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $id              = $this->id('t');
        $this->tags[$id] = ['name' => $name, 'slug' => $slug, 'description' => $description, 'parent' => $parentProviderTagId, 'hidden' => $hidden];

        return $id;
    }

    public function updateTag(string $providerTagId, array $changes): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->tags[$providerTagId]), 'tag ' . $providerTagId);
        $this->tags[$providerTagId] = array_merge($this->tags[$providerTagId], $changes);
    }

    public function setTagAccess(string $providerTagId, ?array $providerGroupIds, bool $allowPosting = true): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->tags[$providerTagId]), 'tag ' . $providerTagId);
        $this->tagAccess[$providerTagId] = $providerGroupIds === null ? null : array_values($providerGroupIds);
    }

    public function addUserToGroup(string $providerUserId, string $providerGroupId): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->users[$providerUserId]) && isset($this->groups[$providerGroupId]), 'user/group');
        $current                             = $this->memberships[$providerUserId] ?? [];
        $current[]                           = $providerGroupId;
        $this->memberships[$providerUserId] = array_values(array_unique($current));
    }

    public function removeUserFromGroup(string $providerUserId, string $providerGroupId): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->memberships[$providerUserId] = array_values(array_diff($this->memberships[$providerUserId] ?? [], [$providerGroupId]));
    }

    public function createDiscussion(string $providerTagId, string $authorProviderUserId, string $title, string $body): string
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->tags[$providerTagId]) && isset($this->users[$authorProviderUserId]), 'tag/author');
        $id                     = $this->id('d');
        $this->discussions[$id] = ['tag' => $providerTagId, 'author' => $authorProviderUserId, 'title' => $title, 'body' => $body, 'hidden' => false];

        return $id;
    }

    public function updateDiscussion(string $providerDiscussionId, string $title, string $body, bool $hidden): void
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->must(isset($this->discussions[$providerDiscussionId]), 'discussion ' . $providerDiscussionId);
        $this->discussions[$providerDiscussionId] = array_merge($this->discussions[$providerDiscussionId], ['title' => $title, 'body' => $body, 'hidden' => $hidden]);
    }

    /** True when $providerUserId can see $providerTagId under the recorded access rules. */
    public function userCanSee(string $providerUserId, string $providerTagId): bool
    {
        $allowed = $this->tagAccess[$providerTagId] ?? null;

        if ($allowed === null) {
            return true;
        }

        return array_intersect($allowed, $this->memberships[$providerUserId] ?? []) !== [];
    }

    private function record(string $method, array $args): void
    {
        $this->calls[] = [$method, $args];

        if ($this->failures !== []) {
            if ($this->failures[0][0] <= 0) {
                throw array_shift($this->failures)[1];
            }

            $this->failures[0][0]--;
        }
    }

    private function id(string $prefix): string
    {
        return $prefix . $this->nextId++;
    }

    private function must(bool $condition, string $what): void
    {
        if (!$condition) {
            throw new AdapterRejected('Unknown ' . $what . ' on the forum');
        }
    }
}
