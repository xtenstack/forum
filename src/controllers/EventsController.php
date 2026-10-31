<?php
declare(strict_types=1);

namespace Forum\Controllers;

use Forum\Exception\ForumException;
use Forum\ForumServiceInterface;
use Forum\Models\Event;
use Forum\Models\Game;

/** The event audit: list with filters, one event with its envelope, and Retry. */
class EventsController extends ControllerBase
{
    private const PER_PAGE = 50;

    public function indexAction(): void
    {
        $conditions = [];
        $bind       = [];

        $status = (string) $this->request->getQuery('status', 'string', 'all');

        if (in_array($status, Event::STATUSES, true)) {
            $conditions[]   = 'status = :status:';
            $bind['status'] = $status;
        } else {
            $status = 'all';
        }

        $game = (string) $this->request->getQuery('game', 'string', 'all');

        if ($game !== 'all' && $game !== '') {
            $conditions[] = 'game_key = :game:';
            $bind['game'] = $game;
        } else {
            $game = 'all';
        }

        $type = (string) $this->request->getQuery('type', 'string', 'all');

        if (in_array($type, ForumServiceInterface::EVENT_TYPES, true)) {
            $conditions[] = 'event_type = :type:';
            $bind['type'] = $type;
        } else {
            $type = 'all';
        }

        $list = \App_skeleton\ListView::paginate(
            $this->request,
            Event::class,
            ['event_id', 'entity_key', 'last_error'],
            ['received' => 'id', 'occurred' => 'occurred_at', 'status' => 'status', 'attempts' => 'attempts'],
            $conditions,
            $bind,
            self::PER_PAGE
        );

        $this->view->events        = $list['results'];
        $this->view->listState     = $list;
        $this->view->preserveQuery = array_merge($list['preserve'], array_filter(['status' => $status, 'game' => $game, 'type' => $type], static fn (string $v): bool => $v !== 'all'));
        $this->view->currentStatus = $status;
        $this->view->currentGame   = $game;
        $this->view->currentType   = $type;
        $this->view->statuses      = Event::STATUSES;
        $this->view->eventTypes    = ForumServiceInterface::EVENT_TYPES;
        $this->view->games         = iterator_to_array(Game::find(['order' => 'game_key']), false);
        $this->view->pick('events/index');
    }

    public function viewAction(): void
    {
        $event = Event::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => (int) $this->dispatcher->getParam('id')]]);

        if (!$event instanceof Event) {
            $this->flash->error('No such event.');
            $this->response->redirect('forum/events');

            return;
        }

        $this->view->event        = $event;
        $this->view->envelopeJson = json_encode($event->envelopeArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->view->pick('events/view');
    }

    public function retryAction(): void
    {
        $this->view->disable();
        $id = (int) $this->dispatcher->getParam('id');

        try {
            $this->forum()->retry($id);
            $this->flash->success('Event ' . $id . ' is back on the queue; the worker runs every five minutes (or ./run forum process now).');
        } catch (ForumException $e) {
            $this->flash->error($e->getMessage());
        }

        $this->response->redirect('forum/events/view/' . $id);
    }
}
