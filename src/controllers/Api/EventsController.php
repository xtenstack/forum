<?php
declare(strict_types=1);

namespace Forum\Controllers\Api;

use Forum\Controllers\ApiControllerBase;
use Forum\Exception\Unauthorised;
use Forum\Exception\UnknownGame;
use Forum\Exception\ValidationFailed;
use Forum\ForumServiceInterface;

/**
 * POST /api/forum/v1/events (contract §"Event endpoint"). Bearer = the
 * per-game Stack service user's API key. 202 {accepted, duplicate} for a
 * stored or replayed event; 400 malformed body, 404 unknown game, 403 key
 * not authorised for the game, 422 invalid envelope/payload — all 4xx are
 * permanent for the game (dead-letter), per the contract.
 */
class EventsController extends ApiControllerBase
{
    public function createAction()
    {
        $body = $this->jsonObjectBody();

        if ($body === null) {
            return $this->json(400, ['error' => 'Request body must be a JSON object']);
        }

        /** @var ForumServiceInterface $forum */
        $forum = $this->getDI()->getShared('forum');

        try {
            $result = $forum->ingest($body, (int) ($this->principal['user_id'] ?? 0), isset($this->principal['api_key_id']) ? (int) $this->principal['api_key_id'] : null);
        } catch (UnknownGame $e) {
            return $this->json(404, ['error' => $e->getMessage()]);
        } catch (Unauthorised $e) {
            return $this->json(403, ['error' => $e->getMessage()]);
        } catch (ValidationFailed $e) {
            return $this->json(422, ['error' => 'Invalid event', 'errors' => $e->getErrors()]);
        }

        return $this->json(202, ['accepted' => true, 'duplicate' => $result['duplicate']]);
    }
}
