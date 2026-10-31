<?php
declare(strict_types=1);

namespace Forum\Adapter;

/**
 * Everything the event handlers need from the forum platform, in the
 * platform's own terms (users, groups, tags, discussions, permissions)
 * but keyed by the provider ids the plugin stores in forum_links /
 * forum_areas. The HTTP implementation talks to Flarum's JSON:API; the
 * fake keeps the same state in memory for the contract tests. Swapping
 * the platform means one new class here, nothing in the service.
 *
 * Every method throws Forum\Exception\AdapterUnavailable for a transient
 * failure (retry) and Forum\Exception\AdapterRejected for a permanent one
 * (dead-letter).
 */
interface FlarumAdapterInterface
{
    public function name(): string;

    /** True when the adapter has what it needs to talk to the platform. */
    public function isConfigured(): bool;

    /**
     * @param array{username: string, email: string, language?: ?string, avatar_url?: ?string, bio?: ?string} $user
     * @return string the provider user id
     */
    public function createUser(array $user): string;

    /** @param array<string, mixed> $user same keys as createUser(), all optional */
    public function updateUser(string $providerUserId, array $user): void;

    /** @return string the provider group id */
    public function createGroup(string $name, string $namePlural, ?string $color = null): string;

    public function renameGroup(string $providerGroupId, string $name, string $namePlural): void;

    /**
     * @return string the provider tag id
     */
    public function createTag(string $name, string $slug, ?string $description, ?string $parentProviderTagId, bool $hidden): string;

    /** @param array{name?: string, description?: ?string, hidden?: bool} $changes */
    public function updateTag(string $providerTagId, array $changes): void;

    /**
     * Restrict who can see/post in a tag: null $providerGroupIds means
     * "everyone" (public); an array means only those groups (plus
     * moderators/admins, which the platform grants anyway).
     *
     * @param string[]|null $providerGroupIds
     */
    public function setTagAccess(string $providerTagId, ?array $providerGroupIds, bool $allowPosting = true): void;

    public function addUserToGroup(string $providerUserId, string $providerGroupId): void;

    public function removeUserFromGroup(string $providerUserId, string $providerGroupId): void;

    /** @return string the provider discussion id */
    public function createDiscussion(string $providerTagId, string $authorProviderUserId, string $title, string $body): string;

    public function updateDiscussion(string $providerDiscussionId, string $title, string $body, bool $hidden): void;
}
