<?php
declare(strict_types=1);

/**
 * Bootstrap for the pure-PHP suite (phpunit.unit.xml, tests/Unit/): only
 * the framework-free classes under src/ (Envelope, the exceptions, the
 * fake adapter), autoloaded with the same psr-4 mapping composer.json
 * declares. ForumServiceInterface is interface-only and loads too.
 */
spl_autoload_register(static function (string $class): void {
    $prefix = 'Forum\\';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

    if (is_file($file)) {
        require $file;
    }
});
