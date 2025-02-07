<?php

use Etobi\MensamaxApi\Service\ChatgptService;
use Etobi\MensamaxApi\Service\MensamaxApiService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Factory\AppFactory;
use Symfony\Component\Dotenv\Dotenv;

require __DIR__ . '/../vendor/autoload.php';

$dotenv = new Dotenv();
$dotenv->load(__DIR__ . '/../.env');
$config = [
    'baseurl' => $_ENV['MENSAMAXAPI_baseurl'],
    'project' => $_ENV['MENSAMAXAPI_project'],
    'username' => $_ENV['MENSAMAXAPI_username'],
    'password' => $_ENV['MENSAMAXAPI_password'],
    'openai_apikey' => $_ENV['OPENAI_API_KEY'],
];

$app = AppFactory::create();

$app->get('/', function (Request $request, Response $response, $args) {
    $response->getBody()->write("Hello world!");
    return $response;
});

$app->get('/get', function (Request $request, Response $response, $args) {
    $response->getBody()->write(
        file_get_contents(__DIR__ . '/../storage/fetch.json')
    );
    return $response;
});

$app->get('/summary', function (Request $request, Response $response, $args) use ($config) {
    $chatGptService = new ChatgptService($config['openai_apikey']);
    $response->getBody()->write(
        $chatGptService->getSummary(
            file_get_contents(__DIR__ . '/../storage/fetch.json')
        )
    );
    return $response;
});

$app->post('/fetch', function (Request $request, Response $response, $args) use ($config) {
    $mensamaxApiService = new MensamaxApiService($config);
    $result = $mensamaxApiService->fetch(4);

    $chatGptService = new ChatgptService($config['openai_apikey']);
    $summary = $chatGptService->getSummary(
        json_encode($result)
    );
    $result['summary'] = $summary;

    file_put_contents(__DIR__ . '/../storage/fetch.json', json_encode($result));

    $response->getBody()->write('ok');
    return $response;
});

$app->run();