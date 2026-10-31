<?php
declare(strict_types=1);

namespace Forum\Controllers;

use Phalcon\Mvc\Controller;

/**
 * JSON surface auth — the same session-or-API-key principal resolution
 * the announcements and ai-ssa-* plugins carry (a plugin cannot extend
 * the core api module's ControllerBase: no cross-module reach-ins).
 * Authentication happens here, before any action body parses anything
 * (contract: "authenticate before parsing or applying the event"). Which
 * game the principal may send for is the service's decision, not a role.
 */
class ApiControllerBase extends Controller
{
    /** @var array{type: string, user_id: int, role_id: int, role_ids: int[], api_key_id?: int}|null */
    protected ?array $principal = null;

    protected function onConstruct()
    {
        $this->response->setContentType('application/json', 'UTF-8');

        if ($this->auth->isLoggedIn()) {
            $auth            = $this->session->get('auth');
            $this->principal = ['type' => 'user', 'user_id' => (int) $auth['id'], 'role_id' => (int) $auth['role_id'], 'role_ids' => \App_skeleton\Auth::roleIdsFrom($auth)];
        } else {
            $token           = $this->apiKeyAuth->tokenFromRequest($this->request);
            $this->principal = $token ? $this->apiKeyAuth->resolve($token) : null;
        }

        if (!$this->principal) {
            $this->response->setStatusCode(401, 'Unauthorized');
            $this->response->setJsonContent(['error' => 'Not authenticated']);
            $this->response->send();
            exit;
        }
    }

    /** @return array<string, mixed>|null null when the body is not a JSON object */
    protected function jsonObjectBody(): ?array
    {
        $raw = $this->request->getRawBody();

        if ($raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) && array_is_list($decoded) === false ? $decoded : null;
    }

    protected function json(int $status, array $body): \Phalcon\Http\ResponseInterface
    {
        $this->response->setStatusCode($status);

        return $this->response->setJsonContent($body);
    }
}
