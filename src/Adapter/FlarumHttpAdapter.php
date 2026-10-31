<?php
declare(strict_types=1);

namespace Forum\Adapter;

use Forum\Exception\AdapterRejected;
use Forum\Exception\AdapterUnavailable;

/**
 * Talks to a Flarum 1.8 instance over its JSON:API with a master API key.
 *
 * Flarum quirks this class absorbs so the service never has to know them:
 *  - a master key acts as the user named in the Authorization header
 *    (`Token <key>; userId=<n>`), so "post as player X" is just a header;
 *  - group membership has no add/remove endpoint, only a full replacement
 *    of relationships.groups on the user (read-modify-write);
 *  - tag access is a flat permission table keyed `tag<id>.<ability>`;
 *  - creating a user that already exists is a 422, which we turn into a
 *    lookup so a replayed `user.linked` event is idempotent.
 *
 * Plain curl, no HTTP client dependency: the project does not ship one.
 */
final class FlarumHttpAdapter implements FlarumAdapterInterface
{
    /** Flarum's built-in group ids. */
    private const GROUP_ADMIN  = '1';
    private const GROUP_GUEST  = '2';
    private const GROUP_MEMBER = '3';

    /** The user the master key acts as unless a request says otherwise. */
    private const MASTER_USER_ID = '1';

    /** Flarum requires a hex colour when a tag is created; a neutral grey for private game areas. */
    private const DEFAULT_TAG_COLOR = '#6b7280';

    private ?string $baseUrl;

    /** Held only for the lifetime of this object; never logged or echoed. */
    private ?string $token;

    private int $timeoutSeconds;

    public function __construct(?string $baseUrl, ?string $token, int $timeoutSeconds = 10)
    {
        $baseUrl              = trim((string) $baseUrl);
        $this->baseUrl        = $baseUrl === '' ? null : rtrim($baseUrl, '/');
        $token                = trim((string) $token);
        $this->token          = $token === '' ? null : $token;
        $this->timeoutSeconds = max(1, $timeoutSeconds);
    }

    /**
     * Build from the Stack's shared external_connections row named `flarum`
     * (base_url + encrypted credential). An unconfigured adapter is returned,
     * not an exception, when the row is missing: the service decides whether
     * to fall back to the fake or refuse to start.
     */
    public static function fromExternalConnections(): self
    {
        $conn = \ExternalConnections::findActiveByName('flarum');

        if ($conn === null) {
            return new self(null, null);
        }

        return new self((string) $conn->base_url, $conn->revealCredential());
    }

    public function name(): string
    {
        return 'flarum';
    }

    public function isConfigured(): bool
    {
        return $this->baseUrl !== null && $this->token !== null;
    }

    // ------------------------------------------------------------------ users

    public function createUser(array $user): string
    {
        $username = (string) $user['username'];
        $email    = (string) $user['email'];

        $attributes = [
            'username'         => $username,
            'email'            => $email,
            // Random throw-away password: players reach Flarum through SSO,
            // nobody ever types this and the plugin does not keep it.
            'password'         => bin2hex(random_bytes(32)),
            'isEmailConfirmed' => true,
        ];

        try {
            $response = $this->request('POST', '/api/users', ['data' => ['type' => 'users', 'attributes' => $attributes]]);
            $id       = $this->idFrom($response);
        } catch (AdapterRejected $e) {
            // "username/email already taken" → the user exists from an earlier
            // delivery; return that id so the event is idempotent.
            $existing = $e->getCode() === 422 ? $this->findUser($username, $email) : null;

            if ($existing === null) {
                throw $e;
            }

            $id = $existing;
        }

        $extra = $this->userAttributes($user);

        if ($extra !== []) {
            $this->request('PATCH', '/api/users/' . rawurlencode($id), ['data' => ['type' => 'users', 'id' => $id, 'attributes' => $extra]]);
        }

        return $id;
    }

