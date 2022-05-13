<?php

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Factory\AppFactory;
use Cake\Validation\Validator;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use App\Models\User;
use App\Models\Order;
use App\Models\OrderDetail;

require __DIR__ . '/../vendor/autoload.php';

$app = AppFactory::create();
$app->setBasePath('/fashion-store-slim4/public');

$settings = require __DIR__ . '/../config/settings.php';
require __DIR__ . '/../config/dependencies.php';

$authMiddleware = function (Request $request, RequestHandler $handler) use ($settings) {
    try {
        $message = [];
        if ($request->hasHeader('Authorization')) {
            $header = $request->getHeader('Authorization');
            if (!empty($header)) {
                $bearer = trim($header[0]);
                preg_match('/Bearer\s(\S+)/', $bearer, $matches);
                $token = $matches[1];
                $key = $settings['jwt']['key'];
                $alg = $settings['jwt']['alg'];
                $key = new Key($key, $alg);
                $data = JWT::decode($token, $key);
                $dateTime = new DateTimeImmutable();
                $now = $dateTime->getTimestamp();

                if ($now > $data->nbf && $now < $data->exp) {
                    $request = $request->withAttribute('user_id', $data->user_id);
                    $request = $request->withAttribute('user_email', $data->email);
                } else {
                    $message['message'] = 'Token expired';
                }
            }
        } else {
            $message['message'] = 'Unauthorized access';
            $response = new \Slim\Psr7\Response();
            $response->getBody()->write(json_encode($message));
            return $response->withHeader('Content-Type', 'application-json')
                ->withStatus(401);
        }
    } catch (\Exception $e) {
        $message['message'] = $e->getMessage();
        $response = new \Slim\Psr7\Response();
        $response->getBody()->write(json_encode($message));
        return $response->withHeader('Content-Type', 'application-json')
            ->withStatus(401);
    }

    $response = $handler->handle($request);
    return $response;
};

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

$app->get('/identity', function (Request $request, Response $response) {
    $user = User::where('id', $request->getAttribute('user_id'))
        ->where('email', $request->getAttribute('user_email'))
        ->first();
    $response->getBody()->write(json_encode($user));
    return $response->withHeader('Content-Type', 'application/json')
        ->withStatus(200);
})->add($authMiddleware);

$app->post('/order', function (Request $request, Response $response) use ($capsule) {
    $body = $request->getBody();
    $json = json_decode($body, true);

    $connection = $capsule->getConnection();
    try {
        $connection->beginTransaction();

        $order = new Order();
        $order->user_id = $request->getAttribute('user_id');
        $order->user_address_id = $json['address_id'];
        $order->status = 0;
        $order->save();

        $items = [];
        foreach($json['item'] as $item) {
            $item['order_id'] = $order->id;
            $item['status'] = 0;
            $items[] = $item;
        }
        OrderDetail::insert($items);

        $connection->commit();
        $message['message'] = 'Order successfully';
        $statusCode = 201;
    } catch (\Exception $e) {
        $connection->rollBack();
        $message['message'] = $e->getMessage();
        $statusCode = 400;
    }

    $response->getBody()->write(json_encode($message));
    return $response->withHeader('Content-Type', 'application-json')
        ->withStatus($statusCode);
})->add($authMiddleware);

$app->run();
