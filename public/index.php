<?php

header('Access-Control-Allow-Origin: *');
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {

    /**
     * Read the configuration
     */
    $config = include __DIR__ . "/../app/config/config.php";

    require __DIR__ . "/../vendor/autoload.php";

    /**
     * Read auto-loader
     */
    include __DIR__ . "/../app/config/loader.php";

    /**
     * Read services
     */
    include __DIR__ . "/../app/config/services.php";

    /**
     * Handle the request
     */
    $application = new \Phalcon\Mvc\Application($di);

    $uri = isset($_GET['_url']) ? $_GET['_url'] : (isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/');
    echo $application->handle($uri)->getContent();

} catch (\Exception $e) {
    echo $e->getMessage();
}
