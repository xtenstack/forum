<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Cli\Tasks;

use Forum\ForumServiceInterface;

// Lives in the forum plugin but declares the base cli module's task
// namespace, contributed via this plugin's composer.json psr-4 entry
// ("App_skeleton\\Modules\\Cli\\Tasks\\": "src/tasks/") — the same
// mechanism the announcements plugin's feeds task uses: Phalcon's CLI
// dispatcher and CronRunner resolve a task as a fixed
// App_skeleton\Modules\Cli\Tasks\{Studly}Task name.

/**
 * Usage:
 *   ./run forum process [limit] [game_key]      apply queued events (all games, or one) (cron: task 'forum', action 'process', every 5 minutes, seeded by migration 001)
 *   ./run forum status                          queue counts per status and game
 *   ./run forum game <game_key> <name> <email>  register a game and the Stack service user whose API key it sends with
 *
 * Output is one summary line (CronRunner stores stdout as the run's
 * last_output).
 */
class ForumTask extends \Phalcon\Cli\Task
{
    public function mainAction(): void
    {
        echo 'Usage: ./run forum process [limit] [game_key] | ./run forum status | ./run forum game <game_key> <name> <service-user-email>' . PHP_EOL;
    }

    // Phalcon's CLI dispatcher passes the command line's extra words as
    // positional arguments (./run forum process 50 my-game), the same
    // way the core tasks take them (ModulesTask::enableAction($key)).
    public function processAction(string $limit = '', string $gameKey = ''): void
    {
        $limit   = ctype_digit($limit) ? max(1, (int) $limit) : 100;
        $gameKey = $gameKey !== '' ? $gameKey : null;
        $counts  = $this->forum()->processQueue($limit, $gameKey);

        echo sprintf(
            'forum: %d applied, %d rescheduled, %d dead, %d held, %d skipped behind a failure (adapter %s%s)',
            $counts['done'],
            $counts['failed'],
            $counts['dead'],
            $counts['held'],
            $counts['skipped'],
            $this->forum()->adapter()->name(),
            $this->forum()->adapter()->isConfigured() ? '' : ', not configured'
        ) . PHP_EOL;
    }

    public function statusAction(): void
    {
        $rows = $this->getDI()->getShared('db')->fetchAll(
            'SELECT game_key, status, COUNT(*) AS n FROM forum_events GROUP BY game_key, status ORDER BY game_key, status',
            \Phalcon\Db\Enum::FETCH_ASSOC
        );

        if ($rows === []) {
            echo 'forum: no events stored yet' . PHP_EOL;

            return;
        }

        foreach ($rows as $row) {
            echo sprintf('%-20s %-11s %6d', $row['game_key'], $row['status'], (int) $row['n']) . PHP_EOL;
        }
    }

    public function gameAction(string $gameKey = '', string $name = '', string $email = ''): void
    {
        if ($gameKey === '' || $name === '' || $email === '') {
            echo 'Usage: ./run forum game <game_key> <name> <service-user-email>' . PHP_EOL;

            return;
        }

        $user = \Users::findFirst(['conditions' => 'email = :e:', 'bind' => ['e' => $email]]);

        if (!$user instanceof \Users) {
            echo 'No Stack user with email ' . $email . PHP_EOL;

            return;
        }

        $game = $this->forum()->registerGame($gameKey, $name, (int) $user->id, true);
        echo sprintf('forum: game %s (%s) registered for service user #%d %s', $game->game_key, $game->name, (int) $user->id, $email) . PHP_EOL;
    }

    private function forum(): ForumServiceInterface
    {
        return $this->getDI()->getShared('forum');
    }
}
