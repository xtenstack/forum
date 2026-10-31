<?php

/**
 * Admin and operator only: the event audit and the game registry are
 * operations screens, not something an agent or member principal sees.
 * The plugin is headless ("routes": false) — these two URLs exist only
 * because Module::registerRoutes() adds them explicitly, and the
 * controllers enforce the same gate server-side (ControllerBase).
 */
$staff = \Roles::idsByNames(['admin', 'operator']);

return [
    [
        'label'      => 'Forum events',
        'icon'       => 'fas fa-stream',
        'controller' => 'events',
        'url'        => 'forum/events',
        'roles'      => $staff,
        'group'      => 'Forum',
    ],
    [
        'label'      => 'Forum games',
        'icon'       => 'fas fa-gamepad',
        'controller' => 'games',
        'url'        => 'forum/games',
        'roles'      => \Roles::idsByNames(['admin']),
        'group'      => 'Forum',
    ],
];
