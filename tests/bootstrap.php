<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// With APP_DEBUG=0 the container is never rebuilt on config changes, so
// edits to security.yaml or new controllers stay invisible. Wipe the test
// cache at the start of every run.
if (false === (bool) ($_SERVER['APP_DEBUG'] ?? true)) {
    (new \Symfony\Component\Filesystem\Filesystem())->remove(__DIR__.'/../var/cache/test');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
