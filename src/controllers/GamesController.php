<?php
declare(strict_types=1);

namespace Forum\Controllers;

use Forum\Exception\ValidationFailed;
use Forum\Models\Game;

/** The game registry: which Stack service user may send events for which game_key. */
class GamesController extends ControllerBase
{
    public function indexAction(): void
    {
        $this->render([]);
    }

    public function saveAction(): void
    {
        if (!$this->requireAdmin('Registering a game')) {
            return;
        }

        $gameKey = trim((string) $this->request->getPost('game_key', 'string', ''));
        $name    = trim((string) $this->request->getPost('name', 'string', ''));
        $email   = trim((string) $this->request->getPost('service_user_email', 'email', ''));
        $enabled = $this->request->getPost('enabled') !== null;
        $errors  = [];

        $user = $email !== '' ? \Users::findFirst(['conditions' => 'email = :e:', 'bind' => ['e' => $email]]) : null;

        if (!$user instanceof \Users) {
            $errors[] = 'No Stack user has the email ' . $email . '. Create the service user first (Users), then its API key.';
        } else {
            try {
                $this->forum()->registerGame($gameKey, $name, (int) $user->id, $enabled);
                $this->flash->success('Game ' . $gameKey . ' saved. Its events must be sent with an API key of ' . $email . '.');
                $this->view->disable();
                $this->response->redirect('forum/games');

                return;
            } catch (ValidationFailed $e) {
                foreach ($e->getErrors() as $field => $problem) {
                    $errors[] = $field . ' ' . $problem;
                }
            }
        }

        $this->render($errors, ['game_key' => $gameKey, 'name' => $name, 'service_user_email' => $email, 'enabled' => $enabled]);
    }

    private function render(array $errors, array $old = []): void
    {
        $games  = iterator_to_array(Game::find(['order' => 'game_key']), false);
        $labels = [];

        foreach ($games as $game) {
            $user = \Users::findFirst(['conditions' => 'id = :id:', 'bind' => ['id' => (int) $game->service_user_id]]);
            $labels[(int) $game->service_user_id] = $user instanceof \Users ? (string) $user->email : '#' . $game->service_user_id;
        }

        $this->view->games      = $games;
        $this->view->userLabels = $labels;
        $this->view->errors     = $errors;
        $this->view->old        = $old + ['game_key' => '', 'name' => '', 'service_user_email' => '', 'enabled' => true];
        $this->view->pick('games/index');
    }
}
