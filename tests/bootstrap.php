<?php
declare(strict_types=1);

/**
 * Same shape as the contacts plugin's bootstrap: BASE_PATH climbs four
 * levels from this file's composer-installed location
 * (vendor/xtendeploy/forum/tests/) back to the app_skeleton root, boots
 * the CLI DI (services.php + loader.php) and every enabled module's
 * shared services, which is what registers `forum`.
 */
// A symlinked path-repo install (composer.local.json "symlink": true, the
// dev loop) resolves __DIR__ to the plugins checkout, so prefer the
// app_skeleton root the suite is run from, or an explicit APP_SKELETON_ROOT.
define('BASE_PATH', getenv('APP_SKELETON_ROOT') ?: (is_file(getcwd() . '/vendor/autoload.php') && is_dir(getcwd() . '/app') ? getcwd() : dirname(__DIR__, 4)));
define('APP_PATH', BASE_PATH . '/app');

require BASE_PATH . '/vendor/autoload.php';
require BASE_PATH . '/tests/Feature/HttpClient.php';

$di = new \Phalcon\Di\FactoryDefault();
require APP_PATH . '/config/services.php';
require APP_PATH . '/config/loader.php';
\Phalcon\Di\Di::setDefault($di);

$di->getShared('moduleManager')->registerSharedServices($di);

if (!$di->has('forum')) {
    fwrite(STDERR, "The forum module is not enabled on this instance — run ./run modules sync && ./run modules enable forum first.\n");
    exit(1);
}
