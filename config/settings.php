<?php

return [
    'db' => [
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'database' => 'database',
        'username' => 'username',
        'password' => 'password',
        'charset' => 'utf8',
        'collation' => 'utf8_general_ci',
    ],
    'jwt' => [
        'key' => 'secretkey',
        'alg' => 'HS256',
    ],
];