    public function updateUser(string $providerUserId, array $user): void
    {
        $attributes = $this->userAttributes($user);

        foreach (['username', 'email'] as $key) {
            if (isset($user[$key]) && $user[$key] !== '') {
                $attributes[$key] = (string) $user[$key];
            }
        }

        if ($attributes === []) {
            return;
        }

        $this->request('PATCH', '/api/users/' . rawurlencode($providerUserId), [
            'data' => ['type' => 'users', 'id' => $providerUserId, 'attributes' => $attributes],
        ]);
    }

    // ----------------------------------------------------------------- groups

    public function createGroup(string $name, string $namePlural, ?string $color = null): string
    {
        $response = $this->request('POST', '/api/groups', [
            'data' => [
                'type'       => 'groups',
                'attributes' => [
                    'nameSingular' => $name,
                    'namePlural'   => $namePlural,
                    'color'        => $color,
                    'icon'         => null,
                ],
            ],
        ]);

        return $this->idFrom($response);
    }

    public function renameGroup(string $providerGroupId, string $name, string $namePlural): void
    {
        $this->request('PATCH', '/api/groups/' . rawurlencode($providerGroupId), [
            'data' => [
                'type'       => 'groups',
                'id'         => $providerGroupId,
                'attributes' => ['nameSingular' => $name, 'namePlural' => $namePlural],
            ],
        ]);
    }

    // ------------------------------------------------------------------- tags

    public function createTag(string $name, string $slug, ?string $description, ?string $parentProviderTagId, bool $hidden): string
    {
        $data = [
            'type'       => 'tags',
            'attributes' => [
                'name'        => $name,
                'slug'        => $slug,
                // Flarum 1.8 refuses a tag without a valid hex colour or with a
                // null description (verified against the live forum, 26 Oct 2026).
                'description' => (string) $description,
                'color'       => self::DEFAULT_TAG_COLOR,
                'isHidden'    => $hidden,
            ],
        ];

        if ($parentProviderTagId !== null && $parentProviderTagId !== '') {
            $data['relationships'] = ['parent' => ['data' => ['type' => 'tags', 'id' => $parentProviderTagId]]];
        }

        return $this->idFrom($this->request('POST', '/api/tags', ['data' => $data]));
    }

    public function updateTag(string $providerTagId, array $changes): void
    {
        $attributes = [];

        if (array_key_exists('name', $changes)) {
            $attributes['name'] = (string) $changes['name'];
        }
        if (array_key_exists('description', $changes)) {
            $attributes['description'] = (string) $changes['description'];
        }
        if (array_key_exists('hidden', $changes)) {
            $attributes['isHidden'] = (bool) $changes['hidden'];
        }

        if ($attributes === []) {
            return;
        }

        $this->request('PATCH', '/api/tags/' . rawurlencode($providerTagId), [
            'data' => ['type' => 'tags', 'id' => $providerTagId, 'attributes' => $attributes],
        ]);
    }

    /**
     * Flarum scopes tag permissions as `tag<id>.<ability>` rows, each holding
     * the full list of group ids allowed. Guest (2) means everyone can view;
     * posting for a public tag goes to Members (3), never guests. Private
     * tags get exactly the given groups plus Admin (1) so operators keep
     * sight of them (moderators are granted by Flarum regardless).
     */
    public function setTagAccess(string $providerTagId, ?array $providerGroupIds, bool $allowPosting = true): void
    {
        if ($providerGroupIds === null) {
            $viewIds = [self::GROUP_GUEST];
            $postIds = [self::GROUP_MEMBER];
        } else {
            $ids = array_values(array_unique(array_merge([self::GROUP_ADMIN], array_map('strval', $providerGroupIds))));
            $viewIds = $postIds = $ids;
        }

        $this->setPermission('tag' . $providerTagId . '.viewForum', $viewIds);

        if ($allowPosting) {
            $this->setPermission('tag' . $providerTagId . '.startDiscussion', $postIds);
            $this->setPermission('tag' . $providerTagId . '.discussion.reply', $postIds);
        }
    }

