<?php
declare(strict_types=1);

namespace Forum;

use Phalcon\Autoload\Loader;
use Phalcon\Di\DiInterface;
use Phalcon\Mvc\ModuleDefinitionInterface;
use Phalcon\Mvc\Router;
use Phalcon\Mvc\View;
use Phalcon\Mvc\View\Engine\Php as PhpEngine;

/**
 * Headless plugin-tier module (docs/MODULE-SPEC.md, "Headless modules"):
 * `"routes": false` in module.json, so the engine adds no generic
 * /forum/:controller/:action routes. What exists is registered here
 * explicitly — the one API route games call and the two admin screens
 * (event audit, game registry) — and the `forum` service other modules
 * and the cron worker resolve from the shared DI.
 */
class Module implements ModuleDefinitionInterface
{
    public function registerAutoloaders(?DiInterface $di = null)
    {
        $loader = new Loader();

        // Controllers follow the house layout (src/controllers/, lower
        // case) which Composer's PSR-4 map for "Forum\" cannot resolve on
        // a case-sensitive filesystem; everything else under src/ is PSR-4
        // exact and reachable through Composer from any module or the CLI.
        $loader->setNamespaces([
            'Forum\Controllers'     => __DIR__ . '/controllers/',
            'Forum\Controllers\Api' => __DIR__ . '/controllers/Api/',
        ]);

        $loader->register();
    }

    public function registerServices(DiInterface $di)
    {
        $di['view'] = function () {
            $view = new View();
            $view->setViewsDir(__DIR__ . '/../views/');

            $view->registerEngines([
                '.volt'  => 'voltShared',
                '.phtml' => PhpEngine::class,
            ]);

            return $view;
        };
    }

    /** Lazy on purpose: runs on every request (MODULE-SPEC, Shared services). */
    public function registerSharedServices(DiInterface $di): void
    {
        $di->setShared('forum', fn (): ForumServiceInterface => new ForumService($di));
    }

    public function registerRoutes(Router $router)
    {
        $router->add('/api/forum/v1/events', [
            'namespace'  => 'Forum\Controllers\Api',
            'module'     => 'forum',
            'controller' => 'events',
            'action'     => 'create',
        ])->via(['POST']);

        foreach (['index' => '/forum/events', 'view' => '/forum/events/view/{id:[0-9]+}', 'retry' => '/forum/events/retry/{id:[0-9]+}'] as $action => $pattern) {
            $router->add($pattern, [
                'namespace'  => 'Forum\Controllers',
                'module'     => 'forum',
                'controller' => 'events',
                'action'     => $action,
            ])->via($action === 'retry' ? ['POST'] : ['GET']);
        }

        $router->add('/forum/games', [
            'namespace'  => 'Forum\Controllers',
            'module'     => 'forum',
            'controller' => 'games',
            'action'     => 'index',
        ])->via(['GET']);

        $router->add('/forum/games/save', [
            'namespace'  => 'Forum\Controllers',
            'module'     => 'forum',
            'controller' => 'games',
            'action'     => 'save',
        ])->via(['POST']);
    }
}
