<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use App\Models\User;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->setBasePath('/fashion-store-slim4/public');

$settings = require __DIR__ . '/../config/settings.php';
require __DIR__ . '/../config/dependencies.php';

$app->get('/user', function (Request $request, Response $response) {
    $user = User::all();
    $data = json_encode($user);
    $response->getBody()->write($data);
    return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
});

$app->run();
