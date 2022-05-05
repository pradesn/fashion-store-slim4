<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Cake\Validation\Validator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->setBasePath('/fashion-store-slim4/public');

$settings = require __DIR__ . '/../config/settings.php';
require __DIR__ . '/../config/dependencies.php';

$app->post('/register', function (Request $request, Response $response, $args) {
    $body = $request->getBody();
    $json = json_decode($body, true);

    $validator = new Validator();
    $user = new User();
    
    $validator->requirePresence('email', true, 'Email is required')
        ->notEmptyString('email', 'Email field is required')
        ->email('email', false, 'Email must be valid')
        ->requirePresence('password', true, 'Password field is required')
        ->notEmptyString('password', 'Password is required')
        ->requirePresence('name', true, 'Name field is required')
        ->notEmptyString('name', 'Name is required');
    $errors = $validator->validate($json);
    if ($errors) {
        $messages['message'] = 'Register failed';
        foreach($errors as $error) {
            $messages['error'][] = array_values($error);
        }
        $statusCode = 400;
    } else {
        try {
            $messages['message'] = 'Register successfully';
            $statusCode = 201;
            $user->email = $json['email'];
            $user->password = sha1($json['password']);
            $user->name = $json['name'];
            $user->level = 0; // ex: default level for user
            $user->status = 1; // ex: default status for active user
            $user->save();
        } catch (\Exception $e) {
            $messages = [
                'message' => 'Register failed',
                'error' => $e->getMessage(),
            ];
            $statusCode = 400;
        }
    }

    $message = json_encode($messages);
    $response->getBody()->write($message);
    return $response->withHeader('Content-Type', 'application/json')
        ->withStatus($statusCode);
});

$app->get('/user', function (Request $request, Response $response) {
    $user = User::all();
    $data = json_encode($user);
    $response->getBody()->write($data);
    return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(200);
});

$app->post('/login', function (Request $request, Response $response) use ($settings) {
    $body = $request->getBody();
    $json = json_decode($body, true);
    $validator = new Validator;
    $validator->requirePresence('email', true, 'Email field is required')
        ->notEmptyString('email', 'Email is required')
        ->email('email', false, 'Email must be valid')
        ->requirePresence('password', true, 'Password field is required')
        ->notEmptyString('password', 'Password is required');
    $errors = $validator->validate($json);
    if ($errors) {
        $messages['message'] = 'Login failed';
        foreach($errors as $error) {
            $messages['error'][] = array_values($error);
        }
        $statusCode = 401;
    } else {
        $user = User::where('email', $json['email'])
            ->where('password', sha1($json['password']))
            ->where('status', 1)
            ->first();
        if ($user) {
            $iat = new DateTimeImmutable(); // issued at time
            $exp = $iat->modify('+30 minutes')->getTimestamp(); // expired
            $nbf = $iat->getTimestamp(); // not before
            $payload = [
                'iat' => $iat->getTimestamp(),
                'exp' => $exp,
                'nbf' => $nbf,
                'user_id' => $user->id,
                'email' => $user->email,
            ];
            $message['access_token'] = JWT::encode($payload, $settings['jwt']['key'], $settings['jwt']['alg']);
            $statusCode = 200;
        } else {
            $message['message'] = 'Login failed';
            $statusCode = 401;
        }
    }
    $data = json_encode($message);
    $response->getBody()->write($data);
    return $response
            ->withHeader('Content-Type', 'application-json')
            ->withStatus($statusCode);
});

$app->post('/decode', function (Request $request, Response $response) use ($settings) {
    $body = $request->getBody();
    $json = json_decode($body, true);
    $key = new Key($settings['jwt']['key'], $settings['jwt']['alg']);
    $decode = JWT::decode($json['access_token'], $key);
    $response->getBody()->write(json_encode($decode));
    return $response->withHeader('Content-Type', 'application-json');
});

$app->run();