    // ------------------------------------------------------------ memberships

    public function addUserToGroup(string $providerUserId, string $providerGroupId): void
    {
        $current = $this->userGroupIds($providerUserId);

        if (in_array($providerGroupId, $current, true)) {
            return;
        }

        $current[] = $providerGroupId;
        $this->writeUserGroups($providerUserId, $current);
    }

    public function removeUserFromGroup(string $providerUserId, string $providerGroupId): void
    {
        $current = $this->userGroupIds($providerUserId);
        $next    = array_values(array_diff($current, [$providerGroupId]));

        if (count($next) === count($current)) {
            return;
        }

        $this->writeUserGroups($providerUserId, $next);
    }

    // ------------------------------------------------------------ discussions

    public function createDiscussion(string $providerTagId, string $authorProviderUserId, string $title, string $body): string
    {
        // Acting as the author via the master key's userId override makes
        // Flarum record them as the poster (and respect their permissions).
        $response = $this->request('POST', '/api/discussions', [
            'data' => [
                'type'          => 'discussions',
                'attributes'    => ['title' => $title, 'content' => $body],
                'relationships' => ['tags' => ['data' => [['type' => 'tags', 'id' => $providerTagId]]]],
            ],
        ], $authorProviderUserId);

        return $this->idFrom($response);
    }

