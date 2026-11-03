<?php

header('Access-Control-Allow-Origin: *');
ini_set('display_errors', '0');
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
    $router = $di->get('router');
    $router->handle($uri);
    if (!$router->wasMatched()) {
        http_response_code(404);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(array('status' => 0));
        return;
    }

    echo $application->handle($uri)->getContent();

} catch (\Throwable $e) {
    error_log($e->getMessage());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=UTF-8');
    }
    echo json_encode(array('status' => 0));
}
