<?php

    $router = new \Phalcon\Mvc\Router(false);

    $router->add('/search/:params',[
        'controller' => 'item',
        'action' => 'apiSearch',
        'string' => 1
    ]);

    /*
     * API Call to get list of geolocations
     */
    $router->addGet('/item/list', [       
        'controller' => 'item',
        'action' => 'apiList',
    ]);

    $router->addGet('/item/groups', [
        'controller' => 'item',
        'action' => 'apiGroups',
    ]);

    /*
     * API Call to get all items
     */
    $router->addGet('/item/all', [       
        'controller' => 'item',
        'action' => 'getAll',
    ]);
    
    /*
     * get one item
     */
    $router->addGet('/item/:int', [
        'controller' => 'item',
        'action' => 'apiGet',
        'id' => 1
    ]);

    /*
     * feedback form
     */
    $router->addPost('/feedback',[
        'controller' => 'feedback',
        'action' => 'zipmail'
    ]);

    $router->addPost('/feedback/report',[
        'controller' => 'feedback',
        'action' => 'report'
    ]);