    public function updateDiscussion(string $providerDiscussionId, string $title, string $body, bool $hidden): void
    {
        $path     = '/api/discussions/' . rawurlencode($providerDiscussionId);
        $response = $this->request('PATCH', $path, [
            'data' => [
                'type'       => 'discussions',
                'id'         => $providerDiscussionId,
                'attributes' => ['title' => $title, 'isHidden' => $hidden],
            ],
        ]);

        // The body lives on the first post, a separate resource.
        $firstPostId = $this->firstPostIdFrom($response);

        if ($firstPostId === null) {
            $firstPostId = $this->firstPostIdFrom($this->request('GET', $path));
        }

        if ($firstPostId === null) {
            throw new AdapterRejected('Flarum discussion ' . $providerDiscussionId . ' has no first post');
        }

        $this->request('PATCH', '/api/posts/' . rawurlencode($firstPostId), [
            'data' => ['type' => 'posts', 'id' => $firstPostId, 'attributes' => ['content' => $body]],
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Optional user attributes shared by create and update. `language` is
     * skipped: Flarum core has no per-user locale attribute writable through
     * the API (the UI locale is a cookie/session choice), so it is dropped
     * silently rather than rejected.
     *
     * @param array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function userAttributes(array $user): array
    {
        $attributes = [];

        if (isset($user['avatar_url']) && $user['avatar_url'] !== '') {
            $attributes['avatarUrl'] = (string) $user['avatar_url'];
        }
        if (isset($user['bio']) && $user['bio'] !== '') {
            $attributes['bio'] = (string) $user['bio'];
        }

        return $attributes;
    }

    /**
     * Find an existing user by username (case-insensitive) or email.
     * `filter[q]=username:x` is unreliable on 1.8, so search the free-text
     * form and match the attributes ourselves.
     */
    private function findUser(string $username, string $email): ?string
    {
        foreach ([$username, $email] as $needle) {
            $response = $this->request('GET', '/api/users?' . http_build_query(['filter' => ['q' => $needle], 'page' => ['limit' => 50]]));

            $byEmail = null;

            foreach ($response['data'] ?? [] as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }

                $attrs = $row['attributes'] ?? [];

                if (strcasecmp((string) ($attrs['username'] ?? ''), $username) === 0) {
                    return (string) $row['id'];
                }
                if ($byEmail === null && strcasecmp((string) ($attrs['email'] ?? ''), $email) === 0) {
                    $byEmail = (string) $row['id'];
                }
            }

            if ($byEmail !== null) {
                return $byEmail;
            }
        }

        return null;
    }

    /** @return string[] */
    private function userGroupIds(string $providerUserId): array
    {
        $response = $this->request('GET', '/api/users/' . rawurlencode($providerUserId));
        $ids      = [];

        foreach ($response['data']['relationships']['groups']['data'] ?? [] as $ref) {
            if (is_array($ref) && isset($ref['id'])) {
                $ids[] = (string) $ref['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** @param string[] $groupIds */
    private function writeUserGroups(string $providerUserId, array $groupIds): void
    {
        $this->request('PATCH', '/api/users/' . rawurlencode($providerUserId), [
            'data' => [
                'type'          => 'users',
                'id'            => $providerUserId,
                'relationships' => [
                    'groups' => [
                        'data' => array_map(static fn (string $id): array => ['type' => 'groups', 'id' => $id], $groupIds),
                    ],
                ],
            ],
        ]);
    }

    /** @param string[] $groupIds */
    private function setPermission(string $permission, array $groupIds): void
    {
        $this->request('POST', '/api/permission', [
            'permission' => $permission,
            'groupIds'   => array_values($groupIds),
        ]);
    }

    /** @param array<string, mixed> $response */
    private function idFrom(array $response): string
    {
        $id = $response['data']['id'] ?? null;

        if (!is_scalar($id) || (string) $id === '') {
            throw new AdapterRejected('Flarum returned no id');
        }

        return (string) $id;
    }

    /** @param array<string, mixed> $response */
    private function firstPostIdFrom(array $response): ?string
    {
        $id = $response['data']['relationships']['firstPost']['data']['id'] ?? null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }

    /**
     * One curl round trip. Returns the decoded JSON body ([] for 204).
     * Transport failures, 429 and 5xx → AdapterUnavailable (retry);
     * any other non-2xx → AdapterRejected (dead-letter), code = HTTP status.
     *
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body = null, ?string $actAsUserId = null): array
    {
        if (!$this->isConfigured()) {
            throw new AdapterUnavailable('Flarum adapter is not configured (missing base URL or token)');
        }

        $url     = $this->baseUrl . $path;
        $headers = [
            'Accept: application/vnd.api+json',
            'Content-Type: application/json',
            // Master key + userId: Flarum performs the request as that user.
            'Authorization: Token ' . $this->token . '; userId=' . ($actAsUserId ?? self::MASTER_USER_ID),
        ];

        $ch = curl_init($url);

        if ($ch === false) {
            throw new AdapterUnavailable('curl_init failed for ' . $url);
        }

        $options = [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER     => $headers,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($json === false) {
                curl_close($ch);
                throw new AdapterRejected('Could not encode request body for ' . $method . ' ' . $path);
            }

            $options[CURLOPT_POSTFIELDS] = $json;
        }

        curl_setopt_array($ch, $options);

        $raw     = curl_exec($ch);
        $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $errno   = curl_errno($ch);
        $error   = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $errno !== 0) {
            throw new AdapterUnavailable(sprintf('Flarum unreachable (%s %s): curl %d %s', $method, $path, $errno, $error));
        }

        $raw     = (string) $raw;
        $snippet = substr(preg_replace('/\s+/', ' ', $raw) ?? '', 0, 200);

        if ($status === 429 || $status >= 500) {
            throw new AdapterUnavailable(sprintf('Flarum %d on %s %s: %s', $status, $method, $path, $snippet), $status);
        }

        if ($status < 200 || $status >= 300) {
            throw new AdapterRejected(sprintf('Flarum %d on %s %s: %s', $status, $method, $path, $snippet), $status);
        }

        if ($status === 204 || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new AdapterRejected(sprintf('Flarum %d on %s %s returned non-JSON: %s', $status, $method, $path, $snippet), $status);
        }

        return $decoded;
    }
}
