<?php

return new \Phalcon\Config\Config([
        'database' => [
        'adapter'     => 'Mysql',
        'host'        => 'localhost',
        'username'    => 'root',
        'password'    => 'r00tme',
        'dbname'      => 'cola',
        'charset'     => 'utf8',
    ],
    'application' => [
        'controllersDir' => __DIR__ . '/../../app/controllers/',
        'modelsDir'      => __DIR__ . '/../../app/models/',
        'migrationsDir'  => __DIR__ . '/../../app/migrations/',
        'viewsDir'       => __DIR__ . '/../../app/views/',
        'pluginsDir'     => __DIR__ . '/../../app/plugins/',
        'libraryDir'     => __DIR__ . '/../../app/library/',
        'cacheDir'       => __DIR__ . '/../../app/cache/',
        'configDir'      => __DIR__ . '/../../app/config/',
        'baseUri'        => '/',
    ],
    'collmex' => [
        'user' => '',
        'password' => '',
        'customer_id' => ''
    ],
    'smtp' => [
        'host' => '',
        'user' => '',
        'pass' => '',
        'from' => ['landkarte@premium-cola.de' => 'Premium Landkarte']
    ],
    'photon' => [
        'DE' => 'http://127.0.0.1:2322',
        'AT' => 'http://127.0.0.1:2323',
        'CH' => 'http://127.0.0.1:2324',
    ]
]);