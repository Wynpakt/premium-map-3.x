<?php

/**
 * App classes (controllers, models, lib). Phalcon's own loader is not used.
 */
spl_autoload_register(function ($class) use ($config) {
    foreach ([
        $config->application->controllersDir,
        $config->application->modelsDir,
        dirname($config->application->modelsDir) . '/lib/',
    ] as $dir) {
        $file = $dir . $class . '.php';
        if (is_readable($file)) {
            require_once $file;
            return;
        }
    }
});
